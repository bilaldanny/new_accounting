<?php

namespace App\Services;

use App\Models\CashCollection;
use App\Models\Contact;
use App\Models\Payment;
use App\Models\SupplierPaymentAllocation;
use App\Models\SupplierPaymentBatch;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Matches the advances a party has paid (customer: the advance a cash collection kept; supplier: the advance a supplier payment
 * kept) to that party's open invoices or bills. `suggest` proposes the pairs, in the order the strategy names: `fifo` (oldest document
 * first), `due_date` (earliest due first) or `smallest` (smallest balance first, so the most documents are cleared). `apply` settles
 * the pairs it is given through the same calls the advance screens use, so nothing here posts anything of its own: an advance
 * settles a document without moving cash, the party's account already holds it.
 */
class AllocationEngine
{
    public const STRATEGIES = ['fifo', 'due_date', 'smallest'];

    public function __construct(private readonly CashCollections $collections) {}

    /**
     * @return array{advances: list<array<string, mixed>>, documents: list<array<string, mixed>>, suggestion: list<array<string, mixed>>, unallocated: float}
     */
    public function suggest(int $companyId, int $contactId, string $role, string $strategy): array
    {
        $advances = $this->advances($companyId, $contactId, $role);
        $documents = $this->documents($companyId, $contactId, $role, $strategy);

        $pool = $advances->map(fn (array $advance): array => ['id' => $advance['id'], 'left' => $advance['remaining']])->values()->all();
        $suggestion = [];

        foreach ($documents as $document) {
            $need = $document['remaining'];

            foreach ($pool as &$source) {
                if ($need <= 0.000001) {
                    break;
                }

                if ($source['left'] <= 0.000001) {
                    continue;
                }

                $take = round(min($need, $source['left']), 2);
                $suggestion[] = ['source_id' => $source['id'], 'transaction_id' => $document['id'], 'invoice_no' => $document['invoice_no'], 'amount' => $take];
                $source['left'] = round($source['left'] - $take, 2);
                $need = round($need - $take, 2);
            }

            unset($source);
        }

        return [
            'advances' => $advances->values()->all(),
            'documents' => $documents->values()->all(),
            'suggestion' => $suggestion,
            'unallocated' => round((float) array_sum(array_column($pool, 'left')), 2),
        ];
    }

    /**
     * @param  list<array{source_id: int, transaction_id: int, amount: float|int|string}>  $allocations
     * @return int the settlements made
     *
     * @throws ValidationException
     */
    public function apply(int $companyId, int $contactId, string $role, array $allocations, string $paidOn): int
    {
        return DB::transaction(function () use ($companyId, $contactId, $role, $allocations, $paidOn): int {
            $bySource = collect($allocations)->groupBy(fn (array $row): int => (int) $row['source_id']);

            foreach ($bySource as $sourceId => $rows) {
                $rows = $rows->map(fn (array $row): array => ['transaction_id' => (int) $row['transaction_id'], 'amount' => round((float) $row['amount'], 2)])->all();

                if ($role === 'customer') {
                    $collection = CashCollection::query()->where('company_id', $companyId)->where('contact_id', $contactId)
                        ->where('status', CashCollection::STATUS_COMPLETED)->find($sourceId);

                    if ($collection === null) {
                        throw ValidationException::withMessages(['allocations' => ['An advance is not one of this customer\'s.']]);
                    }

                    try {
                        $this->collections->applyAdvance($collection, $rows);
                    } catch (RuntimeException $e) {
                        throw ValidationException::withMessages(['allocations' => [$e->getMessage() === 'advance_exceeded' ? 'The allocations take more than is left of an advance.' : 'An advance cannot be used.']]);
                    }

                    continue;
                }

                $this->applySupplier($companyId, $contactId, (int) $sourceId, $rows, $paidOn);
            }

            return count($allocations);
        });
    }

