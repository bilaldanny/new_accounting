<?php

use App\Models\SellLine;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('guests cannot access sell returns', function () {
    $this->getJson('/api/sell-returns')
        ->assertUnauthorized();
});

test('sell returns api creates a return from an issued sell', function () {
    $scope = seedSellScope();
    $sell = createIssuedSell($scope, [
        'invoice_no' => 'INV-ISSUED',
    ]);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/sell-returns', validSellReturnPayload($scope, $sell))
        ->assertSuccessful();

    $note = Transaction::query()->sellReturns()->where('parent_id', $sell->id)->first();
    $line = $sell->selllines()->first();

    expect($note)->not->toBeNull()
        ->and($note->type)->toBe(Transaction::TYPE_SELL_RETURN)
        ->and($note->status)->toBe('pending')
        ->and($note->payment_status)->toBe('due')
        ->and($note->invoice_no)->not->toBeEmpty()
        ->and((float) $note->final_amount)->toBe(120.0)
        ->and((float) $line->quantity_returned)->toBe(1.0);
});

test('sell returns api rejects a pending sell invoice', function () {
    $scope = seedSellScope();
    $sell = createSellRecord($scope);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/sell-returns', validSellReturnPayload($scope, $sell))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['transaction_id']);
});

test('sell returns api rejects an approved sell that has not been issued', function () {
    $scope = seedSellScope();
    $sell = createSellRecord($scope, ['status' => 'approved']);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/sell-returns', validSellReturnPayload($scope, $sell))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['transaction_id']);
});

test('sell returns api rejects a return quantity above the issued quantity', function () {
    $scope = seedSellScope();
    $sell = createIssuedSell($scope);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/sell-returns', validSellReturnPayload($scope, $sell, [
        'selllines' => [
            [
                'id' => $sell->selllines()->first()->id,
                'quantity_returned' => 9,
            ],
        ],
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['selllines']);
});

test('sell returns api rejects a second return for the same sell invoice', function () {
    $scope = seedSellScope();
    $sell = createIssuedSell($scope);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/sell-returns', validSellReturnPayload($scope, $sell))
        ->assertSuccessful();

    $this->postJson('/api/sell-returns', validSellReturnPayload($scope, $sell, [
        'invoice_no' => 'SR-DUP',
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['transaction_id']);
});

test('sell returns eligible sells returns only issued invoices without a return', function () {
    $scope = seedSellScope();
    createSellRecord($scope, ['invoice_no' => 'INV-FINAL']);
    createSellRecord($scope, [
        'invoice_no' => 'INV-APPROVED',
        'status' => 'approved',
    ]);
    $issued = createIssuedSell($scope, ['invoice_no' => 'INV-ELIGIBLE']);
    $alreadyReturned = createIssuedSell($scope, ['invoice_no' => 'INV-RETURNED']);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/sell-returns', validSellReturnPayload($scope, $alreadyReturned))
        ->assertSuccessful();

    $response = $this->getJson('/api/sell-returns/eligible-sells?'.http_build_query([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'contact_id' => $scope['contact_id'],
    ]));

    $response->assertSuccessful();
    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.invoice_no'))->toBe('INV-ELIGIBLE')
        ->and($response->json('0.id'))->toBe($issued->id);
});

test('sell returns index returns the sell invoice number', function () {
    $scope = seedSellScope();
    $sell = createIssuedSell($scope, ['invoice_no' => 'INV-00001']);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/sell-returns', validSellReturnPayload($scope, $sell))
        ->assertSuccessful();

    $response = $this->getJson('/api/sell-returns');

    $response->assertSuccessful();
    expect($response->json('data.data.0.sell_order_no'))->toBe('INV-00001')
        ->and($response->json('data.data.0.customer_name'))->toBe('Acme Retail')
        ->and($response->json('data.data.0.status'))->toBe('pending')
        ->and($response->json('data.data.0.payment_status'))->toBe('due');
});

test('sell returns show returns returned line quantities', function () {
    $scope = seedSellScope();
    $sell = createIssuedSell($scope);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/sell-returns', validSellReturnPayload($scope, $sell))
        ->assertSuccessful();

    $note = Transaction::query()->sellReturns()->where('parent_id', $sell->id)->firstOrFail();

    $response = $this->getJson('/api/sell-returns/'.$note->id);

    $response->assertSuccessful();
    expect($response->json('transaction_id'))->toBe($sell->id)
        ->and($response->json('sell_order_no'))->toBe('INV-00001')
        ->and($response->json('selllines.0.product_name'))->toBe('Premium Basmati Rice')
        ->and((float) $response->json('selllines.0.quantity_issue'))->toBe(1.0)
        ->and((float) $response->json('selllines.0.quantity_returned'))->toBe(1.0)
        ->and((float) $response->json('selllines.0.remaining_qty'))->toBe(0.0);
});

test('sell returns sell lines endpoint returns issued quantities', function () {
    $scope = seedSellScope();
    $sell = createIssuedSell($scope);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/sell-returns/sell/'.$sell->id);

    $response->assertSuccessful();
    expect($response->json('id'))->toBe($sell->id)
        ->and($response->json('selllines.0.id'))->toBe($sell->selllines()->first()->id)
        ->and((float) $response->json('selllines.0.quantity'))->toBe(1.0)
        ->and((float) $response->json('selllines.0.quantity_issue'))->toBe(1.0);
});

test('sell returns api can update returned quantities', function () {
    $scope = seedSellScope();
    $sell = createIssuedSell($scope);
    $line = $sell->selllines()->first();
    $line->quantity = 4;
    $line->quantity_issue = 4;
    $line->save();

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/sell-returns', validSellReturnPayload($scope, $sell, [
        'selllines' => [
            ['id' => $line->id, 'quantity_returned' => 4],
        ],
    ]))->assertSuccessful();

    $note = Transaction::query()->sellReturns()->where('parent_id', $sell->id)->firstOrFail();

    $this->putJson('/api/sell-returns/'.$note->id, [
        'selllines' => [
            ['id' => $line->id, 'quantity_returned' => 2],
        ],
    ])->assertSuccessful();

    $note->refresh();

    expect((float) SellLine::query()->findOrFail($line->id)->quantity_returned)->toBe(2.0)
        ->and((float) $note->final_amount)->toBe(240.0);
});

test('sell returns api can soft delete a return and reset returned quantities', function () {
    $scope = seedSellScope();
    $sell = createIssuedSell($scope);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/sell-returns', validSellReturnPayload($scope, $sell))
        ->assertSuccessful();

    $note = Transaction::query()->sellReturns()->where('parent_id', $sell->id)->firstOrFail();

    $this->deleteJson('/api/sell-returns/'.$note->id)
        ->assertSuccessful();

    $line = $sell->selllines()->first();

    expect(Transaction::query()->find($note->id))->toBeNull()
        ->and(Transaction::onlyTrashed()->find($note->id))->not->toBeNull()
        ->and((float) $line->quantity_returned)->toBe(0.0);
});
