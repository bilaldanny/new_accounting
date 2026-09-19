<?php

use App\Models\PurchaseLine;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('stock transfers api creates a transfer with required fields and line items', function () {
    $scope = seedStockTransferScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/stocktransfers', validStockTransferPayload($scope))
        ->assertSuccessful();

    $transfer = Transaction::query()->transfers()->where('company_id', $scope['company_id'])->first();

    expect($transfer)->not->toBeNull()
        ->and($transfer->branch_id)->toBe($scope['branch_id'])
        ->and($transfer->tobranch_id)->toBe($scope['tobranch_id'])
        ->and($transfer->invoice_no)->not->toBeEmpty()
        ->and($transfer->type)->toBe(Transaction::TYPE_TRANSFER)
        ->and($transfer->status)->toBe('pending');

    $this->assertDatabaseHas('purchase_lines', [
        'transaction_id' => $transfer->id,
        'product_id' => $scope['product_id'],
        'variation_id' => $scope['variation_id'],
        'quantity' => 4,
    ]);
});

test('stock transfers api rejects a transfer without a destination branch or line items', function () {
    $scope = seedStockTransferScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/stocktransfers', validStockTransferPayload($scope, [
        'tobranch_id' => null,
        'purchaselines' => [],
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['tobranch_id', 'purchaselines']);
});

test('stock transfers api rejects a transfer to the same branch', function () {
    $scope = seedStockTransferScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/stocktransfers', validStockTransferPayload($scope, [
        'tobranch_id' => $scope['branch_id'],
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['tobranch_id']);
});

test('stock transfers api rejects a transfer that exceeds available stock', function () {
    $scope = seedStockTransferScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/stocktransfers', validStockTransferPayload($scope, [
        'purchaselines' => [
            [
                'product_id' => $scope['product_id'],
                'variation_id' => $scope['variation_id'],
                'itemtype_id' => $scope['itemtype_id'],
                'unit_id' => $scope['unit_id'],
                'quantity' => 999,
                'packing_qty' => 1,
            ],
        ],
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['purchaselines']);
});

test('stock transfers index returns branch names and formatted labels', function () {
    $scope = seedStockTransferScope();
    createStockTransferRecord($scope);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/stocktransfers');

    $response->assertSuccessful();
    expect($response->json('data.data.0.company_name'))->toBe('Purchase Test Company')
        ->and($response->json('data.data.0.branch_name'))->toBe('Purchase Branch')
        ->and($response->json('data.data.0.tobranch_name'))->toBe('Transfer Destination Branch')
        ->and($response->json('data.data.0.invoice_no'))->toBe('ST-00001');
});

test('stock transfers show returns formatted line items', function () {
    $scope = seedStockTransferScope();
    $transfer = createStockTransferRecord($scope);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/stocktransfers/'.$transfer->id);

    $response->assertSuccessful();
    expect($response->json('invoice_no'))->toBe('ST-00001')
        ->and($response->json('purchaselines.0.product_id'))->toBe($scope['product_id'])
        ->and($response->json('purchaselines.0.product_name'))->toBe('Premium Basmati Rice');
});

test('stock transfers api updates line items and can soft delete a transfer', function () {
    $scope = seedStockTransferScope();
    $transfer = createStockTransferRecord($scope);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->putJson('/api/stocktransfers/'.$transfer->id, validStockTransferPayload($scope, [
        'invoice_no' => 'ST-UPDATED',
        'purchaselines' => [
            [
                'id' => $transfer->purchaselines()->first()->id,
                'product_id' => $scope['product_id'],
                'variation_id' => $scope['variation_id'],
                'itemtype_id' => $scope['itemtype_id'],
                'unit_id' => $scope['unit_id'],
                'quantity' => 3,
                'packing_qty' => 1,
            ],
        ],
    ]))->assertSuccessful();

    $transfer->refresh();

    expect($transfer->invoice_no)->toBe('ST-UPDATED')
        ->and((float) $transfer->purchaselines()->first()->quantity)->toBe(3.0);

    $this->postJson('/api/stocktransfers/bulk_delete', [$transfer->id])
        ->assertSuccessful();

    expect(Transaction::query()->find($transfer->id))->toBeNull()
        ->and(Transaction::onlyTrashed()->find($transfer->id))->not->toBeNull();
});

test('a completed transfer moves stock from the source branch into the destination branch', function () {
    $scope = seedStockTransferScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    expect(PurchaseLine::currentStock($scope['product_id'], $scope['variation_id'], $scope['unit_id'], $scope['branch_id']))->toBe(10.0)
        ->and(PurchaseLine::currentStock($scope['product_id'], $scope['variation_id'], $scope['unit_id'], $scope['tobranch_id']))->toBe(0.0);

    $this->postJson('/api/stocktransfers', validStockTransferPayload($scope, [
        'status' => 'completed',
    ]))->assertSuccessful();

    expect(PurchaseLine::currentStock($scope['product_id'], $scope['variation_id'], $scope['unit_id'], $scope['branch_id']))->toBe(6.0)
        ->and(PurchaseLine::currentStock($scope['product_id'], $scope['variation_id'], $scope['unit_id'], $scope['tobranch_id']))->toBe(4.0);
});
