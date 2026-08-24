<?php

use App\Models\PurchaseLine;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('guests cannot access receiving notes', function () {
    $this->getJson('/api/receiving-notes')
        ->assertUnauthorized();
});

test('receiving notes api creates a goods receipt from an approved purchase', function () {
    $scope = seedPurchaseScope();
    $purchase = createPurchaseRecord($scope, [
        'status' => 'approved',
        'invoice_no' => 'PO-APPROVED',
    ]);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/receiving-notes', validReceivingNotePayload($scope, $purchase))
        ->assertSuccessful();

    $note = Transaction::query()->receivingNotes()->where('parent_id', $purchase->id)->first();
    $purchase->refresh();
    $line = $purchase->purchaselines()->first();

    expect($note)->not->toBeNull()
        ->and($note->type)->toBe(Transaction::TYPE_RECEIVING_NOTE)
        ->and($note->status)->toBe('received')
        ->and($note->invoice_no)->not->toBeEmpty()
        ->and($purchase->status)->toBe('received')
        ->and((float) $line->quantity_received)->toBe(1.0);
});

test('receiving notes api rejects a pending purchase order', function () {
    $scope = seedPurchaseScope();
    $purchase = createPurchaseRecord($scope);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/receiving-notes', validReceivingNotePayload($scope, $purchase))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['transaction_id']);
});

test('receiving notes api rejects received quantity above the purchased quantity', function () {
    $scope = seedPurchaseScope();
    $purchase = createPurchaseRecord($scope, ['status' => 'approved']);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/receiving-notes', validReceivingNotePayload($scope, $purchase, [
        'purchaselines' => [
            [
                'id' => $purchase->purchaselines()->first()->id,
                'quantity_received' => 9,
            ],
        ],
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['purchaselines']);
});

test('receiving notes eligible purchases returns only approved orders without a grn', function () {
    $scope = seedPurchaseScope();
    createPurchaseRecord($scope, ['invoice_no' => 'PO-PENDING']);
    $approved = createPurchaseRecord($scope, [
        'invoice_no' => 'PO-ELIGIBLE',
        'status' => 'approved',
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/receiving-notes/eligible-purchases?'.http_build_query([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'contact_id' => $scope['contact_id'],
    ]));

    $response->assertSuccessful();
    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.invoice_no'))->toBe('PO-ELIGIBLE')
        ->and($response->json('0.id'))->toBe($approved->id);
});

test('receiving notes index returns the purchase order number', function () {
    $scope = seedPurchaseScope();
    $purchase = createPurchaseRecord($scope, [
        'status' => 'approved',
        'invoice_no' => 'PO-00001',
    ]);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/receiving-notes', validReceivingNotePayload($scope, $purchase))
        ->assertSuccessful();

    $response = $this->getJson('/api/receiving-notes');

    $response->assertSuccessful();
    expect($response->json('data.data.0.purchase_order_no'))->toBe('PO-00001')
        ->and($response->json('data.data.0.supplier_name'))->toBe('Acme Supplies')
        ->and($response->json('data.data.0.status'))->toBe('received');
});

test('receiving notes show returns received line quantities', function () {
    $scope = seedPurchaseScope();
    $purchase = createPurchaseRecord($scope, ['status' => 'approved']);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/receiving-notes', validReceivingNotePayload($scope, $purchase))
        ->assertSuccessful();

    $note = Transaction::query()->receivingNotes()->where('parent_id', $purchase->id)->firstOrFail();

    $response = $this->getJson('/api/receiving-notes/'.$note->id);

    $response->assertSuccessful();
    expect($response->json('transaction_id'))->toBe($purchase->id)
        ->and($response->json('purchase_order_no'))->toBe('PO-00001')
        ->and($response->json('purchaselines.0.product_name'))->toBe('Premium Basmati Rice')
        ->and((float) $response->json('purchaselines.0.quantity_received'))->toBe(1.0);
});

test('receiving notes purchase lines endpoint returns ordered quantities', function () {
    $scope = seedPurchaseScope();
    $purchase = createPurchaseRecord($scope, ['status' => 'approved']);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/receiving-notes/purchase/'.$purchase->id);

    $response->assertSuccessful();
    expect($response->json('id'))->toBe($purchase->id)
        ->and($response->json('purchaselines.0.id'))->toBe($purchase->purchaselines()->first()->id)
        ->and((float) $response->json('purchaselines.0.quantity'))->toBe(1.0);
});

test('receiving notes api can update received quantities', function () {
    $scope = seedPurchaseScope();
    $purchase = createPurchaseRecord($scope, ['status' => 'approved']);
    $line = $purchase->purchaselines()->first();
    $line->quantity = 4;
    $line->save();

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/receiving-notes', validReceivingNotePayload($scope, $purchase, [
        'purchaselines' => [
            ['id' => $line->id, 'quantity_received' => 4],
        ],
    ]))->assertSuccessful();

    $note = Transaction::query()->receivingNotes()->where('parent_id', $purchase->id)->firstOrFail();

    $this->putJson('/api/receiving-notes/'.$note->id, [
        'purchaselines' => [
            ['id' => $line->id, 'quantity_received' => 2],
        ],
    ])->assertSuccessful();

    expect((float) PurchaseLine::query()->findOrFail($line->id)->quantity_received)->toBe(2.0);
});

test('receiving notes api can soft delete a note and restore the purchase', function () {
    $scope = seedPurchaseScope();
    $purchase = createPurchaseRecord($scope, ['status' => 'approved']);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/receiving-notes', validReceivingNotePayload($scope, $purchase))
        ->assertSuccessful();

    $note = Transaction::query()->receivingNotes()->where('parent_id', $purchase->id)->firstOrFail();

    $this->deleteJson('/api/receiving-notes/'.$note->id)
        ->assertSuccessful();

    $purchase->refresh();
    $line = $purchase->purchaselines()->first();

    expect(Transaction::query()->find($note->id))->toBeNull()
        ->and(Transaction::onlyTrashed()->find($note->id))->not->toBeNull()
        ->and($purchase->status)->toBe('approved')
        ->and((float) $line->quantity_received)->toBe(0.0);
});
