<?php

use App\Models\PurchaseLine;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('stock adjustments api creates an adjustment with required fields and line items', function () {
    $scope = seedStockAdjustmentScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/stockadjustments', validStockAdjustmentPayload($scope))
        ->assertSuccessful();

    $adjustment = Transaction::query()->adjustments()->where('company_id', $scope['company_id'])->first();

    expect($adjustment)->not->toBeNull()
        ->and($adjustment->branch_id)->toBe($scope['branch_id'])
        ->and($adjustment->invoice_no)->not->toBeEmpty()
        ->and($adjustment->type)->toBe(Transaction::TYPE_ADJUSTMENT)
        ->and($adjustment->adjustment_type)->toBe('normal')
        ->and($adjustment->status)->toBe('completed');

    $this->assertDatabaseHas('purchase_lines', [
        'transaction_id' => $adjustment->id,
        'product_id' => $scope['product_id'],
        'variation_id' => $scope['variation_id'],
        'quantity_adjustment' => 5,
    ]);
});

test('stock adjustments api rejects an adjustment without a branch or line items', function () {
    $scope = seedStockAdjustmentScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/stockadjustments', validStockAdjustmentPayload($scope, [
        'branch_id' => null,
        'purchaselines' => [],
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['branch_id', 'purchaselines']);
});

test('stock adjustments api rejects a zero-quantity adjustment line', function () {
    $scope = seedStockAdjustmentScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/stockadjustments', validStockAdjustmentPayload($scope, [
        'purchaselines' => [
            [
                'product_id' => $scope['product_id'],
                'variation_id' => $scope['variation_id'],
                'itemtype_id' => $scope['itemtype_id'],
                'unit_id' => $scope['unit_id'],
                'quantity_adjustment' => 0,
            ],
        ],
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['purchaselines']);
});

test('stock adjustments api rejects a decrease that exceeds available stock', function () {
    $scope = seedStockAdjustmentScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/stockadjustments', validStockAdjustmentPayload($scope, [
        'purchaselines' => [
            [
                'product_id' => $scope['product_id'],
                'variation_id' => $scope['variation_id'],
                'itemtype_id' => $scope['itemtype_id'],
                'unit_id' => $scope['unit_id'],
                'quantity_adjustment' => -999,
            ],
        ],
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['purchaselines']);
});

test('stock adjustments index returns branch names and formatted labels', function () {
    $scope = seedStockAdjustmentScope();
    createStockAdjustmentRecord($scope);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/stockadjustments');

    $response->assertSuccessful();
    expect($response->json('data.data.0.company_name'))->toBe('Purchase Test Company')
        ->and($response->json('data.data.0.branch_name'))->toBe('Purchase Branch')
        ->and($response->json('data.data.0.adjustment_type_label'))->toBe('Normal')
        ->and($response->json('data.data.0.invoice_no'))->toBe('SA-00001');
});

test('stock adjustments show returns formatted line items', function () {
    $scope = seedStockAdjustmentScope();
    $adjustment = createStockAdjustmentRecord($scope);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/stockadjustments/'.$adjustment->id);

    $response->assertSuccessful();
    expect($response->json('invoice_no'))->toBe('SA-00001')
        ->and($response->json('purchaselines.0.product_id'))->toBe($scope['product_id'])
        ->and($response->json('purchaselines.0.product_name'))->toBe('Premium Basmati Rice')
        ->and($response->json('purchaselines.0.direction'))->toBe('increase');
});

test('stock adjustments api updates line items and can soft delete an adjustment', function () {
    $scope = seedStockAdjustmentScope();
    $adjustment = createStockAdjustmentRecord($scope);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->putJson('/api/stockadjustments/'.$adjustment->id, validStockAdjustmentPayload($scope, [
        'invoice_no' => 'SA-UPDATED',
        'purchaselines' => [
            [
                'id' => $adjustment->purchaselines()->first()->id,
                'product_id' => $scope['product_id'],
                'variation_id' => $scope['variation_id'],
                'itemtype_id' => $scope['itemtype_id'],
                'unit_id' => $scope['unit_id'],
                'quantity_adjustment' => -3,
            ],
        ],
    ]))->assertSuccessful();

    $adjustment->refresh();

    expect($adjustment->invoice_no)->toBe('SA-UPDATED')
        ->and((float) $adjustment->purchaselines()->first()->quantity_adjustment)->toBe(-3.0);

    $this->postJson('/api/stockadjustments/bulk_delete', [$adjustment->id])
        ->assertSuccessful();

    expect(Transaction::query()->find($adjustment->id))->toBeNull()
        ->and(Transaction::onlyTrashed()->find($adjustment->id))->not->toBeNull();
});

test('a completed adjustment changes branch stock immediately', function () {
    $scope = seedStockAdjustmentScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    expect(PurchaseLine::currentStock($scope['product_id'], $scope['variation_id'], $scope['unit_id'], $scope['branch_id']))->toBe(10.0);

    $this->postJson('/api/stockadjustments', validStockAdjustmentPayload($scope, [
        'purchaselines' => [
            [
                'product_id' => $scope['product_id'],
                'variation_id' => $scope['variation_id'],
                'itemtype_id' => $scope['itemtype_id'],
                'unit_id' => $scope['unit_id'],
                'quantity_adjustment' => 5,
            ],
        ],
    ]))->assertSuccessful();

    expect(PurchaseLine::currentStock($scope['product_id'], $scope['variation_id'], $scope['unit_id'], $scope['branch_id']))->toBe(15.0);

    $this->postJson('/api/stockadjustments', validStockAdjustmentPayload($scope, [
        'purchaselines' => [
            [
                'product_id' => $scope['product_id'],
                'variation_id' => $scope['variation_id'],
                'itemtype_id' => $scope['itemtype_id'],
                'unit_id' => $scope['unit_id'],
                'quantity_adjustment' => -3,
            ],
        ],
    ]))->assertSuccessful();

    expect(PurchaseLine::currentStock($scope['product_id'], $scope['variation_id'], $scope['unit_id'], $scope['branch_id']))->toBe(12.0);
});
