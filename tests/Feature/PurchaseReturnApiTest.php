<?php

use App\Models\PurchaseLine;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('guests cannot access purchase returns', function () {
    $this->getJson('/api/purchase-returns')
        ->assertUnauthorized();
});

test('purchase returns api creates a return from a received purchase', function () {
    $scope = seedPurchaseScope();
    $purchase = createReceivedPurchase($scope, [
        'invoice_no' => 'PO-RECEIVED',
    ]);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchase-returns', validPurchaseReturnPayload($scope, $purchase))
        ->assertSuccessful();

    $note = Transaction::query()->purchaseReturns()->where('parent_id', $purchase->id)->first();
    $line = $purchase->purchaselines()->first();

    expect($note)->not->toBeNull()
        ->and($note->type)->toBe(Transaction::TYPE_PURCHASE_RETURN)
        ->and($note->status)->toBe('pending')
        ->and($note->payment_status)->toBe('due')
        ->and($note->invoice_no)->not->toBeEmpty()
        ->and((float) $note->final_amount)->toBe(100.0)
        ->and((float) $line->quantity_returned)->toBe(1.0);
});

test('purchase returns api rejects a pending purchase order', function () {
    $scope = seedPurchaseScope();
    $purchase = createPurchaseRecord($scope);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchase-returns', validPurchaseReturnPayload($scope, $purchase))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['transaction_id']);
});

test('purchase returns api rejects an approved purchase that has not been received', function () {
    $scope = seedPurchaseScope();
    $purchase = createPurchaseRecord($scope, ['status' => 'approved']);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchase-returns', validPurchaseReturnPayload($scope, $purchase))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['transaction_id']);
});

test('purchase returns api rejects a return quantity above the received quantity', function () {
    $scope = seedPurchaseScope();
    $purchase = createReceivedPurchase($scope);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchase-returns', validPurchaseReturnPayload($scope, $purchase, [
        'purchaselines' => [
            [
                'id' => $purchase->purchaselines()->first()->id,
                'quantity_returned' => 9,
            ],
        ],
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['purchaselines']);
});

test('purchase returns api rejects a second return for the same purchase order', function () {
    $scope = seedPurchaseScope();
    $purchase = createReceivedPurchase($scope);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchase-returns', validPurchaseReturnPayload($scope, $purchase))
        ->assertSuccessful();

    $this->postJson('/api/purchase-returns', validPurchaseReturnPayload($scope, $purchase, [
        'invoice_no' => 'PR-DUP',
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['transaction_id']);
});

test('purchase returns eligible purchases returns only received orders without a return', function () {
    $scope = seedPurchaseScope();
    createPurchaseRecord($scope, ['invoice_no' => 'PO-PENDING']);
    createPurchaseRecord($scope, [
        'invoice_no' => 'PO-APPROVED',
        'status' => 'approved',
    ]);
    $received = createReceivedPurchase($scope, ['invoice_no' => 'PO-ELIGIBLE']);
    $alreadyReturned = createReceivedPurchase($scope, ['invoice_no' => 'PO-RETURNED']);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchase-returns', validPurchaseReturnPayload($scope, $alreadyReturned))
        ->assertSuccessful();

    $response = $this->getJson('/api/purchase-returns/eligible-purchases?'.http_build_query([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'contact_id' => $scope['contact_id'],
    ]));

    $response->assertSuccessful();
    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.invoice_no'))->toBe('PO-ELIGIBLE')
        ->and($response->json('0.id'))->toBe($received->id);
});

test('purchase returns index returns the purchase order number', function () {
    $scope = seedPurchaseScope();
    $purchase = createReceivedPurchase($scope, ['invoice_no' => 'PO-00001']);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchase-returns', validPurchaseReturnPayload($scope, $purchase))
        ->assertSuccessful();

    $response = $this->getJson('/api/purchase-returns');

    $response->assertSuccessful();
    expect($response->json('data.data.0.purchase_order_no'))->toBe('PO-00001')
        ->and($response->json('data.data.0.supplier_name'))->toBe('Acme Supplies')
        ->and($response->json('data.data.0.status'))->toBe('pending')
        ->and($response->json('data.data.0.payment_status'))->toBe('due');
});

test('purchase returns show returns returned line quantities', function () {
    $scope = seedPurchaseScope();
    $purchase = createReceivedPurchase($scope);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchase-returns', validPurchaseReturnPayload($scope, $purchase))
        ->assertSuccessful();

    $note = Transaction::query()->purchaseReturns()->where('parent_id', $purchase->id)->firstOrFail();

    $response = $this->getJson('/api/purchase-returns/'.$note->id);

    $response->assertSuccessful();
    expect($response->json('transaction_id'))->toBe($purchase->id)
        ->and($response->json('purchase_order_no'))->toBe('PO-00001')
        ->and($response->json('purchaselines.0.product_name'))->toBe('Premium Basmati Rice')
        ->and((float) $response->json('purchaselines.0.quantity_received'))->toBe(1.0)
        ->and((float) $response->json('purchaselines.0.quantity_returned'))->toBe(1.0)
        ->and((float) $response->json('purchaselines.0.remaining_qty'))->toBe(0.0);
});

test('purchase returns purchase lines endpoint returns received quantities', function () {
    $scope = seedPurchaseScope();
    $purchase = createReceivedPurchase($scope);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/purchase-returns/purchase/'.$purchase->id);

    $response->assertSuccessful();
    expect($response->json('id'))->toBe($purchase->id)
        ->and($response->json('purchaselines.0.id'))->toBe($purchase->purchaselines()->first()->id)
        ->and((float) $response->json('purchaselines.0.quantity'))->toBe(1.0)
        ->and((float) $response->json('purchaselines.0.quantity_received'))->toBe(1.0);
});

test('purchase returns api can update returned quantities', function () {
    $scope = seedPurchaseScope();
    $purchase = createReceivedPurchase($scope);
    $line = $purchase->purchaselines()->first();
    $line->quantity = 4;
    $line->quantity_received = 4;
    $line->save();

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchase-returns', validPurchaseReturnPayload($scope, $purchase, [
        'purchaselines' => [
            ['id' => $line->id, 'quantity_returned' => 4],
        ],
    ]))->assertSuccessful();

    $note = Transaction::query()->purchaseReturns()->where('parent_id', $purchase->id)->firstOrFail();

    $this->putJson('/api/purchase-returns/'.$note->id, [
        'purchaselines' => [
            ['id' => $line->id, 'quantity_returned' => 2],
        ],
    ])->assertSuccessful();

    $note->refresh();

    expect((float) PurchaseLine::query()->findOrFail($line->id)->quantity_returned)->toBe(2.0)
        ->and((float) $note->final_amount)->toBe(200.0);
});

test('purchase returns api can soft delete a return and reset returned quantities', function () {
    $scope = seedPurchaseScope();
    $purchase = createReceivedPurchase($scope);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchase-returns', validPurchaseReturnPayload($scope, $purchase))
        ->assertSuccessful();

    $note = Transaction::query()->purchaseReturns()->where('parent_id', $purchase->id)->firstOrFail();

    $this->deleteJson('/api/purchase-returns/'.$note->id)
        ->assertSuccessful();

    $line = $purchase->purchaselines()->first();

    expect(Transaction::query()->find($note->id))->toBeNull()
        ->and(Transaction::onlyTrashed()->find($note->id))->not->toBeNull()
        ->and((float) $line->quantity_returned)->toBe(0.0);
});
