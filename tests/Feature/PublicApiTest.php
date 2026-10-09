<?php

use App\Models\ApiLog;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Product;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The array cache store (tests' CACHE_STORE) persists across tests in the same run, and the
    // public-api rate limiter is keyed by user id, which a fresh user in each test reuses across runs.
    Cache::flush();
});

/**
 * A company, its own staff user, and a plaintext API key for that user — the exact auth this new public
 * API reuses from "Settings > API Keys" (ApiKeyController), nothing new.
 */
function papiKeyFor(string $code): array
{
    $company = Company::query()->create([
        'code' => $code, 'name' => 'Public API Co '.$code, 'is_active' => true, 'max_users' => 10, 'max_branches' => 2,
    ]);
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => $company->id, 'is_active' => true]);
    $user = createStaffUserForRole($role, ['company_id' => $company->id]);
    $token = $user->createToken('test-key');

    return [$company, $user, $token->plainTextToken];
}

test('a request with no token is rejected', function () {
    $this->getJson('/api/v1/products')->assertUnauthorized();
});

test('products/customers/invoices are readable with a valid API key, scoped to its own company', function () {
    [$company, $user, $plainText] = papiKeyFor('CO-90001');
    [$otherCompany] = papiKeyFor('CO-90002');

    Product::query()->create(['company_id' => $company->id, 'name' => 'Widget', 'type' => 'single', 'sku' => 'WID-1', 'is_active' => true]);
    Product::query()->create(['company_id' => $otherCompany->id, 'name' => 'Other Co Widget', 'type' => 'single', 'sku' => 'WID-2', 'is_active' => true]);

    $contact = Contact::query()->create([
        'company_id' => $company->id, 'business_name' => 'Acme Traders',
        'first_name' => 'Acme', 'last_name' => 'Traders', 'user_type' => 'customer', 'active' => true,
        'address' => '1 Test Street', 'code' => 'CUST-1', 'type' => 'local', 'ntn_number' => '',
    ]);

    Transaction::query()->create([
        'company_id' => $company->id, 'type' => Transaction::TYPE_SELL, 'status' => 'final',
        'invoice_no' => 'INV-API-1', 'final_amount' => 500, 'contact_id' => $contact->id,
    ]);

    $this->withToken($plainText)->getJson('/api/v1/products')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Widget');

    $this->withToken($plainText)->getJson('/api/v1/customers')
        ->assertSuccessful()
        ->assertJsonPath('data.0.business_name', 'Acme Traders');

    $this->withToken($plainText)->getJson('/api/v1/invoices')
        ->assertSuccessful()
        ->assertJsonPath('data.0.invoice_no', 'INV-API-1');
});

test('a product from another company is not reachable by id', function () {
    [, , $plainText] = papiKeyFor('CO-90003');
    [$otherCompany] = papiKeyFor('CO-90004');

    $foreignProduct = Product::query()->create(['company_id' => $otherCompany->id, 'name' => 'Not Mine', 'type' => 'single', 'sku' => 'WID-3', 'is_active' => true]);

    $this->withToken($plainText)->getJson('/api/v1/products/'.$foreignProduct->id)->assertNotFound();
});

test('every public API request is recorded in the API log', function () {
    [$company, , $plainText] = papiKeyFor('CO-90005');

    $this->withToken($plainText)->getJson('/api/v1/products')->assertSuccessful();

    $log = ApiLog::query()->where('company_id', $company->id)->first();
    expect($log)->not->toBeNull()
        ->and($log->path)->toBe('api/v1/products')
        ->and($log->status_code)->toBe(200);
});

test('the public API is rate limited past 60 requests a minute', function () {
    [, , $plainText] = papiKeyFor('CO-90006');

    for ($i = 0; $i < 60; $i++) {
        $this->withToken($plainText)->getJson('/api/v1/products')->assertSuccessful();
    }

    $this->withToken($plainText)->getJson('/api/v1/products')->assertStatus(429);
});
