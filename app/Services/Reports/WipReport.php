<?php

namespace App\Services\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Work in progress today: for every work order not cancelled that still holds cost, what it holds (materials + labour + overhead
 * less what receipts absorbed and what was taken out as loss). With manufacturing posting on, this total is the WIP account balance.
 */
class WipReport
{
    public const SORTABLE = ['wo_no' => 'wo_no', 'product' => 'product', 'status' => 'status', 'wip_value' => 'wip_value', 'material_cost' => 'material_cost'];

    public const DEFAULT_SORT = 'wip_value';

    public const DEFAULT_DESC = true;

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return DB::table('work_orders as w')->join('products as p', 'p.id', '=', 'w.product_id')->where('w.status', '!=', 'cancelled')
            ->when($companyId !== null, fn ($q) => $q->where('w.company_id', $companyId))
            ->when($branchId !== null, fn ($q) => $q->where('w.branch_id', $branchId))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('w.wo_no', 'like', "%{$search}%")->orWhere('p.name', 'like', "%{$search}%")))
            ->orderBy('w.id')
            ->get(['w.id', 'w.wo_no', 'p.name as product', 'w.status', 'w.material_cost', 'w.labor_cost', 'w.overhead_cost', 'w.absorbed_cost', 'w.scrap_loss'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id, 'wo_no' => $row->wo_no, 'product' => $row->product, 'status' => $row->status, 'material_cost' => round((float) $row->material_cost, 2),
                'labor_cost' => round((float) $row->labor_cost, 2), 'overhead_cost' => round((float) $row->overhead_cost, 2), 'absorbed_cost' => round((float) $row->absorbed_cost, 2),
                'scrap_loss' => round((float) $row->scrap_loss, 2),
                'wip_value' => round((float) $row->material_cost + (float) $row->labor_cost + (float) $row->overhead_cost - (float) $row->absorbed_cost - (float) $row->scrap_loss, 2),
            ])->filter(fn (array $row): bool => abs($row['wip_value']) > 0.004)->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        return [
            'orders' => $rows->count(), 'material_cost' => round((float) $rows->sum('material_cost'), 2), 'labor_cost' => round((float) $rows->sum('labor_cost'), 2),
            'overhead_cost' => round((float) $rows->sum('overhead_cost'), 2), 'wip_value' => round((float) $rows->sum('wip_value'), 2),
        ];
    }
}
