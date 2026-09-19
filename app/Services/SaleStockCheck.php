<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SellLine;
use App\Models\Transaction;

/**
 * Finds the products a sale would take below zero stock. The caller decides what a shortage means:
 * the POS refuses the sale (InsufficientStockException), the normal sale form only warns.
 *
 * Quantities are compared in base units, summed per product and variation, in the sale's branch.
 * When a sale is being edited its own current lines are left out of the stock figure. Drafts,
 * quotations and sales that predate the stock cutover do not touch stock, so they never report a
 * shortage.
 */
class SaleStockCheck
{
    /**
     * @param  Transaction  $sale  The sale as it is about to be saved (status and branch already set).
     * @param  array<int, mixed>  $lines  The `selllines` rows of the request.
     * @return list<array{product_id: int, variation_id: int, product_name: string, requested: float, available: float}>
     */
    public function shortages(Transaction $sale, array $lines): array
    {
        $isExisting = $sale->exists;

        if (! StockMovements::saleCountsAgainstStock($sale->status, $isExisting ? $sale->created_at : now())) {
            return [];
        }

        $requested = [];

        foreach ($lines as $row) {
            if (! is_array($row) || (int) ($row['product_id'] ?? 0) === 0) {
                continue;
            }

            $key = (int) $row['product_id'].':'.(int) ($row['variation_id'] ?? 0);
            $factor = max(SellLine::resolveNumeric($row['packing_qty'] ?? 1, 1), 1.0);

            $requested[$key] = ($requested[$key] ?? 0.0) + SellLine::resolveNumeric($row['quantity'] ?? 0) * $factor;
        }

        $branchId = Transaction::resolveScopedId($sale->branch_id);
        $shortages = [];

        foreach ($requested as $key => $quantity) {
            [$productId, $variationId] = array_map('intval', explode(':', $key));
            $available = StockMovements::baseStock($productId, $variationId, $branchId, $isExisting ? $sale->id : null);

            if (round($quantity, 6) > $available) {
                $shortages[] = [
                    'product_id' => $productId,
                    'variation_id' => $variationId,
                    'product_name' => '',
                    'requested' => round($quantity, 6),
                    'available' => $available,
                ];
            }
        }

        if ($shortages === []) {
            return [];
        }

        $names = Product::query()->whereIn('id', array_column($shortages, 'product_id'))->pluck('name', 'id');

        return array_map(
            fn (array $shortage): array => [...$shortage, 'product_name' => (string) ($names[$shortage['product_id']] ?? '')],
            $shortages,
        );
    }
}
