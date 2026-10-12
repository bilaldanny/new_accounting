<?php

namespace App\Services\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Materials required by each work order of the range against what was issued, with the quantity variance and the cost of what was
 * issued. A positive variance is an over-issue.
 */
class MaterialVarianceReport
{
    public const SORTABLE = ['wo_no' => 'wo_no', 'component' => 'component', 'required_qty' => 'required_qty', 'issued_qty' => 'issued_qty', 'variance_qty' => 'variance_qty', 'issued_cost' => 'issued_cost'];

    public const DEFAULT_SORT = 'wo_no';

    public const DEFAULT_DESC = true;

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        [$from, $to] = ReportDates::range($filters, 90);
        $search = trim((string) ($filters['search'] ?? ''));
        $cost = DB::table('work_order_issues')->selectRaw('work_order_id, product_id, variation_id, sum(quantity * unit_cost) as amount')->groupBy('work_order_id', 'product_id', 'variation_id');

        return DB::table('work_order_materials as m')->join('work_orders as w', 'w.id', '=', 'm.work_order_id')->join('products as c', 'c.id', '=', 'm.product_id')->join('products as p', 'p.id', '=', 'w.product_id')
            ->leftJoinSub($cost, 'i', fn ($join) => $join->on('i.work_order_id', '=', 'm.work_order_id')->on('i.product_id', '=', 'm.product_id')->on('i.variation_id', '=', 'm.variation_id'))
            ->where('w.status', '!=', 'cancelled')
            ->when($companyId !== null, fn ($q) => $q->where('w.company_id', $companyId))
            ->when($branchId !== null, fn ($q) => $q->where('w.branch_id', $branchId))
            ->whereRaw('date(coalesce(w.planned_start, w.created_at)) >= ?', [$from])->whereRaw('date(coalesce(w.planned_start, w.created_at)) <= ?', [$to])
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('w.wo_no', 'like', "%{$search}%")->orWhere('c.name', 'like', "%{$search}%")->orWhere('p.name', 'like', "%{$search}%")))
            ->orderByDesc('w.id')->orderBy('m.id')
            ->get(['m.id', 'w.wo_no', 'p.name as product', 'c.name as component', 'm.required_qty', 'm.issued_qty', 'i.amount'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id, 'wo_no' => $row->wo_no, 'product' => $row->product, 'component' => $row->component, 'required_qty' => round((float) $row->required_qty, 4),
                'issued_qty' => round((float) $row->issued_qty, 4), 'variance_qty' => round((float) $row->issued_qty - (float) $row->required_qty, 4),
                'variance_percent' => (float) $row->required_qty > 0 ? round(((float) $row->issued_qty - (float) $row->required_qty) / (float) $row->required_qty * 100, 1) : 0.0,
                'issued_cost' => round((float) ($row->amount ?? 0), 2),
            ])->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        return [
            'lines' => $rows->count(), 'required_qty' => round((float) $rows->sum('required_qty'), 4), 'issued_qty' => round((float) $rows->sum('issued_qty'), 4),
            'over_issued' => $rows->where('variance_qty', '>', 0)->count(), 'issued_cost' => round((float) $rows->sum('issued_cost'), 2),
        ];
    }
}
