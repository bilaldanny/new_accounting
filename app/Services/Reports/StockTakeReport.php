<?php

namespace App\Services\Reports;

use App\Services\Valuation\CostSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stock take variance: for every line of the stock takes counted in the range, the system quantity frozen when the take was opened,
 * the counted quantity, the variance (counted less system) and its value at the company's unit cost on the count date (the costing
 * method in force, CostSource). A line that was not counted has no variance and is left out of the totals; a variation with no
 * cost is valued at nothing and counted in `uncosted`. Quantities are base units. Read only: the adjustment a completed take made is
 * a stock adjustment document of its own.
 */
class StockTakeReport
{
    public const SORTABLE = [
        'count_date' => 'count_date',
        'reference' => 'reference',
        'product_name' => 'product_name',
        'system_qty' => 'system_qty',
        'counted_qty' => 'counted_qty',
        'variance_qty' => 'variance_qty',
        'unit_cost' => 'unit_cost',
        'variance_value' => 'variance_value',
    ];

    public const DEFAULT_SORT = 'count_date';

    public function __construct(private readonly CostSource $costs) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        [$from, $to] = ReportDates::range($filters, 365);
        $status = trim((string) ($filters['status'] ?? ''));
        $search = trim((string) ($filters['search'] ?? ''));

        $lines = DB::table('stock_take_lines as l')
            ->join('stock_takes as s', 's.id', '=', 'l.stock_take_id')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('product_details as v', 'v.id', '=', 'l.variation_id')
            ->leftJoin('branches as b', 'b.id', '=', 's.branch_id')
            ->whereNull('s.deleted_at')
            ->when($companyId !== null, fn ($q) => $q->where('s.company_id', $companyId))
            ->when($branchId !== null, fn ($q) => $q->where('s.branch_id', $branchId))
            ->when($status !== '' && $status !== 'all', fn ($q) => $q->where('s.status', $status))
            ->whereDate('s.count_date', '>=', $from)->whereDate('s.count_date', '<=', $to)
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('p.name', 'like', "%{$search}%")->orWhere('s.reference', 'like', "%{$search}%")))
            ->get(['l.id', 'l.system_qty', 'l.counted_qty', 'l.variation_id', 's.company_id', 's.reference', 's.count_date', 's.status', 'p.name as product_name', 'p.sku', 'v.variation_name', 'b.name as branch_name']);

        $unitCosts = [];

        return $lines->map(function (object $line) use (&$unitCosts): array {
            $date = substr((string) $line->count_date, 0, 10);
            $key = $line->company_id.'|'.$date;
            $unitCosts[$key] ??= $this->costs->stockCosts((int) $line->company_id, $date);
            $cost = $unitCosts[$key][(int) $line->variation_id] ?? null;
            $counted = $line->counted_qty === null ? null : round((float) $line->counted_qty, 4);
            $system = round((float) $line->system_qty, 4);
            $variance = $counted === null ? null : round($counted - $system, 4);

            return [
                'id' => (int) $line->id, 'count_date' => $date, 'reference' => (string) $line->reference, 'status' => (string) $line->status,
                'branch_name' => (string) ($line->branch_name ?? ''), 'product_name' => (string) $line->product_name,
                'variation_name' => (string) ($line->variation_name ?? ''), 'sku' => (string) ($line->sku ?? ''),
                'system_qty' => $system, 'counted_qty' => $counted, 'variance_qty' => $variance,
                'unit_cost' => $cost === null ? null : round((float) $cost, 4),
                'variance_value' => $variance === null || $cost === null ? null : round($variance * $cost, 2),
            ];
        })->sortByDesc('count_date')->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        $counted = $rows->whereNotNull('counted_qty');
        $variances = $counted->filter(fn (array $row): bool => $row['variance_qty'] != 0.0);

        return [
            'lines' => $rows->count(),
            'counted' => $counted->count(),
            'with_variance' => $variances->count(),
            'shortage_value' => round(abs((float) $variances->where('variance_value', '<', 0)->sum('variance_value')), 2),
            'excess_value' => round((float) $variances->where('variance_value', '>', 0)->sum('variance_value'), 2),
            'net_value' => round((float) $counted->sum('variance_value'), 2),
            'uncosted' => $variances->whereNull('unit_cost')->count(),
        ];
    }
}
