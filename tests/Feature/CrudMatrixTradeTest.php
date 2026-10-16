<?php

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The trading documents through their API the same way: add, validation, view, edit, delete, and the document of another company
 * out of reach. Sales, purchases, sales returns and purchase returns.
 */
test('a sale is added, validated, viewed, edited and deleted', function () {
    $scope = seedSellScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/sells', validSellPayload($scope, ['contact_id' => null]))->assertUnprocessable();
    $this->postJson('/api/sells', validSellPayload($scope, ['selllines' => []]))->assertUnprocessable();
    $this->postJson('/api/sells', validSellPayload($scope, ['invoice_no' => 'CRUD-S1']))->assertSuccessful();

    $sale = Transaction::query()->where('company_id', $scope['company_id'])->where('type', Transaction::TYPE_SELL)->latest('id')->firstOrFail();
    $this->getJson("/api/sells/{$sale->id}")->assertSuccessful();

    $this->putJson("/api/sells/{$sale->id}", validSellPayload($scope, ['additional_note' => 'Edited by the matrix']))->assertSuccessful();
    expect($sale->fresh()->additional_note)->toBe('Edited by the matrix');

    $this->deleteJson("/api/sells/{$sale->id}")->assertSuccessful();
    expect(Transaction::query()->find($sale->id))->toBeNull();
});

test('a purchase is added, validated, viewed, edited and deleted', function () {
    $scope = seedPurchaseScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/purchases', validPurchasePayload($scope, ['contact_id' => null]))->assertUnprocessable();
    $this->postJson('/api/purchases', validPurchasePayload($scope, ['purchaselines' => []]))->assertUnprocessable();
    $this->postJson('/api/purchases', validPurchasePayload($scope, ['invoice_no' => 'CRUD-P1']))->assertSuccessful();

    $purchase = Transaction::query()->where('company_id', $scope['company_id'])->where('type', Transaction::TYPE_PURCHASE)->latest('id')->firstOrFail();
    $this->getJson("/api/purchases/{$purchase->id}")->assertSuccessful();

    $this->putJson("/api/purchases/{$purchase->id}", validPurchasePayload($scope, ['invoice_no' => 'CRUD-P1', 'additional_note' => 'Edited by the matrix']))->assertSuccessful();
    expect($purchase->fresh()->additional_note)->toBe('Edited by the matrix');

    $this->deleteJson("/api/purchases/{$purchase->id}")->assertSuccessful();
    expect(Transaction::query()->find($purchase->id))->toBeNull();
});

test('a sale of one company is not found, edited or deleted from another', function () {
    $scope = seedSellScope();
    $sale = createSellRecord($scope, ['invoice_no' => 'CRUD-S2']);
    $other = fcoOtherCompany('trade');

    Sanctum::actingAs(fcoStaff($other, ['/sell', '/sell/:id/view', '/sell/:id/edit', '/sell/delete'], false));
    $this->getJson("/api/sells/{$sale->id}")->assertNotFound();
    $this->putJson("/api/sells/{$sale->id}", validSellPayload($scope))->assertStatus(404);
    $this->deleteJson("/api/sells/{$sale->id}")->assertNotFound();
});

test('a sales return is added against its sale, viewed and deleted', function () {
    $scope = seedSellScope();
    Sanctum::actingAs(User::query()->findOrFail(1));
    $sale = createIssuedSell($scope, ['invoice_no' => 'CRUD-R1']);

    $this->postJson('/api/sell-returns', validSellReturnPayload($scope, $sale, ['selllines' => []]))->assertUnprocessable();
    $this->postJson('/api/sell-returns', validSellReturnPayload($scope, $sale))->assertSuccessful();
    $return = Transaction::query()->where('company_id', $scope['company_id'])->where('type', Transaction::TYPE_SELL_RETURN)->latest('id')->firstOrFail();
    $this->getJson("/api/sell-returns/{$return->id}")->assertSuccessful();
    $this->deleteJson("/api/sell-returns/{$return->id}")->assertSuccessful();
});

test('a purchase return is added against its purchase, viewed and deleted', function () {
    $scope = seedPurchaseScope();
    Sanctum::actingAs(User::query()->findOrFail(1));
    $purchase = createReceivedPurchase($scope, ['invoice_no' => 'CRUD-R2']);

    $this->postJson('/api/purchase-returns', validPurchaseReturnPayload($scope, $purchase))->assertSuccessful();
    $return = Transaction::query()->where('company_id', $scope['company_id'])->where('type', Transaction::TYPE_PURCHASE_RETURN)->latest('id')->firstOrFail();
    $this->getJson("/api/purchase-returns/{$return->id}")->assertSuccessful();
    $this->deleteJson("/api/purchase-returns/{$return->id}")->assertSuccessful();
});