    /**
     * @param  list<array{transaction_id: int, amount: float}>  $rows
     */
    private function applySupplier(int $companyId, int $contactId, int $batchId, array $rows, string $paidOn): void
    {
        $batch = SupplierPaymentBatch::query()->where('company_id', $companyId)->where('contact_id', $contactId)->where('status', 'completed')->find($batchId);

        if ($batch === null) {
            throw ValidationException::withMessages(['allocations' => ['An advance is not one of this supplier\'s.']]);
        }

        $total = round(array_sum(array_column($rows, 'amount')), 2);

        if ($total > $batch->advanceRemaining() + 0.000001) {
            throw ValidationException::withMessages(['allocations' => ['The allocations take more than is left of an advance ('.$batch->advanceRemaining().').']]);
        }

        foreach ($rows as $row) {
            $bill = Transaction::query()->where('company_id', $companyId)->where('type', Transaction::TYPE_PURCHASE)->where('contact_id', $contactId)
                ->where('status', '!=', 'draft')->find($row['transaction_id']);

            if ($bill === null) {
                throw ValidationException::withMessages(['allocations' => ['A bill is not one of this supplier\'s open bills.']]);
            }

            $payment = Payment::createPurchasePaymentFromAdvance($bill, $row['amount'], $paidOn, 'Advance '.$batch->reference);
            SupplierPaymentAllocation::query()->create([
                'supplier_payment_batch_id' => $batch->id, 'transaction_id' => $bill->id, 'payment_id' => $payment->id,
                'amount' => $row['amount'], 'kind' => SupplierPaymentAllocation::KIND_ADVANCE,
            ]);
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function advances(int $companyId, int $contactId, string $role): Collection
    {
        if ($role === 'customer') {
            return CashCollection::query()->where('company_id', $companyId)->where('contact_id', $contactId)
                ->where('status', CashCollection::STATUS_COMPLETED)->where('advance_amount', '>', 0)->orderBy('collected_on')->orderBy('id')->get()
                ->map(fn (CashCollection $collection): array => [
                    'id' => $collection->id, 'reference' => $collection->reference, 'date' => $collection->collected_on?->toDateString(),
                    'amount' => (float) $collection->advance_amount, 'remaining' => $collection->advanceRemaining(),
                ])->filter(fn (array $advance): bool => $advance['remaining'] > 0)->values();
        }

        return SupplierPaymentBatch::query()->where('company_id', $companyId)->where('contact_id', $contactId)
            ->where('status', 'completed')->where('advance_amount', '>', 0)->orderBy('id')->get()
            ->map(fn (SupplierPaymentBatch $batch): array => [
                'id' => $batch->id, 'reference' => $batch->reference, 'date' => $batch->created_at?->toDateString(),
                'amount' => (float) $batch->advance_amount, 'remaining' => $batch->advanceRemaining(),
            ])->filter(fn (array $advance): bool => $advance['remaining'] > 0)->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function documents(int $companyId, int $contactId, string $role, string $strategy): Collection
    {
        $type = $role === 'customer' ? Transaction::TYPE_SELL : Transaction::TYPE_PURCHASE;
        $contact = Contact::query()->find($contactId);

        $rows = Transaction::query()->where('company_id', $companyId)->where('contact_id', $contactId)->where('type', $type)
            ->when($role === 'customer', fn ($q) => $q->whereNotIn('status', Transaction::UNPOSTED_SELL_STATUSES), fn ($q) => $q->whereNotIn('status', ['draft', 'rejected', 'cancelled']))
            ->where('payment_status', '!=', 'paid')->orderBy('transaction_date')->orderBy('id')->get()
            ->map(function (Transaction $document) use ($contact): array {
                $days = (int) ($document->pay_term ?? $contact?->pay_term ?? 0);
                $unit = (string) ($document->pay_type ?? $contact?->pay_type ?? 'day');
                $date = $document->transaction_date->copy();
                $due = match ($unit) {
                    'month' => $date->addMonths($days),
                    'year' => $date->addYears($days),
                    default => $date->addDays($days),
                };

                return [
                    'id' => $document->id, 'invoice_no' => $document->invoice_no, 'date' => $document->transaction_date->toDateString(),
                    'due_date' => $due->toDateString(), 'total' => round((float) $document->final_amount, 2), 'remaining' => Payment::remainingAmountForTransaction($document),
                ];
            })->filter(fn (array $row): bool => $row['remaining'] > 0)->values();

        return match ($strategy) {
            'due_date' => $rows->sortBy([['due_date', 'asc'], ['id', 'asc']])->values(),
            'smallest' => $rows->sortBy([['remaining', 'asc'], ['id', 'asc']])->values(),
            default => $rows,
        };
    }
}
