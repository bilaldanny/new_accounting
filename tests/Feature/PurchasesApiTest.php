<?php

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('purchases api creates a purchase with required fields and line items', function () {
    $scope = seedPurchaseScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchases', validPurchasePayload($scope))
        ->assertSuccessful();

    $purchase = Transaction::query()->purchases()->where('contact_id', $scope['contact_id'])->first();

    expect($purchase)->not->toBeNull()
        ->and($purchase->company_id)->toBe($scope['company_id'])
        ->and($purchase->branch_id)->toBe($scope['branch_id'])
        ->and($purchase->invoice_no)->not->toBeEmpty()
        ->and($purchase->type)->toBe(Transaction::TYPE_PURCHASE)
        ->and($purchase->payment_status)->toBe('due')
        ->and((float) $purchase->shipping_charges)->toBe(50.0);

    $this->assertDatabaseHas('purchase_lines', [
        'transaction_id' => $purchase->id,
        'product_id' => $scope['product_id'],
        'variation_id' => $scope['variation_id'],
        'quantity' => 2,
    ]);
});

test('purchases api rejects a purchase without a supplier or line items', function () {
    $scope = seedPurchaseScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchases', validPurchasePayload($scope, [
        'contact_id' => null,
        'purchaselines' => [],
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['contact_id', 'purchaselines']);
});

test('purchases index returns supplier name and formatted labels', function () {
    $scope = seedPurchaseScope();
    createPurchaseRecord($scope);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/purchases');

    $response->assertSuccessful();
    expect($response->json('data.data.0.company_name'))->toBe('Purchase Test Company')
        ->and($response->json('data.data.0.branch_name'))->toBe('Purchase Branch')
        ->and($response->json('data.data.0.supplier_name'))->toBe('Acme Supplies')
        ->and($response->json('data.data.0.invoice_no'))->toBe('PO-00001');
});

test('purchases show returns formatted line items', function () {
    $scope = seedPurchaseScope();
    $purchase = createPurchaseRecord($scope);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/purchases/'.$purchase->id);

    $response->assertSuccessful();
    expect($response->json('invoice_no'))->toBe('PO-00001')
        ->and($response->json('purchaselines.0.product_id'))->toBe($scope['product_id'])
        ->and($response->json('purchaselines.0.product_name'))->toBe('Premium Basmati Rice');
});

test('purchases search products returns matching catalog rows', function () {
    $scope = seedPurchaseScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/purchases/search-products?company_id='.$scope['company_id'].'&search=Basmati');

    $response->assertSuccessful();
    expect($response->json('0.product_id'))->toBe($scope['product_id'])
        ->and($response->json('0.sku'))->toBe('AS-00001-1');
});

test('purchases search products lists catalog rows for the select picker', function () {
    $scope = seedPurchaseScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/purchases/search-products?company_id='.$scope['company_id']);

    $response->assertSuccessful();
    expect($response->json('0.product_id'))->toBe($scope['product_id'])
        ->and($response->json('0.name'))->toBe('Premium Basmati Rice dummy');
});

test('purchases search products can filter by category item type and product', function () {
    $scope = seedPurchaseScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->getJson('/api/purchases/search-products?company_id='.$scope['company_id'].'&category_id=999999')
        ->assertSuccessful()
        ->assertExactJson([]);

    $response = $this->getJson(
        '/api/purchases/search-products?company_id='.$scope['company_id']
        .'&category_id='.$scope['category_id']
        .'&itemtype_id='.$scope['itemtype_id']
        .'&product_id='.$scope['product_id']
    );

    $response->assertSuccessful();
    expect($response->json('0.product_id'))->toBe($scope['product_id'])
        ->and($response->json('0.product_name'))->toBe('Premium Basmati Rice')
        ->and($response->json('0.variation_name'))->toBe('dummy');
});

test('purchases api updates line items and can soft delete a purchase', function () {
    $scope = seedPurchaseScope();
    $purchase = createPurchaseRecord($scope);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->putJson('/api/purchases/'.$purchase->id, validPurchasePayload($scope, [
        'invoice_no' => 'PO-UPDATED',
        'shipping_charges' => 10,
        'final_amount' => 310,
        'purchaselines' => [
            [
                'id' => $purchase->purchaselines()->first()->id,
                'product_id' => $scope['product_id'],
                'variation_id' => $scope['variation_id'],
                'itemtype_id' => $scope['itemtype_id'],
                'unit_id' => $scope['unit_id'],
                'quantity' => 3,
                'packing_qty' => 1,
                'pp_without_discount' => 100,
                'discount_percent' => 0,
                'purchase_rate' => 100,
                'profit_percent' => 20,
                'default_sell_price' => 120,
            ],
        ],
    ]))->assertSuccessful();

    $purchase->refresh();

    expect($purchase->invoice_no)->toBe('PO-UPDATED')
        ->and((float) $purchase->purchaselines()->first()->quantity)->toBe(3.0);

    $this->postJson('/api/purchases/bulk_delete', [$purchase->id])
        ->assertSuccessful();

    expect(Transaction::query()->find($purchase->id))->toBeNull()
        ->and(Transaction::onlyTrashed()->find($purchase->id))->not->toBeNull();
});

test('purchases index points to existing receiving notes and returns', function () {
    $scope = seedPurchaseScope();
    $purchase = createPurchaseRecord($scope, [
        'status' => 'approved',
        'invoice_no' => 'PO-WORKFLOW',
    ]);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $empty = $this->getJson('/api/purchases');
    $empty->assertSuccessful();
    expect($empty->json('data.data.0.invoice_no'))->toBe('PO-WORKFLOW')
        ->and($empty->json('data.data.0.receiving_note_id'))->toBeNull()
        ->and($empty->json('data.data.0.purchase_return_id'))->toBeNull();

    $this->postJson('/api/receiving-notes', validReceivingNotePayload($scope, $purchase))
        ->assertSuccessful();

    $note = Transaction::query()->receivingNotes()->where('parent_id', $purchase->id)->firstOrFail();

    $withNote = $this->getJson('/api/purchases');
    $withNote->assertSuccessful();
    expect($withNote->json('data.data.0.receiving_note_id'))->toBe($note->id)
        ->and($withNote->json('data.data.0.purchase_return_id'))->toBeNull();

    $this->postJson('/api/purchase-returns', validPurchaseReturnPayload($scope, $purchase->fresh(['purchaselines'])))
        ->assertSuccessful();

    $return = Transaction::query()->purchaseReturns()->where('parent_id', $purchase->id)->firstOrFail();

    $withReturn = $this->getJson('/api/purchases');
    $withReturn->assertSuccessful();
    expect($withReturn->json('data.data.0.receiving_note_id'))->toBe($note->id)
        ->and($withReturn->json('data.data.0.purchase_return_id'))->toBe($return->id);
});
