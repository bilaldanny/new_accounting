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

            $factor = max(SellLine::resolveNumeric($row['packing_qty'] ?? 1, 1), 1.0);
            $baseQuantity = SellLine::resolveNumeric($row['quantity'] ?? 0) * $factor;
            $components = StockMovements::kitComponents((int) $row['product_id']);

            if ($components !== []) {
                // A kit takes its components out of stock, not itself.
                foreach ($components as $component) {
                    $key = $component['product_id'].':'.$component['variation_id'];
                    $requested[$key] = ($requested[$key] ?? 0.0) + $baseQuantity * $component['quantity'];
                }

                continue;
            }

            if (! StockMovements::productKeepsStock((int) $row['product_id'])) {
                continue;
            }

            $key = (int) $row['product_id'].':'.(int) ($row['variation_id'] ?? 0);
            $requested[$key] = ($requested[$key] ?? 0.0) + $baseQuantity;
        }

        $branchId = Transaction::resolveScopedId($sale->branch_id);
        $shortages = [];
        $reservations = StockReservations::enforced($sale->company_id === null ? null : (int) $sale->company_id) ? app(StockReservations::class) : null;

        foreach ($requested as $key => $quantity) {
            [$productId, $variationId] = array_map('intval', explode(':', $key));
            $available = StockMovements::baseStock($productId, $variationId, $branchId, $isExisting ? $sale->id : null);

            if ($reservations !== null) {
                // Stock others hold is not available to this sale (the customer's own holds are).
                $available = round($available - $reservations->heldForOthers((int) $sale->company_id, $productId, $variationId, $branchId, $sale->contact_id === null ? null : (int) $sale->contact_id), 6);
            }

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
