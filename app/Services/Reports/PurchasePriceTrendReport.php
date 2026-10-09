<?php

namespace App\Services\Reports;

use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Purchase Price Trend: for each product variation and calendar month in the range (default the last 12
 * months), the quantity bought and the weighted average, lowest and highest purchase rate, and the change of
 * the average against that variation's previous month with purchases. Drafts are left out.
 */
class PurchasePriceTrendReport
{
    public const SORTABLE = [
        'product_name' => 'product_name',
        'month' => 'month',
        'quantity' => 'quantity',
        'average_rate' => 'average_rate',
        'min_rate' => 'min_rate',
        'max_rate' => 'max_rate',
        'change_percent' => 'change_percent',
    ];

    public const DEFAULT_SORT = 'month';

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        [$from, $to] = ReportDates::range($filters, 365);
        $search = trim((string) ($filters['search'] ?? ''));

        $lines = DB::table('purchase_lines as l')
            ->join('transactions as t', 't.id', '=', 'l.transaction_id')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('product_details as d', 'd.id', '=', 'l.variation_id')
            ->where('t.type', Transaction::TYPE_PURCHASE)
            ->where('t.status', '!=', 'draft')
            ->whereNull('t.deleted_at')
            ->when($companyId !== null, fn ($q) => $q->where('t.company_id', $companyId))
            ->when($branchId !== null, fn ($q) => $q->where('t.branch_id', $branchId))
            ->when(! empty($filters['product_id']), fn ($q) => $q->where('l.product_id', $filters['product_id']))
            ->when($search !== '', fn ($q) => $q->where('p.name', 'like', "%{$search}%"))
            ->whereDate('t.transaction_date', '>=', $from)
            ->whereDate('t.transaction_date', '<=', $to)
            ->where('l.quantity', '>', 0)
            ->get(['l.variation_id', 'p.name as product_name', 'd.variation_name', 't.transaction_date', 'l.quantity', 'l.purchase_rate']);

        $rows = $lines
            ->groupBy(fn ($line): string => $line->variation_id.'|'.substr((string) $line->transaction_date, 0, 7))
            ->map(function (Collection $group): array {
                $first = $group->first();
                $quantity = (float) $group->sum('quantity');

                return [
                    'variation_id' => (int) $first->variation_id,
                    'product_name' => trim($first->product_name.($first->variation_name && $first->variation_name !== 'dummy' ? ' - '.$first->variation_name : '')),
                    'month' => substr((string) $first->transaction_date, 0, 7),
                    'quantity' => round($quantity, 2),
                    'average_rate' => round($group->sum(fn ($l): float => (float) $l->purchase_rate * (float) $l->quantity) / $quantity, 2),
                    'min_rate' => round((float) $group->min('purchase_rate'), 2),
                    'max_rate' => round((float) $group->max('purchase_rate'), 2),
                    'change_percent' => null,
                ];
            })
            ->sortBy('month')
            ->values();

        $previous = [];

        return $rows->map(function (array $row) use (&$previous): array {
            $last = $previous[$row['variation_id']] ?? null;
            $row['change_percent'] = $last !== null && $last > 0 ? round(($row['average_rate'] - $last) / $last * 100, 2) : null;
            $previous[$row['variation_id']] = $row['average_rate'];
            $row['id'] = $row['variation_id'] * 1000000 + (int) str_replace('-', '', $row['month']);

            return $row;
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        return [
            'count' => $rows->count(),
            'products' => $rows->pluck('variation_id')->unique()->count(),
            'quantity' => round((float) $rows->sum('quantity'), 2),
            'rising' => $rows->filter(fn (array $row): bool => ($row['change_percent'] ?? 0) > 0)->count(),
            'falling' => $rows->filter(fn (array $row): bool => ($row['change_percent'] ?? 0) < 0)->count(),
        ];
    }
}
