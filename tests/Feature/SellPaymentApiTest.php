<?php

use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $scope
 * @return array<string, mixed>
 */
function seedSellPaymentAccount(array $scope): array
{
    $cashId = insertPurchaseChartAccount($scope, '111-00001', 'Cash in Hand', 'dr', false);
    insertPurchaseAccountMapping($scope, 'Cash', 'cash', $cashId);

    return array_merge($scope, [
        'payment_account_id' => $cashId,
    ]);
}

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validSellPaymentPayload(array $scope, Transaction $sell, array $overrides = []): array
{
    return array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'contact_id' => $sell->contact_id,
        'transaction_id' => $sell->id,
        'amount' => 40,
        'paid_on' => '2026-09-06',
        'method' => 'cash',
        'payment_account' => $scope['payment_account_id'],
        'note' => 'Customer receipt',
    ], $overrides);
}

test('sell payments api records a payment and marks the sell partial', function () {
    $scope = seedSellPaymentAccount(seedSellScope());
    $sell = createSellRecord($scope, ['final_amount' => 100]);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/sell-payments', validSellPaymentPayload($scope, $sell))
        ->assertSuccessful();

    $this->assertDatabaseHas('payments', [
        'transaction_id' => $sell->id,
        'amount' => 40,
        'method' => 'cash',
        'contact_id' => $sell->contact_id,
    ]);

    expect($sell->fresh()->payment_status)->toBe('partial')
        ->and((float) $sell->fresh()->paid_amount)->toBe(40.0);
});

test('sell payments api marks the sell paid when the remaining balance is cleared', function () {
    $scope = seedSellPaymentAccount(seedSellScope());
    $sell = createSellRecord($scope, ['final_amount' => 100]);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/sell-payments', validSellPaymentPayload($scope, $sell, [
        'amount' => 100,
    ]))->assertSuccessful();

    expect($sell->fresh()->payment_status)->toBe('paid');
});

test('sell payments api rejects an amount greater than the remaining balance', function () {
    $scope = seedSellPaymentAccount(seedSellScope());
    $sell = createSellRecord($scope, ['final_amount' => 100]);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/sell-payments', validSellPaymentPayload($scope, $sell))
        ->assertSuccessful();

    $this->postJson('/api/sell-payments', validSellPaymentPayload($scope, $sell, [
        'amount' => 70,
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['amount']);
});

test('sell payments index returns payment rows for sells', function () {
    $scope = seedSellPaymentAccount(seedSellScope());
    $sell = createSellRecord($scope, ['final_amount' => 100, 'invoice_no' => 'INV-PAY-1']);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/sell-payments', validSellPaymentPayload($scope, $sell))
        ->assertSuccessful();

    $response = $this->getJson('/api/sell-payments');

    $response->assertSuccessful();
    expect($response->json('data.data.0.invoice_no'))->toBe('INV-PAY-1')
        ->and($response->json('data.data.0.customer_name'))->toBe('Acme Retail')
        ->and($response->json('data.data.0.method'))->toBe('cash');
});

test('sell payments show returns the payment for editing', function () {
    $scope = seedSellPaymentAccount(seedSellScope());
    $sell = createSellRecord($scope, ['final_amount' => 100]);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/sell-payments', validSellPaymentPayload($scope, $sell))
        ->assertSuccessful();

    $payment = Payment::query()->first();

    $response = $this->getJson('/api/sell-payments/'.$payment->id);

    $response->assertSuccessful()
        ->assertJsonPath('transaction_id', $sell->id)
        ->assertJsonPath('amount', '40.00');
});

test('sell payments api updates an existing payment amount', function () {
    $scope = seedSellPaymentAccount(seedSellScope());
    $sell = createSellRecord($scope, ['final_amount' => 100]);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/sell-payments', validSellPaymentPayload($scope, $sell))
        ->assertSuccessful();

    $payment = Payment::query()->first();

    $this->putJson('/api/sell-payments/'.$payment->id, validSellPaymentPayload($scope, $sell, [
        'amount' => 100,
    ]))->assertSuccessful();

    expect((float) $payment->fresh()->amount)->toBe(100.0)
        ->and($sell->fresh()->payment_status)->toBe('paid');
});

test('sell payments api deletes a payment and restores due status', function () {
    $scope = seedSellPaymentAccount(seedSellScope());
    $sell = createSellRecord($scope, ['final_amount' => 100]);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/sell-payments', validSellPaymentPayload($scope, $sell, [
        'amount' => 100,
    ]))->assertSuccessful();

    $payment = Payment::query()->first();

    $this->postJson('/api/sell-payments/bulk_delete', [$payment->id])
        ->assertSuccessful();

    $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
    expect($sell->fresh()->payment_status)->toBe('due');
});

test('sell payments eligible sells hides fully paid invoices', function () {
    $scope = seedSellPaymentAccount(seedSellScope());
    $open = createSellRecord($scope, ['final_amount' => 100, 'invoice_no' => 'INV-OPEN']);
    $paid = createSellRecord($scope, ['final_amount' => 80, 'invoice_no' => 'INV-PAID']);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/sell-payments', validSellPaymentPayload($scope, $paid, [
        'amount' => 80,
    ]))->assertSuccessful();

    $response = $this->getJson('/api/sell-payments/eligible-sells?'.http_build_query([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'contact_id' => $scope['contact_id'],
    ]));

    $response->assertSuccessful();
    $ids = collect($response->json())->pluck('id')->all();

    expect($ids)->toContain($open->id)
        ->and($ids)->not->toContain($paid->id);
});

test('sell payments cheque method requires a cheque number', function () {
    $scope = seedSellPaymentAccount(seedSellScope());
    $sell = createSellRecord($scope, ['final_amount' => 100]);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/sell-payments', validSellPaymentPayload($scope, $sell, [
        'method' => 'cheque',
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['cheque_number']);
});

test('sell payments api does not list purchase payments', function () {
    $scope = seedSellPaymentAccount(seedSellScope());
    $purchase = createPurchaseRecord($scope, ['final_amount' => 100, 'invoice_no' => 'PO-HIDDEN']);
    $sell = createSellRecord($scope, ['final_amount' => 100, 'invoice_no' => 'INV-ONLY']);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    Payment::query()->create([
        'company_id' => $purchase->company_id,
        'branch_id' => $purchase->branch_id,
        'transaction_id' => $purchase->id,
        'contact_id' => $purchase->contact_id,
        'amount' => 40,
        'method' => 'cash',
        'paid_on' => '2026-09-06 00:00',
        'payment_ref_no' => 'PP2026-09-00001',
        'payment_account' => $scope['payment_account_id'],
    ]);

    $this->postJson('/api/sell-payments', validSellPaymentPayload($scope, $sell))
        ->assertSuccessful();

    $response = $this->getJson('/api/sell-payments');

    $response->assertSuccessful();
    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.invoice_no'))->toBe('INV-ONLY');
});

test('sell payments index can filter by transaction id', function () {
    $scope = seedSellPaymentAccount(seedSellScope());
    $first = createSellRecord($scope, ['final_amount' => 100, 'invoice_no' => 'INV-ONE']);
    $second = createSellRecord($scope, ['final_amount' => 80, 'invoice_no' => 'INV-TWO']);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/sell-payments', validSellPaymentPayload($scope, $first))->assertSuccessful();
    $this->postJson('/api/sell-payments', validSellPaymentPayload($scope, $second, ['amount' => 20]))->assertSuccessful();

    $response = $this->getJson('/api/sell-payments?transaction_id='.$first->id);

    $response->assertSuccessful();
    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.invoice_no'))->toBe('INV-ONE');
});
