<?php

use App\Models\PurchaseLine;
use App\Models\Transaction;

/**
 * @return array<string, mixed>
 */
function seedStockAdjustmentScope(): array
{
    $scope = seedPurchaseScope();

    $purchase = Transaction::query()->create([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'contact_id' => $scope['contact_id'],
        'invoice_no' => 'PO-STOCK-0001',
        'type' => Transaction::TYPE_PURCHASE,
        'status' => 'received',
        'payment_status' => 'due',
        'transaction_date' => now(),
        'final_amount' => 1000,
        'total_item' => 1,
    ]);

    PurchaseLine::query()->create([
        'transaction_id' => $purchase->id,
        'product_id' => $scope['product_id'],
        'variation_id' => $scope['variation_id'],
        'itemtype_id' => $scope['itemtype_id'],
        'unit_id' => $scope['unit_id'],
        'quantity' => 10,
        'quantity_received' => 10,
        'purchase_rate' => 100,
        'pp_without_discount' => 100,
        'default_sell_price' => 120,
        'packing_qty' => 1,
    ]);

    return $scope;
}

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validStockAdjustmentPayload(array $scope, array $overrides = []): array
{
    return array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'transaction_date' => '2026-08-23',
        'adjustment_type' => 'normal',
        'additional_note' => 'Cycle count correction',
        'status' => 'completed',
        'total_item' => 1,
        'purchaselines' => [
            [
                'product_id' => $scope['product_id'],
                'variation_id' => $scope['variation_id'],
                'itemtype_id' => $scope['itemtype_id'],
                'unit_id' => $scope['unit_id'],
                'quantity_adjustment' => 5,
            ],
        ],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $attributes
 */
function createStockAdjustmentRecord(array $scope, array $attributes = []): Transaction
{
    $adjustment = Transaction::query()->create(array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'invoice_no' => 'SA-00001',
        'type' => Transaction::TYPE_ADJUSTMENT,
        'adjustment_type' => 'normal',
        'status' => 'completed',
        'transaction_date' => now(),
        'total_item' => 1,
    ], $attributes));

    PurchaseLine::query()->create([
        'transaction_id' => $adjustment->id,
        'product_id' => $scope['product_id'],
        'variation_id' => $scope['variation_id'],
        'itemtype_id' => $scope['itemtype_id'],
        'unit_id' => $scope['unit_id'],
        'quantity_adjustment' => 2,
    ]);

    return $adjustment;
}
