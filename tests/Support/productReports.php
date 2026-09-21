<?php

use Illuminate\Support\Facades\DB;

/**
 * Helpers for the product, stock and tax report tests. They build on trpScope / trpDoc / trpPayment:
 * products and their lines are inserted straight into the tables so every quantity, rate and date is
 * known. Rates are per base unit and a line's base units are `quantity x packing_qty`, as in the forms.
 */

/**
 * A product with one variation (product_details row) and its base unit.
 *
 * @param  array{company_id: int, branch_id: int}  $scope
 * @param  array<string, mixed>  $attributes  product columns
 * @param  array<string, mixed>  $variation  product_details columns
 * @return array{product_id: int, variation_id: int, unit_id: int}
 */
function prdProduct(array $scope, string $name, array $attributes = [], array $variation = []): array
{
    static $sequence = 0;
    $sequence++;

    $unitId = $attributes['unit_id'] ?? DB::table('units')->insertGetId([
        'company_id' => $scope['company_id'],
        'name' => 'Piece '.$sequence,
        'short_name' => 'pc'.$sequence,
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $productId = DB::table('products')->insertGetId(array_merge([
        'company_id' => $scope['company_id'],
        'unit_id' => $unitId,
        'name' => $name,
        'sku' => 'SKU-'.$sequence,
        'active' => 1,
        'type' => 'single',
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes, ['unit_id' => $unitId]));

    $variationId = DB::table('product_details')->insertGetId(array_merge([
        'product_id' => $productId,
        'name' => $name,
        'sku' => 'SKU-'.$sequence,
        'variation_name' => 'Default',
        'default_purchase_price' => 0,
        'dpp_unit_price' => 0,
        'largequantity' => 0,
        'smallquantity' => 0,
        'profit_percent' => 0,
        'default_sell_price' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ], $variation));

    return ['product_id' => $productId, 'variation_id' => $variationId, 'unit_id' => $unitId];
}

/**
 * @param  array{product_id: int, variation_id: int, unit_id: int}  $product
 * @param  array<string, mixed>  $attributes
 */
function prdPurchaseLine(int $transactionId, array $product, float $quantity, float $rate, array $attributes = []): int
{
    return DB::table('purchase_lines')->insertGetId(array_merge([
        'transaction_id' => $transactionId,
        'product_id' => $product['product_id'],
        'variation_id' => $product['variation_id'],
        'unit_id' => $product['unit_id'],
        'quantity' => $quantity,
        'quantity_received' => $quantity,
        'qunatity_sold' => 0,
        'quantity_returned' => 0,
        'quantity_adjustment' => 0,
        'purchase_rate' => $rate,
        'pp_without_discount' => $rate,
        'discount_percent' => 0,
        'packing_qty' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));
}

/**
 * A sell line; the stored subtotal defaults to `unit_price x quantity x packing_qty`.
 *
 * @param  array{product_id: int, variation_id: int, unit_id: int}  $product
 * @param  array<string, mixed>  $attributes
 */
function prdSellLine(int $transactionId, array $product, float $quantity, float $unitPrice, array $attributes = []): int
{
    $packing = (float) ($attributes['packing_qty'] ?? 1);

    return DB::table('sell_lines')->insertGetId(array_merge([
        'transaction_id' => $transactionId,
        'product_id' => $product['product_id'],
        'variation_id' => $product['variation_id'],
        'unit_id' => $product['unit_id'],
        'quantity' => $quantity,
        'quantity_issue' => 0,
        'quantity_returned' => 0,
        'unit_price' => $unitPrice,
        'discount_percent' => 0,
        'unit_price_after_discount' => $unitPrice,
        'subtotal' => $unitPrice * $quantity * $packing,
        'packing_qty' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));
}

/**
 * A purchase with one line, returning [transaction id, line id].
 *
 * @param  array{company_id: int, branch_id: int, customer_id: int, supplier_id: int}  $scope
 * @param  array{product_id: int, variation_id: int, unit_id: int}  $product
 * @param  array<string, mixed>  $document
 * @param  array<string, mixed>  $line
 * @return array{0: int, 1: int}
 */
function prdPurchase(array $scope, array $product, float $quantity, float $rate, string $date, array $document = [], array $line = []): array
{
    $packing = (float) ($line['packing_qty'] ?? 1);
    $transactionId = trpDoc($scope, 'purchaseorder', array_merge([
        'status' => 'received',
        'transaction_date' => $date,
        'final_amount' => $rate * $quantity * $packing,
        'total_before_tax' => $rate * $quantity * $packing,
        'tax_amount' => 0,
    ], $document));

    return [$transactionId, prdPurchaseLine($transactionId, $product, $quantity, $rate, $line)];
}

/**
 * A sale with one line, returning [transaction id, line id].
 *
 * @param  array{company_id: int, branch_id: int, customer_id: int, supplier_id: int}  $scope
 * @param  array{product_id: int, variation_id: int, unit_id: int}  $product
 * @param  array<string, mixed>  $document
 * @param  array<string, mixed>  $line
 * @return array{0: int, 1: int}
 */
function prdSale(array $scope, array $product, float $quantity, float $unitPrice, string $date, array $document = [], array $line = []): array
{
    $packing = (float) ($line['packing_qty'] ?? 1);
    $transactionId = trpDoc($scope, 'sell', array_merge([
        'status' => 'final',
        'transaction_date' => $date,
        'final_amount' => $unitPrice * $quantity * $packing,
        'total_before_tax' => $unitPrice * $quantity * $packing,
        'tax_amount' => 0,
    ], $document));

    return [$transactionId, prdSellLine($transactionId, $product, $quantity, $unitPrice, $line)];
}
