<?php

use App\Models\Payment;
use App\Models\TAccount;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $scope
 * @return array<string, mixed>
 */
function seedPurchasePaymentAccount(array $scope): array
{
    $cashId = insertPurchaseChartAccount($scope, '101-00001', 'Cash in Hand', 'dr', false);
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
function validPurchasePaymentPayload(array $scope, Transaction $purchase, array $overrides = []): array
{
    return array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'contact_id' => $purchase->contact_id,
        'transaction_id' => $purchase->id,
        'amount' => 40,
        'paid_on' => '2026-09-06',
        'method' => 'cash',
        'payment_account' => $scope['payment_account_id'],
        'note' => 'Supplier advance',
    ], $overrides);
}

test('purchase payments api records a payment and marks the purchase partial', function () {
    $scope = seedPurchasePaymentAccount(seedPurchaseScope());
    $purchase = createPurchaseRecord($scope, ['final_amount' => 100]);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchase-payments', validPurchasePaymentPayload($scope, $purchase))
        ->assertSuccessful();

    $this->assertDatabaseHas('payments', [
        'transaction_id' => $purchase->id,
        'amount' => 40,
        'method' => 'cash',
        'contact_id' => $purchase->contact_id,
    ]);

    expect($purchase->fresh()->payment_status)->toBe('partial')
        ->and((float) $purchase->fresh()->paid_amount)->toBe(40.0);
});

test('purchase payments api marks the purchase paid when the remaining balance is cleared', function () {
    $scope = seedPurchasePaymentAccount(seedPurchaseScope());
    $purchase = createPurchaseRecord($scope, ['final_amount' => 100]);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchase-payments', validPurchasePaymentPayload($scope, $purchase, [
        'amount' => 100,
    ]))->assertSuccessful();

    expect($purchase->fresh()->payment_status)->toBe('paid');
});

test('purchase payments api rejects an amount greater than the remaining balance', function () {
    $scope = seedPurchasePaymentAccount(seedPurchaseScope());
    $purchase = createPurchaseRecord($scope, ['final_amount' => 100]);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchase-payments', validPurchasePaymentPayload($scope, $purchase))
        ->assertSuccessful();

    $this->postJson('/api/purchase-payments', validPurchasePaymentPayload($scope, $purchase, [
        'amount' => 70,
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['amount']);
});

test('purchase payments index returns payment rows for purchases', function () {
    $scope = seedPurchasePaymentAccount(seedPurchaseScope());
    $purchase = createPurchaseRecord($scope, ['final_amount' => 100, 'invoice_no' => 'PO-PAY-1']);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchase-payments', validPurchasePaymentPayload($scope, $purchase))
        ->assertSuccessful();

    $response = $this->getJson('/api/purchase-payments');

    $response->assertSuccessful();
    expect($response->json('data.data.0.invoice_no'))->toBe('PO-PAY-1')
        ->and($response->json('data.data.0.supplier_name'))->toBe('Acme Supplies')
        ->and($response->json('data.data.0.method'))->toBe('cash');
});

test('purchase payments show returns the payment for editing', function () {
    $scope = seedPurchasePaymentAccount(seedPurchaseScope());
    $purchase = createPurchaseRecord($scope, ['final_amount' => 100]);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchase-payments', validPurchasePaymentPayload($scope, $purchase))
        ->assertSuccessful();

    $payment = Payment::query()->first();

    $response = $this->getJson('/api/purchase-payments/'.$payment->id);

    $response->assertSuccessful()
        ->assertJsonPath('transaction_id', $purchase->id)
        ->assertJsonPath('amount', '40.00');
});

test('purchase payments api updates an existing payment amount', function () {
    $scope = seedPurchasePaymentAccount(seedPurchaseScope());
    $purchase = createPurchaseRecord($scope, ['final_amount' => 100]);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchase-payments', validPurchasePaymentPayload($scope, $purchase))
        ->assertSuccessful();

    $payment = Payment::query()->first();

    $this->putJson('/api/purchase-payments/'.$payment->id, validPurchasePaymentPayload($scope, $purchase, [
        'amount' => 100,
    ]))->assertSuccessful();

    expect((float) $payment->fresh()->amount)->toBe(100.0)
        ->and($purchase->fresh()->payment_status)->toBe('paid');
});

test('purchase payments api deletes a payment and restores due status', function () {
    $scope = seedPurchasePaymentAccount(seedPurchaseScope());
    $purchase = createPurchaseRecord($scope, ['final_amount' => 100]);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchase-payments', validPurchasePaymentPayload($scope, $purchase, [
        'amount' => 100,
    ]))->assertSuccessful();

    $payment = Payment::query()->first();

    $this->postJson('/api/purchase-payments/bulk_delete', [$payment->id])
        ->assertSuccessful();

    $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
    expect($purchase->fresh()->payment_status)->toBe('due');
});

