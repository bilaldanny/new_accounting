<?php

namespace App\Services\Reports;

use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ABC Analysis: products ranked by what they sold for in the period (default the last 90 days, posted sales, net of what was
 * returned). Class A is the products that make the first 80% of the sales value, B the next 15%, C the rest; a product that
 * did not sell is not ranked. Read-only.
 */
class AbcAnalysisReport
{
    public const SORTABLE = [
        'rank' => 'rank',
        'product_name' => 'product_name',
        'quantity' => 'quantity',
        'sales_value' => 'sales_value',
        'share_percent' => 'share_percent',
        'cumulative_percent' => 'cumulative_percent',
        'class' => 'class',
    ];

    public const DEFAULT_SORT = 'rank';

    public const DEFAULT_DESC = false;

    public const A_LIMIT = 80.0;

    public const B_LIMIT = 95.0;

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        [$from, $to] = ReportDates::range($filters, 90);
        $search = trim((string) ($filters['search'] ?? ''));
        $class = strtoupper(trim((string) ($filters['status'] ?? '')));

        $sold = DB::table('sell_lines as sl')
            ->join('transactions as t', 't.id', '=', 'sl.transaction_id')
            ->join('products as p', 'p.id', '=', 'sl.product_id')
            ->where('t.type', Transaction::TYPE_SELL)
            ->whereNull('t.deleted_at')
            ->whereNull('p.deleted_at')
            ->whereNotIn('t.status', Transaction::UNPOSTED_SELL_STATUSES)
            ->whereDate('t.transaction_date', '>=', $from)
            ->whereDate('t.transaction_date', '<=', $to)
            ->when($companyId !== null, fn ($q) => $q->where('t.company_id', $companyId))
            ->when($branchId !== null, fn ($q) => $q->where('t.branch_id', $branchId))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('p.name', 'like', "%{$search}%")->orWhere('p.sku', 'like', "%{$search}%")))
            ->groupBy('sl.product_id', 'p.name', 'p.sku')
            ->selectRaw('sl.product_id as product_id, p.name as product_name, p.sku as sku, '
                .'sum(sl.quantity - coalesce(sl.quantity_returned, 0)) as quantity, '
                .'sum(case when sl.quantity > 0 then sl.subtotal * (sl.quantity - coalesce(sl.quantity_returned, 0)) / sl.quantity else 0 end) as sales_value')
            ->get()
            ->filter(fn (object $row): bool => (float) $row->sales_value > 0)
            ->sortByDesc('sales_value')
            ->values();

        $total = (float) $sold->sum('sales_value');
        $running = 0.0;

        return $sold->map(function (object $row, int $index) use ($total, &$running): array {
            $value = round((float) $row->sales_value, 2);
            $before = $total > 0 ? $running / $total * 100 : 0.0;
            $running += (float) $row->sales_value;
            $cumulative = $total > 0 ? $running / $total * 100 : 0.0;

            return [
                'id' => (int) $row->product_id,
                'rank' => $index + 1,
                'product_name' => $row->product_name,
                'sku' => $row->sku,
                'quantity' => round((float) $row->quantity, 4),
                'sales_value' => $value,
                'share_percent' => $total > 0 ? round($value / $total * 100, 2) : 0.0,
                'cumulative_percent' => round($cumulative, 2),
                // a product belongs to the class its first rupee falls in, so the one that crosses 80% is still an A
                'class' => $before < self::A_LIMIT ? 'A' : ($before < self::B_LIMIT ? 'B' : 'C'),
            ];
        })->when(in_array($class, ['A', 'B', 'C'], true), fn (Collection $rows) => $rows->where('class', $class)->values());
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        return [
            'products' => $rows->count(),
            'class_a' => $rows->where('class', 'A')->count(),
            'class_b' => $rows->where('class', 'B')->count(),
            'class_c' => $rows->where('class', 'C')->count(),
            'sales_value' => round((float) $rows->sum('sales_value'), 2),
        ];
    }
}
