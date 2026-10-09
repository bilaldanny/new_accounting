<?php

namespace App\Services\Reports;

use App\Models\Warehouse;
use App\Services\StockMovements;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Warehouse Capacity & Usage: each active warehouse with its capacity, the stock it holds on a day (default today) and how
 * full it is. Both are counted in base units of the products (a piece, a kilo, a litre), because the app holds no product
 * volume: the capacity a warehouse is given should be read the same way. Stock is the units held in a warehouse counting
 * only the variations that are in stock there (a variation below zero adds nothing, it does not free space). Stock of
 * documents that name no warehouse is in none of them. A warehouse with no capacity set shows its stock and no
 * percentage. Read-only.
 */
class WarehouseUsageReport
{
    public const SORTABLE = [
        'id' => 'id',
        'warehouse_name' => 'warehouse_name',
        'branch_name' => 'branch_name',
        'capacity' => 'capacity',
        'used' => 'used',
        'usage_percent' => 'usage_percent',
    ];

    public const DEFAULT_SORT = 'warehouse_name';

    public const DEFAULT_DESC = false;

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        $asOf = trim((string) ($filters['end_date'] ?? '')) ?: null;
        $search = trim((string) ($filters['search'] ?? ''));

        $held = DB::query()
            ->fromSub(
                DB::query()->fromSub(StockMovements::query(['branch_id' => $branchId, 'as_of' => $asOf]), 'm')
                    ->whereNotNull('m.warehouse_id')
                    ->groupBy('m.warehouse_id', 'm.variation_id')
                    ->selectRaw('m.warehouse_id, m.variation_id, sum(m.qty) as qty'),
                's',
            )
            ->where('s.qty', '>', 0)
            ->groupBy('s.warehouse_id')
            ->selectRaw('s.warehouse_id, sum(s.qty) as used')
            ->pluck('used', 'warehouse_id');

        return Warehouse::query()
            ->visibleToCurrentUser()
            ->with('branch:id,name')
            ->where('is_active', true)
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->get()
            ->map(function (Warehouse $warehouse) use ($held): array {
                $capacity = $warehouse->capacity === null ? null : round((float) $warehouse->capacity, 3);
                $used = round((float) ($held[$warehouse->id] ?? 0), 3);
                $percent = $capacity !== null && $capacity > 0 ? round($used / $capacity * 100, 2) : null;

                return [
                    'warehouse_id' => $warehouse->id,
                    'warehouse_name' => $warehouse->name,
                    'branch_name' => (string) ($warehouse->branch?->name ?? ''),
                    'capacity' => $capacity,
                    'used' => $used,
                    'free' => $capacity === null ? null : round($capacity - $used, 3),
                    'usage_percent' => $percent,
                    'status' => $capacity === null ? 'No capacity set' : ($used > $capacity ? 'Over capacity' : ($percent >= 90 ? 'Nearly full' : 'Within capacity')),
                ];
            })
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
            'warehouses' => $rows->count(),
            'capacity' => round((float) $rows->sum('capacity'), 3),
            'used' => round((float) $rows->sum('used'), 3),
            'over_capacity' => $rows->where('status', 'Over capacity')->count(),
        ];
    }
}
