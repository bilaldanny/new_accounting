<?php

namespace App\Services\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Work orders of the range (by planned start, else by creation day) with what was planned and produced, scrap and the cost they
 * absorbed: materials, direct labour, overhead and the finished unit cost of the last receipt. `status` narrows to one state.
 */
class ProductionSummaryReport
{
    public const SORTABLE = ['wo_no' => 'wo_no', 'product' => 'product', 'status' => 'status', 'planned_qty' => 'planned_qty', 'produced_qty' => 'produced_qty', 'total_cost' => 'total_cost', 'planned_start' => 'planned_start'];

    public const DEFAULT_SORT = 'wo_no';

    public const DEFAULT_DESC = true;

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        [$from, $to] = ReportDates::range($filters, 90);
        $status = (string) ($filters['status'] ?? 'all');
        $search = trim((string) ($filters['search'] ?? ''));

        return DB::table('work_orders as w')->join('products as p', 'p.id', '=', 'w.product_id')
            ->when($companyId !== null, fn ($q) => $q->where('w.company_id', $companyId))
            ->when($branchId !== null, fn ($q) => $q->where('w.branch_id', $branchId))
            ->whereRaw('date(coalesce(w.planned_start, w.created_at)) >= ?', [$from])->whereRaw('date(coalesce(w.planned_start, w.created_at)) <= ?', [$to])
            ->when($status !== 'all' && $status !== '', fn ($q) => $q->where('w.status', $status))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('w.wo_no', 'like', "%{$search}%")->orWhere('p.name', 'like', "%{$search}%")))
            ->orderByDesc('w.id')
            ->get(['w.id', 'w.wo_no', 'p.name as product', 'w.status', 'w.bom_version', 'w.planned_qty', 'w.produced_qty', 'w.scrap_qty', 'w.material_cost', 'w.labor_cost', 'w.overhead_cost', 'w.fg_unit_cost', 'w.planned_start', 'w.completed_at'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id, 'wo_no' => $row->wo_no, 'product' => $row->product, 'status' => $row->status, 'bom_version' => (int) $row->bom_version,
                'planned_qty' => round((float) $row->planned_qty, 4), 'produced_qty' => round((float) $row->produced_qty, 4), 'scrap_qty' => round((float) $row->scrap_qty, 4),
                'material_cost' => round((float) $row->material_cost, 2), 'labor_cost' => round((float) $row->labor_cost, 2), 'overhead_cost' => round((float) $row->overhead_cost, 2),
                'total_cost' => round((float) $row->material_cost + (float) $row->labor_cost + (float) $row->overhead_cost, 2), 'fg_unit_cost' => $row->fg_unit_cost === null ? null : round((float) $row->fg_unit_cost, 4),
                'planned_start' => $row->planned_start === null ? '' : substr((string) $row->planned_start, 0, 10),
            ])->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        return [
            'orders' => $rows->count(), 'planned_qty' => round((float) $rows->sum('planned_qty'), 4), 'produced_qty' => round((float) $rows->sum('produced_qty'), 4),
            'scrap_qty' => round((float) $rows->sum('scrap_qty'), 4), 'material_cost' => round((float) $rows->sum('material_cost'), 2), 'labor_cost' => round((float) $rows->sum('labor_cost'), 2),
            'overhead_cost' => round((float) $rows->sum('overhead_cost'), 2), 'total_cost' => round((float) $rows->sum('total_cost'), 2),
        ];
    }
}
