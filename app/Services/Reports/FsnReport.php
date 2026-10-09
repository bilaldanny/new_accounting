<?php

namespace App\Services\Reports;

use App\Models\Transaction;
use App\Services\StockMovements;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fast / Slow-Moving & Dead Stock (FSN): every active product with stock on hand or sales in the period
 * (default the last 90 days). Sold quantity is net of nothing (posted sales only). A product with no sale in
 * the period is Non-moving (dead stock); of the rest, those above the median sold quantity are Fast and the
 * others Slow. Stock on hand is StockMovements, the one stock definition.
 */
class FsnReport
{
    public const SORTABLE = [
        'product_name' => 'product_name',
        'stock' => 'stock',
        'sold' => 'sold',
        'last_sale' => 'last_sale',
        'days_since_last_sale' => 'days_since_last_sale',
        'class' => 'class',
    ];

    public const DEFAULT_SORT = 'sold';

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        [$from, $to] = ReportDates::range($filters, 90);
        $search = trim((string) ($filters['search'] ?? ''));

        $sales = fn () => DB::table('sell_lines as sl')
            ->join('transactions as t', 't.id', '=', 'sl.transaction_id')
            ->where('t.type', Transaction::TYPE_SELL)
            ->whereNull('t.deleted_at')
            ->whereNotIn('t.status', Transaction::UNPOSTED_SELL_STATUSES)
            ->whereDate('t.transaction_date', '<=', $to)
            ->when($companyId !== null, fn ($q) => $q->where('t.company_id', $companyId))
            ->when($branchId !== null, fn ($q) => $q->where('t.branch_id', $branchId));

        $sold = $sales()->whereDate('t.transaction_date', '>=', $from)->groupBy('sl.product_id')
            ->selectRaw('sl.product_id as product_id, SUM(sl.quantity) as sold')->pluck('sold', 'product_id');
        $last = $sales()->groupBy('sl.product_id')
            ->selectRaw('sl.product_id as product_id, MAX(t.transaction_date) as last_sale')->pluck('last_sale', 'product_id');

        $stock = DB::query()->fromSub(StockMovements::query(['as_of' => $to]), 'm')
            ->when($branchId !== null, fn ($q) => $q->where('m.branch_id', $branchId))
            ->groupBy('m.product_id')
            ->selectRaw('m.product_id as product_id, SUM(m.qty) as stock')->pluck('stock', 'product_id');

        $products = DB::table('products')
            ->whereNull('deleted_at')->where('active', 1)
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->get(['id', 'name', 'sku']);

        $rows = $products
            ->map(fn (object $product): array => [
                'id' => (int) $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'stock' => round((float) ($stock[$product->id] ?? 0), 2),
                'sold' => round((float) ($sold[$product->id] ?? 0), 2),
                'last_sale' => isset($last[$product->id]) ? substr((string) $last[$product->id], 0, 10) : null,
            ])
            ->filter(fn (array $row): bool => $row['stock'] > 0 || $row['sold'] > 0)
            ->values();

        $selling = $rows->where('sold', '>', 0)->pluck('sold')->sort()->values();
        $median = $selling->isEmpty() ? 0.0 : (float) $selling[intdiv($selling->count() - 1, 2)];

        return $rows->map(function (array $row) use ($median, $to): array {
            $row['class'] = $row['sold'] <= 0 ? 'Non-moving' : ($row['sold'] > $median ? 'Fast' : 'Slow');
            $row['days_since_last_sale'] = $row['last_sale'] === null ? null : (int) Carbon::parse($row['last_sale'])->diffInDays($to, false);

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
            'products' => $rows->count(),
            'fast' => $rows->where('class', 'Fast')->count(),
            'slow' => $rows->where('class', 'Slow')->count(),
            'non_moving' => $rows->where('class', 'Non-moving')->count(),
            'dead_stock_units' => round((float) $rows->where('class', 'Non-moving')->sum('stock'), 2),
        ];
    }
}
