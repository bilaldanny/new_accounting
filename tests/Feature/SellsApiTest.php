<?php

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('sells api creates a sell with required fields and line items', function () {
    $scope = seedSellScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/sells', validSellPayload($scope))
        ->assertSuccessful();

    $sell = Transaction::query()->sells()->where('contact_id', $scope['contact_id'])->first();

    expect($sell)->not->toBeNull()
        ->and($sell->company_id)->toBe($scope['company_id'])
        ->and($sell->branch_id)->toBe($scope['branch_id'])
        ->and($sell->invoice_no)->not->toBeEmpty()
        ->and($sell->type)->toBe(Transaction::TYPE_SELL)
        ->and($sell->payment_status)->toBe('due')
        ->and($sell->status)->toBe('final')
        ->and($sell->shipping_status)->toBe('ordered')
        ->and($sell->billty_no)->toBe('BLT-100')
        ->and((float) $sell->shipping_charges)->toBe(40.0);

    $this->assertDatabaseHas('sell_lines', [
        'transaction_id' => $sell->id,
        'product_id' => $scope['product_id'],
        'variation_id' => $scope['variation_id'],
        'quantity' => 2,
    ]);
});

test('sells api rejects a sell without a customer or line items', function () {
    $scope = seedSellScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/sells', validSellPayload($scope, [
        'contact_id' => null,
        'selllines' => [],
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['contact_id', 'selllines']);
});

test('sells index returns customer name and formatted labels', function () {
    $scope = seedSellScope();
    createSellRecord($scope);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/sells');

    $response->assertSuccessful();
    expect($response->json('data.data.0.company_name'))->toBe('Purchase Test Company')
        ->and($response->json('data.data.0.branch_name'))->toBe('Purchase Branch')
        ->and($response->json('data.data.0.customer_name'))->toBe('Acme Retail')
        ->and($response->json('data.data.0.invoice_no'))->toBe('INV-00001');
});

test('sells show returns formatted line items', function () {
    $scope = seedSellScope();
    $sell = createSellRecord($scope);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/sells/'.$sell->id);

    $response->assertSuccessful();
    expect($response->json('invoice_no'))->toBe('INV-00001')
        ->and($response->json('selllines.0.product_id'))->toBe($scope['product_id'])
        ->and($response->json('selllines.0.product_name'))->toBe('Premium Basmati Rice')
        ->and($response->json('customer_name'))->toBe('Acme Retail');
});

test('sells search products returns matching catalog rows', function () {
    $scope = seedSellScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/sells/search-products?company_id='.$scope['company_id'].'&search=Basmati');

    $response->assertSuccessful();
    expect($response->json('0.product_id'))->toBe($scope['product_id'])
        ->and($response->json('0.sku'))->toBe('AS-00001-1');
});

test('sells api updates line items and can soft delete a sell', function () {
    $scope = seedSellScope();
    $sell = createSellRecord($scope);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->putJson('/api/sells/'.$sell->id, validSellPayload($scope, [
        'invoice_no' => 'INV-UPDATED',
        'shipping_charges' => 10,
        'final_amount' => 370,
        'selllines' => [
            [
                'id' => $sell->selllines()->first()->id,
                'product_id' => $scope['product_id'],
                'variation_id' => $scope['variation_id'],
                'itemtype_id' => $scope['itemtype_id'],
                'unit_id' => $scope['unit_id'],
                'quantity' => 3,
                'packing_qty' => 1,
                'unit_price' => 120,
                'discount_percent' => 0,
                'unit_price_after_discount' => 120,
                'row_subtotal' => 360,
            ],
        ],
    ]))->assertSuccessful();

    $sell->refresh();

    expect($sell->invoice_no)->toBe('INV-UPDATED')
        ->and((float) $sell->selllines()->first()->quantity)->toBe(3.0);

    $this->postJson('/api/sells/bulk_delete', [$sell->id])
        ->assertSuccessful();

    expect(Transaction::query()->find($sell->id))->toBeNull()
        ->and(Transaction::onlyTrashed()->find($sell->id))->not->toBeNull();
});

test('sells index can filter drafts and shipments with shipping status', function () {
    $scope = seedSellScope();
    createSellRecord($scope, ['invoice_no' => 'INV-DRAFT', 'status' => 'draft', 'shipping_status' => null]);
    createSellRecord($scope, ['invoice_no' => 'INV-SHIP', 'status' => 'issue', 'shipping_status' => 'packed']);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $drafts = $this->getJson('/api/sells?status=draft');
    $drafts->assertSuccessful();
    expect(collect($drafts->json('data.data'))->pluck('invoice_no')->all())->toContain('INV-DRAFT')
        ->and(collect($drafts->json('data.data'))->pluck('invoice_no')->all())->not->toContain('INV-SHIP');

    $shipments = $this->getJson('/api/sells?order_status=notnull');
    $shipments->assertSuccessful();
    expect(collect($shipments->json('data.data'))->pluck('invoice_no')->all())->toContain('INV-SHIP')
        ->and($shipments->json('data.data.0.shipping_status_label'))->toBe('Packed');
});

test('sells shipping update does not require line items', function () {
    $scope = seedSellScope();
    $sell = createSellRecord($scope, ['status' => 'issue', 'shipping_status' => 'ordered']);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->putJson('/api/sells/'.$sell->id.'/shipping', [
        'shipping_details' => 'Leave at gate',
        'shipping_address' => 'Warehouse 2',
        'shipping_status' => 'shipped',
        'delivered_to' => 'Ali',
        'shipping_note' => 'Handle with care',
    ])->assertSuccessful();

    $sell->refresh();

    expect($sell->shipping_details)->toBe('Leave at gate')
        ->and($sell->shipping_address)->toBe('Warehouse 2')
        ->and($sell->shipping_status)->toBe('shipped')
        ->and($sell->delivered_to)->toBe('Ali')
        ->and($sell->shipping_note)->toBe('Handle with care');
});

test('sells show includes invoice totals paid and previous balance', function () {
    $scope = seedSellScope();
    createSellRecord($scope, ['invoice_no' => 'INV-OLD', 'final_amount' => 80, 'payment_status' => 'due']);
    $sell = createSellRecord($scope, ['invoice_no' => 'INV-NEW', 'final_amount' => 120, 'payment_status' => 'due']);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/sells/'.$sell->id);

    $response->assertSuccessful();
    expect((float) $response->json('previous_balance'))->toBe(80.0)
        ->and((float) $response->json('paid'))->toBe(0.0)
        ->and((float) $response->json('bill_total'))->toBe(120.0);
});
