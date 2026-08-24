<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('guests cannot access purchase approvals', function () {
    $this->getJson('/api/purchase-approvals')
        ->assertUnauthorized();
});

test('purchase approvals index defaults to pending purchases', function () {
    $scope = seedPurchaseScope();
    createPurchaseRecord($scope, ['invoice_no' => 'PO-PENDING']);
    createPurchaseRecord($scope, [
        'invoice_no' => 'PO-APPROVED',
        'status' => 'approved',
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/purchase-approvals');

    $response->assertSuccessful();
    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.invoice_no'))->toBe('PO-PENDING')
        ->and($response->json('data.data.0.supplier_name'))->toBe('Acme Supplies')
        ->and($response->json('data.data.0.status'))->toBe('pending');
});

test('purchase approvals index can filter by date and show approved rows', function () {
    $scope = seedPurchaseScope();
    createPurchaseRecord($scope, [
        'invoice_no' => 'PO-TODAY',
        'transaction_date' => '2026-08-23 10:00:00',
    ]);
    createPurchaseRecord($scope, [
        'invoice_no' => 'PO-YESTERDAY',
        'transaction_date' => '2026-08-22 10:00:00',
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->getJson('/api/purchase-approvals?transaction_date=2026-08-23')
        ->assertSuccessful()
        ->assertJsonPath('data.data.0.invoice_no', 'PO-TODAY');

    $this->getJson('/api/purchase-approvals?status=all')
        ->assertSuccessful();
});

test('purchase approval show returns supplier company and grouped lines', function () {
    $scope = seedPurchaseScope();
    $purchase = createPurchaseRecord($scope, [
        'shipping_details' => 'Local delivery',
        'additional_note' => 'Urgent stock',
        'shipping_charges' => 25,
        'final_amount' => 125,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/purchase-approvals/'.$purchase->id);

    $response->assertSuccessful();
    expect($response->json('invoice_no'))->toBe('PO-00001')
        ->and($response->json('business_name'))->toBe('Acme Supplies')
        ->and($response->json('address'))->toBe('Test address')
        ->and($response->json('mobile'))->toBe('03001234567')
        ->and($response->json('company_name'))->toBe('Purchase Test Company')
        ->and($response->json('company_address'))->toBe('12 Warehouse Road')
        ->and($response->json('can_approve'))->toBeTrue()
        ->and($response->json('line_groups.0.itemtype_name'))->toBe('Finished Goods')
        ->and($response->json('line_groups.0.lines.0.product_name'))->toBe('Premium Basmati Rice')
        ->and($response->json('line_groups.0.lines.0.brand_name'))->toBe('Farm Fresh')
        ->and($response->json('payments'))->toBe([]);
});

test('purchase approval can approve a pending purchase', function () {
    $scope = seedPurchaseScope();
    $purchase = createPurchaseRecord($scope);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchase-approvals/'.$purchase->id.'/approve')
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Approved');

    $purchase->refresh();

    expect($purchase->status)->toBe('approved')
        ->and($purchase->approved_by)->toBe($superadmin->id)
        ->and($purchase->approved_date)->not->toBeNull();
});

test('purchase approval cannot approve a purchase that is not pending', function () {
    $scope = seedPurchaseScope();
    $purchase = createPurchaseRecord($scope, ['status' => 'approved']);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/purchase-approvals/'.$purchase->id.'/approve')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});
