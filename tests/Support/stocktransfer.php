<?php

use App\Models\PurchaseLine;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * @return array<string, mixed>
 */
function seedStockTransferScope(): array
{
    $scope = seedPurchaseScope();

    $toBranchId = DB::table('branches')->insertGetId([
        'code' => 'PURB002',
        'company_id' => $scope['company_id'],
        'name' => 'Transfer Destination Branch',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

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

    return array_merge($scope, ['tobranch_id' => $toBranchId]);
}

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validStockTransferPayload(array $scope, array $overrides = []): array
{
    return array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'tobranch_id' => $scope['tobranch_id'],
        'transaction_date' => '2026-08-23',
        'additional_note' => 'Rebalancing stock',
        'status' => 'pending',
        'total_item' => 1,
        'purchaselines' => [
            [
                'product_id' => $scope['product_id'],
                'variation_id' => $scope['variation_id'],
                'itemtype_id' => $scope['itemtype_id'],
                'unit_id' => $scope['unit_id'],
                'quantity' => 4,
                'packing_qty' => 1,
            ],
        ],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $attributes
 */
function createStockTransferRecord(array $scope, array $attributes = []): Transaction
{
    $transfer = Transaction::query()->create(array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'tobranch_id' => $scope['tobranch_id'],
        'invoice_no' => 'ST-00001',
        'type' => Transaction::TYPE_TRANSFER,
        'status' => 'pending',
        'transaction_date' => now(),
        'total_item' => 1,
    ], $attributes));

    PurchaseLine::query()->create([
        'transaction_id' => $transfer->id,
        'product_id' => $scope['product_id'],
        'variation_id' => $scope['variation_id'],
        'itemtype_id' => $scope['itemtype_id'],
        'unit_id' => $scope['unit_id'],
        'quantity' => 2,
        'packing_qty' => 1,
    ]);

    return $transfer;
}
