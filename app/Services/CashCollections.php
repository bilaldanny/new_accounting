<?php

namespace App\Services;

use App\Models\CashCollection;
use App\Models\CashCollectionAllocation;
use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Completing and cancelling cash collections.
 *
 * `complete()` is the only step that posts anything: in one transaction, on a row-locked collection, it
 * spreads the collected amount over the customer's open invoices as ordinary sell payments (cash method,
 * dated the day the cash was collected) through Payment::createSellPayment, so each one gets its ledger
 * voucher and updates its invoice's payment status exactly like a payment made on the Sell Payment screen.
 * If any invoice is refused (not the customer's, not posted yet, more than it still owes) nothing is
 * saved and the collection stays pending.
 *
 * The allocations have to add up to exactly the collected amount: there is no advance / unallocated
 * balance, so no collected money can end up unposted.
 *
 * A refused step throws a RuntimeException whose message is the reason: `not_pending`,
 * `allocation_mismatch`.
 */
class CashCollections
{
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
     *
     * @throws ValidationException when an invoice cannot take its part
     */
    public function complete(CashCollection $collection, int $paymentAccountId, array $allocations, ?int $userId = null): CashCollection
    {
        return DB::transaction(function () use ($collection, $paymentAccountId, $allocations, $userId): CashCollection {
            $locked = CashCollection::query()->lockForUpdate()->findOrFail($collection->id);

            if (! $locked->isPending()) {
                throw new RuntimeException('not_pending');
            }

            $total = round(array_sum(array_map(fn (array $row): float => round((float) $row['amount'], 2), $allocations)), 2);

            if ($total !== round((float) $locked->amount, 2)) {
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
                    'amount' => $amount,
                ]);
            }

            $locked->update([
                'status' => CashCollection::STATUS_COMPLETED,
                'payment_account' => $paymentAccountId,
                'completed_by' => $userId,
                'completed_at' => now(),
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
