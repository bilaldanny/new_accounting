<?php

use App\Http\Middleware\DropArrayForScalarFields;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('an array under a single-value key is dropped on a GET, everything else is kept', function () {
    $request = Request::create('/api/x', 'GET', [
        'search' => ['a'], 'status' => ['b'], 'company_id' => [1], 'show_record' => ['5'], 'sort_by' => ['name'],
        'from_date' => ['2026-01-01'], 'page' => 2, 'ids' => [1, 2], 'product_id' => [3], 'type' => ['x'],
    ]);

    (new DropArrayForScalarFields)->handle($request, fn (Request $r) => response('ok'));

    expect($request->query())->toBe(['page' => 2, 'ids' => [1, 2], 'product_id' => [3], 'type' => ['x']]);
});

test('a scalar under those keys is untouched', function () {
    $get = Request::create('/api/x', 'GET', ['search' => 'abc', 'company_id' => '4']);

    (new DropArrayForScalarFields)->handle($get, fn (Request $r) => response('ok'));

    expect($get->query())->toBe(['search' => 'abc', 'company_id' => '4']);
});

test('on a write request the same keys are dropped from the body and the query string, other arrays stay', function () {
    $post = Request::create('/api/x?search[]=q&page=2', 'POST', [
        'name' => ['a'], 'company_id' => [1], 'status' => 'ok', 'ids' => [1, 2], 'purchaselines' => [['product_id' => 3]],
    ]);

    (new DropArrayForScalarFields)->handle($post, fn (Request $r) => response('ok'));

    expect($post->request->all())->toBe(['status' => 'ok', 'ids' => [1, 2], 'purchaselines' => [['product_id' => 3]]])
        ->and($post->query())->toBe(['page' => '2']);
});

test('a JSON body with an array name is a 422, not a server error', function (string $endpoint) {
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson($endpoint, ['name' => ['a'], 'company_id' => ['x']])->assertStatus(422);
})->with([
    'roles' => ['/api/roles'],
    'departments' => ['/api/departments'],
    'cities' => ['/api/cities'],
    'countries' => ['/api/countries'],
    'states' => ['/api/states'],
    'timezones' => ['/api/timezones'],
    'transporters' => ['/api/transporters'],
    'discounts' => ['/api/discounts'],
]);

test('status update without ids answers with an error message, not a 500', function (string $endpoint) {
    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->postJson($endpoint, []);

    expect($response->status())->toBeLessThan(500);
})->with([
    'banks status' => ['/api/banks/statusupdate'],
    'branches status' => ['/api/branches/statusupdate'],
    'customers status' => ['/api/customers/statusupdate'],
    'roles status' => ['/api/roles/statusupdate'],
    'units status' => ['/api/units/statusupdate'],
]);

test('the list endpoints answer 200 to array-valued filters instead of a 500', function (string $endpoint) {
    Sanctum::actingAs(User::query()->findOrFail(1));

    $query = 'search[]=a&status[]=b&company_id[]=1&branch_id[]=1&contact_id[]=1&show_record[]=5&cur_page[]=2&sort_by[]=name&sort_type[]=asc&from_date[]=2026-01-01&to_date[]=2026-02-01';

    $this->getJson($endpoint.'?'.$query)->assertSuccessful();
})->with([
    'transporters' => ['/api/transporters'],
    'transporters trash' => ['/api/transporters/trash'],
    'commission agents' => ['/api/commission-agents'],
    'bank issuers' => ['/api/bank-issuers'],
    'banks' => ['/api/banks'],
    'branches' => ['/api/branches'],
    'roles' => ['/api/roles'],
    'customers' => ['/api/customers'],
    'suppliers' => ['/api/suppliers'],
    'discounts' => ['/api/discounts'],
    'gift cards' => ['/api/gift-cards'],
    'loyalty' => ['/api/loyalty'],
    'stock takes' => ['/api/stock-takes'],
    'cash collections' => ['/api/cash-collections'],
    'journal entries' => ['/api/journal-entries'],
    'journal entry approvals' => ['/api/journal-entry-approvals'],
    'payment approvals' => ['/api/payment-approvals'],
    'expense approvals' => ['/api/expense-approvals'],
    'deposit approvals' => ['/api/deposit-approvals'],
    'fund transfer approvals' => ['/api/fund-transfer-approvals'],
    'purchases' => ['/api/purchases'],
    'sells' => ['/api/sells'],
    'sell returns' => ['/api/sell-returns'],
    'products' => ['/api/products'],
    'low stock' => ['/api/lowstock'],
]);

test('an id that is not a number in the url is a 404, not a server error', function (string $method, string $endpoint) {
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->json($method, $endpoint, [])->assertNotFound();
})->with([
    'bank show' => ['GET', '/api/banks/abc'],
    'bank update' => ['PUT', '/api/banks/abc'],
    'bank delete' => ['DELETE', '/api/banks/abc'],
    'city show' => ['GET', '/api/cities/abc'],
    'customer show' => ['GET', '/api/customers/abc'],
    'sell payment show' => ['GET', '/api/sell-payments/abc'],
    'tax show' => ['GET', '/api/taxes/abc'],
    'approval show' => ['GET', '/api/journal-entry-approvals/abc'],
    'stock take show' => ['GET', '/api/stock-takes/abc'],
]);
