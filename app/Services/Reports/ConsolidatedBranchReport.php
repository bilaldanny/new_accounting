<?php

namespace App\Services\Reports;

use App\Models\Branch;
use Illuminate\Support\Collection;

/**
 * Consolidated Multi-Branch Report: the Purchase & Sale Summary of each branch of the company side by side, with
 * the company total (the sum of the branches). One company only; a branch user sees only their own branch.
 */
class ConsolidatedBranchReport
{
    public const SORTABLE = [
        'branch_name' => 'branch_name',
        'net_sales' => 'net_sales',
        'net_purchases' => 'net_purchases',
        'sales_due' => 'sales_due',
        'purchase_due' => 'purchase_due',
        'result' => 'result',
    ];

    public const DEFAULT_SORT = 'net_sales';

    public function __construct(private readonly PurchaseSaleSummary $summary) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        if ($companyId === null) {
            return collect();
        }

        [$from, $to] = ReportDates::range($filters);

        return Branch::query()
            ->where('company_id', $companyId)
            ->when($branchId !== null, fn ($q) => $q->where('id', $branchId))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function (Branch $branch) use ($companyId, $from, $to): array {
                $totals = $this->summary->build($companyId, $branch->id, ['start_date' => $from, 'end_date' => $to])['totals'];

                return [
                    'id' => $branch->id,
                    'branch_name' => $branch->name,
                    'net_sales' => round((float) $totals['net_sales'], 2),
                    'net_purchases' => round((float) $totals['net_purchases'], 2),
                    'sales_due' => round((float) $totals['sales_due'], 2),
                    'purchase_due' => round((float) $totals['purchase_due'], 2),
                    'result' => round((float) $totals['net_sales'] - (float) $totals['net_purchases'], 2),
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        return [
            'branches' => $rows->count(),
            'net_sales' => round((float) $rows->sum('net_sales'), 2),
            'net_purchases' => round((float) $rows->sum('net_purchases'), 2),
            'sales_due' => round((float) $rows->sum('sales_due'), 2),
            'purchase_due' => round((float) $rows->sum('purchase_due'), 2),
            'result' => round((float) $rows->sum('result'), 2),
        ];
    }
}
