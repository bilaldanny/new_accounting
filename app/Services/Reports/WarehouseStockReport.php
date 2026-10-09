<?php

namespace App\Services\Reports;

use App\Services\StockMovements;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Multi-Warehouse Stock View: the stock of each variation in each warehouse of each branch, on a day (default today),
 * in base units. It is read straight from the one definition of stock (StockMovements) and only adds the warehouse the
 * movement's document names. A movement whose document names no warehouse is shown under "Unassigned", so for every
 * variation the warehouse rows of a branch always add up to the branch's stock (`branch_total`, the figure every other
 * stock report shows; with one warehouse chosen it is the stock of that warehouse alone, and `branch_total` is then that warehouse's too). Read-only: it changes nothing and no guard reads it.
 */
class WarehouseStockReport
{
    public const UNASSIGNED = 'Unassigned';

    public const SORTABLE = [
        'id' => 'id',
        'branch_name' => 'branch_name',
        'warehouse_name' => 'warehouse_name',
        'product_name' => 'product_name',
        'on_hand' => 'on_hand',
        'branch_total' => 'branch_total',
    ];

    public const DEFAULT_SORT = 'branch_name';

    public const DEFAULT_DESC = false;

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        $asOf = trim((string) ($filters['end_date'] ?? '')) ?: null;
        $show = (string) ($filters['status'] ?? 'all');
        $productId = $filters['product_id'] ?? null;
        $search = trim((string) ($filters['search'] ?? ''));
        $warehouseId = isset($filters['warehouse_id']) && is_numeric($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;

        $query = DB::query()
            ->fromSub(StockMovements::query(['branch_id' => $branchId, 'as_of' => $asOf, 'warehouse_id' => $warehouseId]), 'm')
            ->join('products as p', 'p.id', '=', 'm.product_id')
            ->leftJoin('product_details as d', 'd.id', '=', 'm.variation_id')
            ->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
            ->leftJoin('branches as b', 'b.id', '=', 'm.branch_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->whereNull('p.deleted_at')
            ->when($companyId !== null, fn (Builder $q) => $q->where('p.company_id', $companyId))
            ->when(! empty($productId), fn (Builder $q) => $q->where('p.id', $productId))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $sub) => $sub->where('p.name', 'like', "%{$search}%")->orWhere('d.sku', 'like', "%{$search}%")))
            ->groupBy('m.branch_id', 'b.name', 'm.warehouse_id', 'w.name', 'm.product_id', 'm.variation_id', 'p.name', 'd.sku', 'd.variation_name', 'u.short_name')
            ->select(['m.branch_id', 'b.name as branch_name', 'm.warehouse_id', 'w.name as warehouse_name', 'm.product_id', 'm.variation_id', 'p.name as product_name', 'd.sku', 'd.variation_name', 'u.short_name as unit_name'])
            ->selectRaw('coalesce(sum(m.qty), 0) as on_hand');

        $rows = $query->get()->map(fn (object $row): array => [
            'branch_id' => (int) $row->branch_id,
            'branch_name' => (string) $row->branch_name,
            'warehouse_id' => $row->warehouse_id === null ? null : (int) $row->warehouse_id,
            'warehouse_name' => $row->warehouse_id === null ? self::UNASSIGNED : (string) $row->warehouse_name,
            'product_id' => (int) $row->product_id,
            'variation_id' => (int) $row->variation_id,
            'product_name' => (string) $row->product_name,
            'sku' => $row->sku,
            'variation_name' => $row->variation_name,
            'unit_name' => $row->unit_name,
            'on_hand' => round((float) $row->on_hand, 6),
        ]);

        // The branch's stock of each variation, which the warehouse rows (assigned and unassigned) add up to.
        $totals = $rows->groupBy(fn (array $row): string => $row['branch_id'].'|'.$row['variation_id'])->map(fn (Collection $group): float => round((float) $group->sum('on_hand'), 6));

        return $rows
            ->reject(fn (array $row): bool => $row['on_hand'] == 0.0)
            ->when($show === 'unassigned', fn (Collection $rows) => $rows->whereNull('warehouse_id'))
            ->when($show === 'assigned', fn (Collection $rows) => $rows->whereNotNull('warehouse_id'))
            ->map(fn (array $row): array => $row + ['branch_total' => $totals[$row['branch_id'].'|'.$row['variation_id']]])
            ->sortBy([['branch_name', 'asc'], ['warehouse_name', 'asc'], ['product_name', 'asc']])
            ->values()
            ->map(fn (array $row, int $index): array => ['id' => $index + 1] + $row);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        return [
            'rows' => $rows->count(),
            'warehouses' => $rows->whereNotNull('warehouse_id')->pluck('warehouse_id')->unique()->count(),
            'assigned_qty' => round((float) $rows->whereNotNull('warehouse_id')->sum('on_hand'), 4),
            'unassigned_qty' => round((float) $rows->whereNull('warehouse_id')->sum('on_hand'), 4),
            'total_qty' => round((float) $rows->sum('on_hand'), 4),
        ];
    }
}
