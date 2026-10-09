<?php

namespace App\Services\Reports;

use App\Models\CashCollection;
use Illuminate\Support\Collection;

/**
 * Cash Collection Report: the collections made in the range (default the last 30 days; the day the cash was
 * collected), with who collected them, their status and what was kept as advance.
 */
class CashCollectionReport
{
    public const SORTABLE = [
        'collected_on' => 'collected_on',
        'reference' => 'reference',
        'contact_name' => 'contact_name',
        'amount' => 'amount',
        'advance_amount' => 'advance_amount',
        'status' => 'status',
        'collector' => 'collector',
    ];

    public const DEFAULT_SORT = 'collected_on';

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        [$from, $to] = ReportDates::range($filters);
        $search = trim((string) ($filters['search'] ?? ''));
        $status = (string) ($filters['status'] ?? '');

        return CashCollection::query()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->when(in_array($status, [CashCollection::STATUS_PENDING, CashCollection::STATUS_COMPLETED, CashCollection::STATUS_CANCELLED], true), fn ($q) => $q->where('status', $status))
            ->whereDate('collected_on', '>=', $from)
            ->whereDate('collected_on', '<=', $to)
            ->with(['contact:id,business_name,first_name,last_name', 'collector:id,first_name,last_name', 'branch:id,name'])
            ->orderBy('collected_on')
            ->get()
            ->map(fn (CashCollection $collection): array => [
                'id' => $collection->id,
                'collected_on' => $collection->collected_on?->toDateString(),
                'reference' => $collection->reference,
                'contact_name' => $collection->contactName(),
                'branch_name' => $collection->branch?->name,
                'amount' => round((float) $collection->amount, 2),
                'advance_amount' => round((float) $collection->advance_amount, 2),
                'status' => $collection->status,
                'collector' => $collection->collector?->full_name,
                'completed_at' => $collection->completed_at?->format('Y-m-d H:i'),
            ])
            ->when($search !== '', fn (Collection $rows) => $rows->filter(fn (array $row): bool => str_contains(strtolower($row['reference'].' '.$row['contact_name'].' '.$row['collector']), strtolower($search))))
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        $by = fn (string $status): float => round((float) $rows->where('status', $status)->sum('amount'), 2);

        return [
            'count' => $rows->count(),
            'total' => round((float) $rows->sum('amount'), 2),
            'completed' => $by(CashCollection::STATUS_COMPLETED),
            'pending' => $by(CashCollection::STATUS_PENDING),
            'cancelled' => $by(CashCollection::STATUS_CANCELLED),
            'advance' => round((float) $rows->sum('advance_amount'), 2),
        ];
    }
}
