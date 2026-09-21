<?php

namespace App\Services;

use App\Models\CashCollection;
use App\Models\CashCollectionAllocation;
use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Completing, cancelling, reversing and using the advance of cash collections.
 *
 * `complete()` is the step that posts: in one transaction, on a row-locked collection, it spreads the
 * collected amount over the customer's open invoices as ordinary sell payments (cash method, dated the day
 * the cash was collected) through Payment::createSellPayment, so each one gets its ledger voucher and updates
 * its invoice's payment status exactly like a payment made on the Sell Payment screen. If any invoice is
 * refused (not the customer's, not posted yet, more than it still owes) nothing is saved and the collection
 * stays pending.
 *
 * The allocations may never add up to more than the collected amount. When they add up to less, the rest is
 * only accepted when the caller says to keep it (`$keepAdvance`): it is then posted as a customer advance
 * (Payment::createSellAdvance: cash / bank debit, the customer's account credit, no invoice), so no collected
 * money is ever left unposted and a forgotten invoice is never turned into an advance by accident.
 *
 * `applyAdvance()` later settles invoices out of that advance (Payment::createSellPaymentFromAdvance: an
 * invoice payment with no cash and no journal, since the customer's account already holds the credit).
 *
 * `reverse()` undoes a completed collection: it removes every payment the collection made (the invoice
 * payments, the advance and the invoices settled from it, with their vouchers), puts the invoices' payment
 * status right and returns the collection to pending, so it can be corrected and completed again.
 *
 * A refused step throws a RuntimeException whose message is the reason: `not_pending`, `not_completed`,
 * `allocation_mismatch`, `advance_exceeded`.
 */
class CashCollections
{
    /**
     * Whether a company has switched cash collection on (Company Settings). A company that never saved its
     * settings has it off.
     */
    public function enabledFor(int $companyId): bool
    {
        return (bool) CompanySetting::query()->where('company_id', $companyId)->value('cash_collection');
    }

    /**
     * The posted sales of a customer that still owe something, oldest first, each with what it owes.
     *
     * @return Collection<int, array{id: int, invoice_no: string|null, transaction_date: string|null, final_amount: float, paid_amount: float, remaining: float}>
     */
    public function openInvoices(int $companyId, int $contactId): Collection
    {
        return Transaction::query()
            ->sells()
            ->visibleToCurrentUser()
            ->where('company_id', $companyId)
            ->where('contact_id', $contactId)
            ->whereNotIn('status', Transaction::UNPOSTED_SELL_STATUSES)
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get()
            ->map(fn (Transaction $sell): array => [
                'id' => (int) $sell->id,
                'invoice_no' => $sell->invoice_no,
                'transaction_date' => $sell->transaction_date?->toDateString(),
                'final_amount' => round((float) $sell->final_amount, 2),
                'paid_amount' => Payment::paidAmountForTransaction((int) $sell->id),
                'remaining' => Payment::remainingAmountForTransaction($sell),
            ])
            ->filter(fn (array $invoice): bool => $invoice['remaining'] > 0)
            ->values();
    }

    /**
     * @param  list<array{transaction_id: int|string, amount: int|float|string}>  $allocations
     * @param  bool  $keepAdvance  keep what the allocations do not cover as a customer advance
     *
     * @throws ValidationException when an invoice cannot take its part
     */
    public function complete(CashCollection $collection, int $paymentAccountId, array $allocations, ?int $userId = null, bool $keepAdvance = false): CashCollection
    {
        return DB::transaction(function () use ($collection, $paymentAccountId, $allocations, $userId, $keepAdvance): CashCollection {
            $locked = CashCollection::query()->lockForUpdate()->findOrFail($collection->id);

            if (! $locked->isPending()) {
                throw new RuntimeException('not_pending');
            }

            $total = round(array_sum(array_map(fn (array $row): float => round((float) $row['amount'], 2), $allocations)), 2);
            $advance = round((float) $locked->amount - $total, 2);

            if ($advance < 0 || ($advance > 0 && ! $keepAdvance)) {
                throw new RuntimeException('allocation_mismatch');
            }

            foreach ($allocations as $index => $row) {
                $invoice = $this->collectableInvoice($locked, (int) $row['transaction_id'], $index);
                $amount = round((float) $row['amount'], 2);

                $payment = Payment::createSellPayment(new Request([
                    'transaction_id' => $invoice->id,
                    'amount' => $amount,
                    'paid_on' => $locked->collected_on->toDateString(),
                    'method' => 'cash',
                    'payment_account' => $paymentAccountId,
                    'note' => 'Cash collection '.$locked->reference,
                ]));

                CashCollectionAllocation::query()->create([
                    'cash_collection_id' => $locked->id,
                    'transaction_id' => $invoice->id,
                    'payment_id' => $payment->id,
                    'kind' => CashCollectionAllocation::KIND_COLLECTED,
                    'amount' => $amount,
                ]);
            }

            $advancePayment = $advance > 0
                ? Payment::createSellAdvance(
                    Contact::query()->findOrFail($locked->contact_id),
                    (int) $locked->company_id,
                    (int) $locked->branch_id,
                    $advance,
                    $locked->collected_on->toDateString(),
                    $paymentAccountId,
                    'Cash collection '.$locked->reference.' (advance)',
                )
                : null;

            $locked->update([
                'status' => CashCollection::STATUS_COMPLETED,
                'payment_account' => $paymentAccountId,
                'advance_amount' => $advance,
                'advance_payment_id' => $advancePayment?->id,
                'completed_by' => $userId,
                'completed_at' => now(),
            ]);

            return $locked;
        });
    }

    /**
     * Settles invoices of the customer out of the advance a completed collection kept. The parts may not add up
     * to more than what is left of the advance.
     *
     * @param  list<array{transaction_id: int|string, amount: int|float|string}>  $allocations
     *
     * @throws ValidationException when an invoice cannot take its part
     */
    public function applyAdvance(CashCollection $collection, array $allocations): CashCollection
    {
        return DB::transaction(function () use ($collection, $allocations): CashCollection {
            $locked = CashCollection::query()->lockForUpdate()->findOrFail($collection->id);

            if ($locked->status !== CashCollection::STATUS_COMPLETED) {
                throw new RuntimeException('not_completed');
            }

            $total = round(array_sum(array_map(fn (array $row): float => round((float) $row['amount'], 2), $allocations)), 2);

            if ($total <= 0 || $total > $locked->advanceRemaining()) {
                throw new RuntimeException('advance_exceeded');
            }

            foreach ($allocations as $index => $row) {
                $invoice = $this->collectableInvoice($locked, (int) $row['transaction_id'], $index);
                $amount = round((float) $row['amount'], 2);

                $payment = Payment::createSellPaymentFromAdvance(
                    $invoice,
                    $amount,
                    now()->toDateString(),
                    'Advance from cash collection '.$locked->reference,
                );

                CashCollectionAllocation::query()->create([
                    'cash_collection_id' => $locked->id,
                    'transaction_id' => $invoice->id,
                    'payment_id' => $payment->id,
                    'kind' => CashCollectionAllocation::KIND_ADVANCE,
                    'amount' => $amount,
                ]);
            }

            return $locked;
        });
    }

    /**
     * Undoes a completed collection: every payment it made is removed and it goes back to pending. Payments
     * that were already deleted by hand (on the Sell Payment screen) are simply skipped.
     *
     * @throws RuntimeException `not_completed`
     */
    public function reverse(CashCollection $collection, ?int $userId = null, ?string $note = null): CashCollection
    {
        return DB::transaction(function () use ($collection, $userId, $note): CashCollection {
            $locked = CashCollection::query()->lockForUpdate()->findOrFail($collection->id);

            if ($locked->status !== CashCollection::STATUS_COMPLETED) {
                throw new RuntimeException('not_completed');
            }

            $allocations = $locked->allocations()->get();

            // the invoices settled out of the advance first, then the cash payments and the advance itself
            foreach ($allocations->sortByDesc(fn (CashCollectionAllocation $allocation): int => $allocation->kind === CashCollectionAllocation::KIND_ADVANCE ? 1 : 0) as $allocation) {
                $payment = $allocation->payment_id === null ? null : Payment::query()->find($allocation->payment_id);

                if ($payment !== null) {
                    Payment::removeCollectedPayment($payment);
                }
            }

            $advance = $locked->advance_payment_id === null ? null : Payment::query()->find($locked->advance_payment_id);

            if ($advance !== null) {
                Payment::removeCollectedPayment($advance);
            }

            $locked->allocations()->delete();

            $trimmed = $note === null ? '' : trim($note);
            $locked->update([
                'status' => CashCollection::STATUS_PENDING,
                'payment_account' => null,
                'advance_amount' => 0,
                'advance_payment_id' => null,
                'completed_by' => null,
                'completed_at' => null,
                'reversed_at' => now(),
                'reversed_by' => $userId,
                'note' => $trimmed === '' ? $locked->note : trim(($locked->note ? $locked->note."\n" : '').'Reversed '.now()->toDateString().': '.$trimmed),
            ]);

            return $locked;
        });
    }

    /**
     * Drops a pending collection that will not be banked; it never had any accounting effect.
     */
    public function cancel(CashCollection $collection): CashCollection
    {
        return DB::transaction(function () use ($collection): CashCollection {
            $locked = CashCollection::query()->lockForUpdate()->findOrFail($collection->id);

            if (! $locked->isPending()) {
                throw new RuntimeException('not_pending');
            }

            $locked->update(['status' => CashCollection::STATUS_CANCELLED, 'cancelled_at' => now()]);

            return $locked;
        });
    }

    /**
     * The posted sale of the collection's customer, or a validation error on that allocation row.
     */
    private function collectableInvoice(CashCollection $collection, int $transactionId, int $index): Transaction
    {
        $invoice = Transaction::query()
            ->sells()
            ->visibleToCurrentUser()
            ->where('company_id', $collection->company_id)
            ->where('contact_id', $collection->contact_id)
            ->whereNotIn('status', Transaction::UNPOSTED_SELL_STATUSES)
            ->find($transactionId);

        if ($invoice === null) {
            throw ValidationException::withMessages([
                "allocations.{$index}.transaction_id" => ['This is not an open invoice of the customer.'],
            ]);
        }

        return $invoice;
    }
}
