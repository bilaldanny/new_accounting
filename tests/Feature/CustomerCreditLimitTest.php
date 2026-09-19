<?php

use App\Models\Contact;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $scope
 */
function setCustomerCreditLimit(array $scope, int $limit): void
{
    Contact::query()->findOrFail($scope['contact_id'])->update(['credit_limit' => $limit]);
}

/**
 * The default payload is a due sale of 280 (2 x 120 + 40 shipping).
 *
 * @param  array<string, mixed>  $scope
 */
function postCreditSale(array $scope, array $overrides = []): TestResponse
{
    return test()->postJson('/api/sells', validSellPayload($scope, $overrides));
}

beforeEach(function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
});

test('a due sale within the limit is saved', function () {
    $scope = seedSellScope();
    setCustomerCreditLimit($scope, 500);

    postCreditSale($scope)->assertSuccessful();

    expect(Transaction::query()->sells()->count())->toBe(1);
});

test('a sale that would push the ledger balance past the limit is blocked with the figures', function () {
    $scope = seedSellScope();
    setCustomerCreditLimit($scope, 500);

    postCreditSale($scope)->assertSuccessful();
    $response = postCreditSale($scope)->assertUnprocessable();

    $response->assertJsonValidationErrors(['contact_id'])
        ->assertJsonPath('code', 'credit_limit_exceeded');

    expect($response->json('errors.contact_id.0'))->toContain('Acme Retail', 'Credit limit exceeded')
        ->and($response->json('credit_limit'))->toEqual(500)
        ->and($response->json('current_balance'))->toEqual(280)
        ->and($response->json('sale_amount'))->toEqual(280)
        ->and($response->json('projected_balance'))->toEqual(560)
        ->and(Transaction::query()->sells()->count())->toBe(1);
});

test('a limit of zero means no limit', function () {
    $scope = seedSellScope();
    setCustomerCreditLimit($scope, 0);

    postCreditSale($scope)->assertSuccessful();
    postCreditSale($scope)->assertSuccessful();

    expect(Transaction::query()->sells()->count())->toBe(2);
});

test('a fully paid sale never extends credit', function () {
    $scope = seedSellScope();
    setCustomerCreditLimit($scope, 100);

    postCreditSale($scope, ['payment_status' => 'paid'])->assertSuccessful();
});

test('draft and quotation sales are not checked', function (string $status) {
    $scope = seedSellScope();
    setCustomerCreditLimit($scope, 100);

    postCreditSale($scope, ['status' => $status])->assertSuccessful();
})->with(['draft', 'quotation']);

test('a partly paid sale is checked in full because payments are recorded separately', function () {
    $scope = seedSellScope();
    setCustomerCreditLimit($scope, 100);

    postCreditSale($scope, ['payment_status' => 'partial'])->assertUnprocessable();
});

test('editing a sale so it grows past the limit is blocked', function () {
    $scope = seedSellScope();
    setCustomerCreditLimit($scope, 300);

    $id = postCreditSale($scope)->assertSuccessful()->json('id');

    $this->putJson("/api/sells/{$id}", validSellPayload($scope, [
        'final_amount' => 400,
        'selllines' => [array_merge(validSellPayload($scope)['selllines'][0], ['quantity' => 3, 'row_subtotal' => 360])],
    ]))->assertUnprocessable()
        ->assertJsonPath('code', 'credit_limit_exceeded');

    expect((float) Transaction::query()->findOrFail($id)->final_amount)->toBe(280.0);
});

test('editing a sale without increasing it is allowed even when already over the limit', function () {
    $scope = seedSellScope();
    setCustomerCreditLimit($scope, 500);

    $id = postCreditSale($scope)->assertSuccessful()->json('id');
    setCustomerCreditLimit($scope, 100);

    $this->putJson("/api/sells/{$id}", validSellPayload($scope, ['additional_note' => 'Edited note']))
        ->assertSuccessful();

    expect(Transaction::query()->findOrFail($id)->additional_note)->toBe('Edited note');
});

