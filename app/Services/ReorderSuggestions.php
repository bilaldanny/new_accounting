<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What to order again. A product is a suggestion when its stock in the branch (StockMovements) is at or below its reorder
 * point: the reorder level (or minimum stock) plus the average daily sales of the last 30 days over its lead time, so a slow
 * supplier asks for an earlier order. The suggested quantity brings stock up to the maximum (when set) or the reorder quantity,
 * never below the reorder quantity. The supplier is the consigning supplier, else the one of the product's last purchase.
 */
class ReorderSuggestions
{
    public const DEMAND_DAYS = 30;

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(int $companyId, ?int $branchId): Collection
    {
        $products = DB::table('products')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->where('active', 1)
            ->where(fn ($q) => $q->whereNotNull('reorder_level')->orWhereNotNull('min_stock'))
            ->get(['id', 'name', 'sku', 'unit_id', 'itemtype_id', 'reorder_level', 'reorder_qty', 'min_stock', 'max_stock', 'lead_time_days', 'consignment_supplier_id', 'ownership']);

        if ($products->isEmpty()) {
            return collect();
        }

        $stock = DB::query()->fromSub(StockMovements::query(['branch_id' => $branchId]), 'm')
            ->whereIn('m.product_id', $products->pluck('id'))
            ->groupBy('m.product_id')
            ->selectRaw('m.product_id as product_id, SUM(m.qty) as stock')
            ->pluck('stock', 'product_id');

        $sold = DB::table('sell_lines as sl')
            ->join('transactions as t', 't.id', '=', 'sl.transaction_id')
            ->where('t.type', Transaction::TYPE_SELL)
            ->whereNull('t.deleted_at')
            ->whereNotIn('t.status', Transaction::UNPOSTED_SELL_STATUSES)
            ->where('t.company_id', $companyId)
            ->when($branchId !== null, fn ($q) => $q->where('t.branch_id', $branchId))
            ->whereDate('t.transaction_date', '>=', now()->subDays(self::DEMAND_DAYS)->toDateString())
            ->whereIn('sl.product_id', $products->pluck('id'))
            ->groupBy('sl.product_id')
            ->selectRaw('sl.product_id as product_id, SUM(sl.quantity - COALESCE(sl.quantity_returned, 0)) as sold')
            ->pluck('sold', 'product_id');

        $lastSupplier = DB::table('purchase_lines as pl')
            ->join('transactions as t', 't.id', '=', 'pl.transaction_id')
            ->where('t.type', Transaction::TYPE_PURCHASE)
            ->whereNull('t.deleted_at')
            ->where('t.status', '!=', 'draft')
            ->where('t.company_id', $companyId)
            ->whereIn('pl.product_id', $products->pluck('id'))
            ->orderByDesc('t.transaction_date')
            ->orderByDesc('t.id')
            ->get(['pl.product_id', 't.contact_id', 'pl.purchase_rate', 'pl.variation_id'])
            ->unique('product_id')
            ->keyBy('product_id');

        $suppliers = DB::table('contacts')->whereIn('id', $lastSupplier->pluck('contact_id')->merge($products->pluck('consignment_supplier_id'))->filter()->unique())
            ->pluck('business_name', 'id');

        return $products->map(function (object $product) use ($stock, $sold, $lastSupplier, $suppliers): ?array {
            $onHand = round((float) ($stock[$product->id] ?? 0), 4);
            $daily = (float) ($sold[$product->id] ?? 0) / self::DEMAND_DAYS;
            $leadTime = (int) ($product->lead_time_days ?? 0);
            $base = (float) ($product->reorder_level ?? $product->min_stock ?? 0);
            $point = round($base + $daily * $leadTime, 4);

            if ($onHand > $point) {
                return null;
            }

            $minimum = (float) ($product->reorder_qty ?? 0);
            $target = $product->max_stock !== null ? (float) $product->max_stock : $point + $minimum;
            $suggested = round(max($target - $onHand, $minimum, 0), 4);

            if ($suggested <= 0) {
                return null;
            }

            $last = $lastSupplier->get($product->id);
            $supplierId = $product->ownership === 'consignment' && $product->consignment_supplier_id ? (int) $product->consignment_supplier_id : ($last?->contact_id ? (int) $last->contact_id : null);

            return [
                'product_id' => (int) $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'unit_id' => (int) $product->unit_id,
                'itemtype_id' => $product->itemtype_id,
                'variation_id' => $last?->variation_id ? (int) $last->variation_id : null,
                'on_hand' => $onHand,
                'reorder_point' => $point,
                'avg_daily_sales' => round($daily, 4),
                'lead_time_days' => $leadTime,
                'suggested_qty' => $suggested,
                'last_rate' => $last === null ? null : round((float) $last->purchase_rate, 2),
                'supplier_id' => $supplierId,
                'supplier_name' => $supplierId === null ? null : ($suppliers[$supplierId] ?? null),
            ];
        })->filter()->values();
    }
}
