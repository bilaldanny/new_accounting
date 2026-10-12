<?php

namespace App\Services\Reports;

use App\Models\CashCollection;
use App\Models\SupplierPaymentBatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Advances held: the part of a completed customer collection (cash collection) or supplier payment that was kept as an advance
 * instead of settling an invoice, with how much of it has been used on invoices and bills, how much was refunded, and what remains.
 * A customer advance is owed back to the customer as goods or money; a supplier advance is owed to us by the supplier.
 * `contact_type` narrows to customers or suppliers; the date is the day the advance was taken.
 */
class AdvanceReport
{
    public const SORTABLE = [
        'taken_on' => 'taken_on',
        'party_type' => 'party_type',
        'contact_name' => 'contact_name',
        'reference' => 'reference',
        'amount' => 'amount',
        'used' => 'used',
        'remaining' => 'remaining',
    ];

    public const DEFAULT_SORT = 'taken_on';

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        [$from, $to] = ReportDates::range($filters, 3650);
        $type = (string) ($filters['contact_type'] ?? 'all');
        $search = trim((string) ($filters['search'] ?? ''));
        $rows = collect();

        if ($type !== 'supplier') {
            CashCollection::query()->with('contact:id,business_name,first_name,last_name')
                ->where('status', CashCollection::STATUS_COMPLETED)->where('advance_amount', '>', 0)
                ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
                ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
                ->whereDate('collected_on', '>=', $from)->whereDate('collected_on', '<=', $to)
                ->get()
                ->each(function (CashCollection $collection) use ($rows): void {
                    $rows->push($this->row('Customer', (int) $collection->id, (string) $collection->collected_on, $collection->contact, (string) $collection->reference, (float) $collection->advance_amount, $collection->advanceRemaining()));
                });
        }

        if ($type !== 'customer') {
            $batches = SupplierPaymentBatch::query()
                ->where('status', 'completed')->where('advance_amount', '>', 0)
                ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
                ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
                ->whereDate('paid_on', '>=', $from)->whereDate('paid_on', '<=', $to)
                ->get();
            $suppliers = DB::table('contacts')->whereIn('id', $batches->pluck('contact_id')->unique()->all())->get(['id', 'business_name', 'first_name', 'last_name'])->keyBy('id');

            foreach ($batches as $batch) {
                $rows->push($this->row('Supplier', 1000000000 + (int) $batch->id, (string) $batch->paid_on, $suppliers[$batch->contact_id] ?? null, (string) $batch->reference, (float) $batch->advance_amount, $batch->advanceRemaining()));
            }
        }

        return $rows
            ->when($search !== '', fn (Collection $all): Collection => $all->filter(fn (array $row): bool => stripos($row['contact_name'].' '.$row['reference'], $search) !== false))
            ->sortByDesc('taken_on')->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        $open = fn (string $type): float => round((float) $rows->where('party_type', $type)->sum('remaining'), 2);

        return [
            'count' => $rows->count(),
            'customer_advances' => $open('Customer'),
            'supplier_advances' => $open('Supplier'),
            'taken' => round((float) $rows->sum('amount'), 2),
            'used' => round((float) $rows->sum('used'), 2),
            'remaining' => round((float) $rows->sum('remaining'), 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $type, int $id, string $date, mixed $contact, string $reference, float $amount, float $remaining): array
    {
        $name = $contact === null ? '' : trim((string) ($contact->business_name ?: $contact->first_name.' '.$contact->last_name));

        return [
            'id' => $id, 'party_type' => $type, 'taken_on' => substr($date, 0, 10), 'contact_name' => $name, 'reference' => $reference,
            'amount' => round($amount, 2), 'used' => round($amount - $remaining, 2), 'remaining' => round($remaining, 2),
        ];
    }
}