test('moving a draft to final is checked against the limit', function () {
    $scope = seedSellScope();
    setCustomerCreditLimit($scope, 200);

    $id = postCreditSale($scope, ['status' => 'draft'])->assertSuccessful()->json('id');

    $this->postJson('/api/sells/statusupdate', ['ids' => [$id], 'status' => 'final'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'credit_limit_exceeded');

    expect(Transaction::query()->findOrFail($id)->status)->toBe('draft');

    setCustomerCreditLimit($scope, 500);

    $this->postJson('/api/sells/statusupdate', ['ids' => [$id], 'status' => 'final'])->assertSuccessful();

    expect(Transaction::query()->findOrFail($id)->status)->toBe('final');
});

test('moving a posted sale to another posted status is not an increase', function () {
    $scope = seedSellScope();
    setCustomerCreditLimit($scope, 500);

    $id = postCreditSale($scope)->assertSuccessful()->json('id');
    setCustomerCreditLimit($scope, 100);

    $this->postJson('/api/sells/statusupdate', ['ids' => [$id], 'status' => 'approved'])->assertSuccessful();

    expect(Transaction::query()->findOrFail($id)->status)->toBe('approved');
});

test('a quotation on a customer with no journals yet does not count as balance', function () {
    $scope = seedSellScope();
    setCustomerCreditLimit($scope, 500);

    createSellRecord($scope, ['status' => 'quotation', 'final_amount' => 900, 'invoice_no' => 'QT-00001']);
    createSellRecord($scope, ['status' => 'draft', 'final_amount' => 900, 'invoice_no' => 'DR-00001']);

    postCreditSale($scope)->assertSuccessful();

    expect(Transaction::query()->sells()->where('status', 'final')->count())->toBe(1);
});

test('another customers balance does not count against this customer', function () {
    $scope = seedSellScope();
    setCustomerCreditLimit($scope, 500);
    $other = createExportCustomer($scope);

    postCreditSale($scope, ['contact_id' => $other->id])->assertSuccessful();
    postCreditSale($scope)->assertSuccessful();

    expect(Transaction::query()->sells()->count())->toBe(2);
});

/**
 * A due sale of exactly $total: quantity x 120 plus shipping.
 *
 * @param  array<string, mixed>  $scope
 * @return array<string, mixed>
 */
function creditSaleOf(array $scope, int $contactId, int $quantity, int $shipping): array
{
    return [
        'contact_id' => $contactId,
        'final_amount' => $quantity * 120 + $shipping,
        'shipping_charges' => $shipping,
        'selllines' => [array_merge(validSellPayload($scope)['selllines'][0], [
            'quantity' => $quantity,
            'row_subtotal' => $quantity * 120,
        ])],
    ];
}

test('a new customer with a 1000 limit cannot be sold 1500 but can be sold 500', function () {
    $scope = seedSellScope();
    $customer = createExportCustomer($scope);
    $customer->update(['credit_limit' => 1000]);

    // 12 x 120 + 60 shipping = 1500
    $blocked = postCreditSale($scope, creditSaleOf($scope, $customer->id, 12, 60))->assertUnprocessable();

    $blocked->assertJsonPath('code', 'credit_limit_exceeded')
        ->assertJsonValidationErrors(['contact_id']);

    expect($blocked->json('credit_limit'))->toEqual(1000)
        ->and($blocked->json('current_balance'))->toEqual(0)
        ->and($blocked->json('sale_amount'))->toEqual(1500)
        ->and($blocked->json('projected_balance'))->toEqual(1500)
        ->and(Transaction::query()->sells()->where('contact_id', $customer->id)->count())->toBe(0);

    // 4 x 120 + 20 shipping = 500
    $created = postCreditSale($scope, creditSaleOf($scope, $customer->id, 4, 20))->assertSuccessful();

    expect(Transaction::query()->sells()->where('contact_id', $customer->id)->count())->toBe(1)
        ->and((float) Transaction::query()->findOrFail($created->json('id'))->final_amount)->toBe(500.0);
});

test('the limit is inclusive: a second 500 sale reaches exactly 1000 and the next rupee is refused', function () {
    $scope = seedSellScope();
    $customer = createExportCustomer($scope);
    $customer->update(['credit_limit' => 1000]);

    postCreditSale($scope, creditSaleOf($scope, $customer->id, 4, 20))->assertSuccessful();
    postCreditSale($scope, creditSaleOf($scope, $customer->id, 4, 20))->assertSuccessful();

    $refused = postCreditSale($scope, creditSaleOf($scope, $customer->id, 1, 1))->assertUnprocessable();

    expect($refused->json('code'))->toBe('credit_limit_exceeded')
        ->and($refused->json('current_balance'))->toEqual(1000)
        ->and(Transaction::query()->sells()->where('contact_id', $customer->id)->count())->toBe(2);
});
