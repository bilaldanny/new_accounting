<?php

namespace App\Services\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Scrap recorded on work orders in the range, normal (absorbed in the good units) and abnormal (a loss), with the cost taken out.
 * `status` can be `normal` or `abnormal`.
 */
class ScrapReport
{
    public const SORTABLE = ['recorded_on' => 'recorded_on', 'wo_no' => 'wo_no', 'product' => 'product', 'quantity' => 'quantity', 'cost' => 'cost', 'type' => 'type'];

    public const DEFAULT_SORT = 'recorded_on';

    public const DEFAULT_DESC = true;

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        [$from, $to] = ReportDates::range($filters, 90);
        $type = (string) ($filters['status'] ?? 'all');
        $search = trim((string) ($filters['search'] ?? ''));

        return DB::table('work_order_scraps as s')->join('work_orders as w', 'w.id', '=', 's.work_order_id')->join('products as p', 'p.id', '=', 'w.product_id')
            ->when($companyId !== null, fn ($q) => $q->where('s.company_id', $companyId))
            ->when($branchId !== null, fn ($q) => $q->where('w.branch_id', $branchId))
            ->whereBetween('s.recorded_on', [$from, $to])
            ->when($type === 'normal', fn ($q) => $q->where('s.is_normal', true))
            ->when($type === 'abnormal', fn ($q) => $q->where('s.is_normal', false))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('w.wo_no', 'like', "%{$search}%")->orWhere('p.name', 'like', "%{$search}%")->orWhere('s.reason', 'like', "%{$search}%")))
            ->orderByDesc('s.recorded_on')->orderByDesc('s.id')
            ->get(['s.id', 's.recorded_on', 'w.wo_no', 'p.name as product', 's.quantity', 's.is_normal', 's.reason', 's.cost'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id, 'recorded_on' => substr((string) $row->recorded_on, 0, 10), 'wo_no' => $row->wo_no, 'product' => $row->product, 'quantity' => round((float) $row->quantity, 4),
                'type' => $row->is_normal ? 'normal' : 'abnormal', 'reason' => (string) $row->reason, 'cost' => round((float) $row->cost, 2),
            ])->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        return [
            'entries' => $rows->count(), 'quantity' => round((float) $rows->sum('quantity'), 4), 'normal_qty' => round((float) $rows->where('type', 'normal')->sum('quantity'), 4),
            'abnormal_qty' => round((float) $rows->where('type', 'abnormal')->sum('quantity'), 4), 'loss' => round((float) $rows->sum('cost'), 2),
        ];
    }
}
