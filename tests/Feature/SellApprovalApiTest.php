<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('guests cannot access sell approvals', function () {
    $this->getJson('/api/sell-approvals')
        ->assertUnauthorized();
});

test('sell approvals index defaults to final sells', function () {
    $scope = seedSellScope();
    createSellRecord($scope, ['invoice_no' => 'INV-FINAL']);
    createSellRecord($scope, [
        'invoice_no' => 'INV-APPROVED',
        'status' => 'approved',
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/sell-approvals');

    $response->assertSuccessful();
    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.invoice_no'))->toBe('INV-FINAL')
        ->and($response->json('data.data.0.customer_name'))->toBe('Acme Retail')
        ->and($response->json('data.data.0.status'))->toBe('final');
});

test('sell approvals index can filter by date and show approved rows', function () {
    $scope = seedSellScope();
    createSellRecord($scope, [
        'invoice_no' => 'INV-TODAY',
        'transaction_date' => '2026-08-26 10:00:00',
    ]);
    createSellRecord($scope, [
        'invoice_no' => 'INV-YESTERDAY',
        'transaction_date' => '2026-08-25 10:00:00',
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->getJson('/api/sell-approvals?transaction_date=2026-08-26')
        ->assertSuccessful()
        ->assertJsonPath('data.data.0.invoice_no', 'INV-TODAY');

    $this->getJson('/api/sell-approvals?status=all')
        ->assertSuccessful();
});

test('sell approval show returns customer company and grouped lines', function () {
    $scope = seedSellScope();
    $sell = createSellRecord($scope, [
        'shipping_details' => 'Local delivery',
        'additional_note' => 'Priority dispatch',
        'shipping_charges' => 40,
        'final_amount' => 160,
        'billty_no' => 'BLT-100',
        'packing' => 'Carton',
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/sell-approvals/'.$sell->id);

    $response->assertSuccessful();
    expect($response->json('invoice_no'))->toBe('INV-00001')
        ->and($response->json('business_name'))->toBe('Acme Retail')
        ->and($response->json('customer_name'))->toBe('Acme Retail')
        ->and($response->json('address'))->toBe('Customer address')
        ->and($response->json('mobile'))->toBe('03007654321')
        ->and($response->json('company_name'))->toBe('Purchase Test Company')
        ->and($response->json('company_address'))->toBe('12 Warehouse Road')
        ->and($response->json('billty_no'))->toBe('BLT-100')
        ->and($response->json('packing'))->toBe('Carton')
        ->and($response->json('can_approve'))->toBeTrue()
        ->and($response->json('line_groups.0.itemtype_name'))->toBe('Finished Goods')
        ->and($response->json('line_groups.0.lines.0.product_name'))->toBe('Premium Basmati Rice')
        ->and($response->json('line_groups.0.lines.0.brand_name'))->toBe('Farm Fresh')
        ->and($response->json('payments'))->toBe([]);
});

test('sell approval can approve a final sell', function () {
    $scope = seedSellScope();
    $sell = createSellRecord($scope);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/sell-approvals/'.$sell->id.'/approve')
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Approved');

    $sell->refresh();

    expect($sell->status)->toBe('approved')
        ->and($sell->approved_by)->toBe($superadmin->id)
        ->and($sell->approved_date)->not->toBeNull();
});

test('sell approval cannot approve a sell that is not final', function () {
    $scope = seedSellScope();
    $sell = createSellRecord($scope, ['status' => 'approved']);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/sell-approvals/'.$sell->id.'/approve')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});