test('purchase payments eligible purchases hides fully paid orders', function () {
    $scope = seedPurchasePaymentAccount(seedPurchaseScope());
    $open = createPurchaseRecord($scope, ['final_amount' => 100, 'invoice_no' => 'PO-OPEN']);
    $paid = createPurchaseRecord($scope, ['final_amount' => 80, 'invoice_no' => 'PO-PAID']);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchase-payments', validPurchasePaymentPayload($scope, $paid, [
        'amount' => 80,
    ]))->assertSuccessful();

    $response = $this->getJson('/api/purchase-payments/eligible-purchases?'.http_build_query([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'contact_id' => $scope['contact_id'],
    ]));

    $response->assertSuccessful();
    $ids = collect($response->json())->pluck('id')->all();

    expect($ids)->toContain($open->id)
        ->and($ids)->not->toContain($paid->id);
});

test('purchase payments cheque method requires a cheque number', function () {
    $scope = seedPurchasePaymentAccount(seedPurchaseScope());
    $purchase = createPurchaseRecord($scope, ['final_amount' => 100]);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchase-payments', validPurchasePaymentPayload($scope, $purchase, [
        'method' => 'cheque',
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['cheque_number']);
});

test('purchase payments api posts a balanced ledger voucher debiting the supplier and crediting cash', function () {
    $scope = seedPurchasePaymentAccount(seedPurchaseScope());
    $purchase = createPurchaseRecord($scope, ['final_amount' => 100]);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchase-payments', validPurchasePaymentPayload($scope, $purchase))
        ->assertSuccessful();

    $payment = Payment::query()->first();
    expect($payment->t_account_id)->not->toBeNull();

    $journal = TAccount::query()->with('details')->findOrFail($payment->t_account_id);

    expect($journal->voucher_no)->toStartWith('PP-')
        ->and((float) $journal->total_amount)->toBe(40.0)
        ->and($journal->details)->toHaveCount(2);

    $debit = $journal->details->firstWhere('coa_id', $scope['supplier_coa_id']);
    $credit = $journal->details->firstWhere('coa_id', $scope['payment_account_id']);

    expect((float) $debit->debit)->toBe(40.0)
        ->and((float) $debit->credit)->toBe(0.0)
        ->and((float) $credit->credit)->toBe(40.0)
        ->and((float) $credit->debit)->toBe(0.0);

    $totalDebit = (float) $journal->details->sum('debit');
    $totalCredit = (float) $journal->details->sum('credit');
    expect($totalDebit)->toBe($totalCredit);
});

test('purchase payments api rewrites the ledger voucher when the payment amount is updated', function () {
    $scope = seedPurchasePaymentAccount(seedPurchaseScope());
    $purchase = createPurchaseRecord($scope, ['final_amount' => 100]);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchase-payments', validPurchasePaymentPayload($scope, $purchase))
        ->assertSuccessful();

    $payment = Payment::query()->first();
    $originalTAccountId = $payment->t_account_id;

    $this->putJson('/api/purchase-payments/'.$payment->id, validPurchasePaymentPayload($scope, $purchase, [
        'amount' => 100,
    ]))->assertSuccessful();

    expect(TAccount::query()->count())->toBe(1);

    $journal = TAccount::query()->with('details')->findOrFail($originalTAccountId);

    expect((float) $journal->total_amount)->toBe(100.0)
        ->and((float) $journal->details->sum('debit'))->toBe(100.0)
        ->and((float) $journal->details->sum('credit'))->toBe(100.0);
});

test('purchase payments api removes the ledger voucher when the payment is deleted', function () {
    $scope = seedPurchasePaymentAccount(seedPurchaseScope());
    $purchase = createPurchaseRecord($scope, ['final_amount' => 100]);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchase-payments', validPurchasePaymentPayload($scope, $purchase))
        ->assertSuccessful();

    $payment = Payment::query()->first();
    $tAccountId = $payment->t_account_id;

    $this->postJson('/api/purchase-payments/bulk_delete', [$payment->id])->assertSuccessful();

    $this->assertDatabaseMissing('t_accounts', ['id' => $tAccountId]);
    $this->assertDatabaseMissing('t_account_details', ['t_account_id' => $tAccountId]);
});

test('purchase payments index can filter by transaction id', function () {
    $scope = seedPurchasePaymentAccount(seedPurchaseScope());
    $first = createPurchaseRecord($scope, ['final_amount' => 100, 'invoice_no' => 'PO-ONE']);
    $second = createPurchaseRecord($scope, ['final_amount' => 80, 'invoice_no' => 'PO-TWO']);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchase-payments', validPurchasePaymentPayload($scope, $first))->assertSuccessful();
    $this->postJson('/api/purchase-payments', validPurchasePaymentPayload($scope, $second, ['amount' => 20]))->assertSuccessful();

    $response = $this->getJson('/api/purchase-payments?transaction_id='.$first->id);

    $response->assertSuccessful();
    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.invoice_no'))->toBe('PO-ONE');
});
