<?php

use App\Models\Company;
use App\Models\Tax;
use App\Models\TaxExemption;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
});

function teTax(int $companyId, string $name, float $rate): int
{
    return Tax::query()->create(['company_id' => $companyId, 'name' => $name, 'percentage' => $rate, 'type' => 0, 'status' => true])->id;
}

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $attributes
 */
function teRule(array $scope, array $attributes): TaxExemption
{
    return TaxExemption::query()->create(array_merge(['company_id' => $scope['company_id'], 'name' => 'Rule', 'scope' => 'customer', 'contact_id' => $scope['contact_id'], 'is_active' => true], $attributes));
}

/**
 * @param  array<string, mixed>  $scope
 */
function teSell(array $scope, int $taxId, array $overrides = []): Transaction
{
    $payload = validSellPayload($scope, $overrides);
    $payload['selllines'][0]['tax_id'] = $taxId;
    test()->postJson('/api/sells', $payload)->assertSuccessful();

    return Transaction::query()->where('type', 'sell')->latest('id')->firstOrFail()->load('selllines');
}

test('a customer exemption takes the customer out of the tax and the line keeps the rule', function () {
    $scope = seedSellScope();
    $tax = teTax($scope['company_id'], 'GST 10', 10);
    $rule = teRule($scope, ['name' => 'Diplomatic', 'certificate_no' => 'EX-1']);

    $sell = teSell($scope, $tax);

    expect((float) $sell->tax_amount)->toBe(0.0)
        ->and((float) $sell->final_amount)->toBe(280.0)
        ->and($sell->selllines->first()->tax_id)->toBe($tax)
        ->and((float) $sell->selllines->first()->tax_amount)->toBe(0.0)
        ->and($sell->selllines->first()->tax_exemption_id)->toBe($rule->id);
});

test('an item exemption takes only that item out and a rule for one tax leaves the others alone', function () {
    $scope = seedSellScope();
    $gst = teTax($scope['company_id'], 'GST 10', 10);
    $other = teTax($scope['company_id'], 'Levy 5', 5);
    teRule($scope, ['scope' => 'item', 'contact_id' => null, 'product_id' => $scope['product_id'], 'tax_id' => $gst]);

    expect((float) teSell($scope, $gst)->tax_amount)->toBe(0.0)
        ->and((float) teSell($scope, $other)->tax_amount)->toBe(12.0);
});

test('an inactive rule or one outside its dates exempts nothing', function () {
    $scope = seedSellScope();
    $tax = teTax($scope['company_id'], 'GST 10', 10);
    $inactive = teRule($scope, ['is_active' => false]);
    $expired = teRule($scope, ['valid_to' => '2026-08-01']);
    $future = teRule($scope, ['valid_from' => '2026-09-01']);

    expect((float) teSell($scope, $tax)->tax_amount)->toBe(24.0);

    // The rule that covers the sale date applies.
    $expired->update(['valid_to' => '2026-08-31']);
    expect((float) teSell($scope, $tax)->tax_amount)->toBe(0.0);

    expect([$inactive->is_active, $future->valid_from->toDateString()])->toBe([false, '2026-09-01']);
});

test('a purchase from an exempt supplier is exempt too', function () {
    $scope = seedPurchaseScope();
    $tax = teTax($scope['company_id'], 'GST 10', 10);
    teRule($scope, []);
    $payload = validPurchasePayload($scope);
    $payload['purchaselines'][0]['tax_id'] = $tax;

    $this->postJson('/api/purchases', $payload)->assertSuccessful();

    $purchase = Transaction::query()->where('type', 'purchaseorder')->latest('id')->firstOrFail();
    expect((float) $purchase->tax_amount)->toBe(0.0)->and((float) $purchase->final_amount)->toBe(250.0);
});

test('exemptions are managed through the api and checked against the company', function () {
    $scope = seedSellScope();
    $tax = teTax($scope['company_id'], 'GST 10', 10);
    $other = Company::query()->create(['code' => 'CO-TE-02', 'name' => 'Other', 'is_active' => true, 'max_users' => 5, 'max_branches' => 1]);
    $foreignTax = teTax($other->id, 'Foreign', 5);

    $this->postJson('/api/tax-exemptions', ['company_id' => $scope['company_id'], 'name' => 'Customer rule', 'scope' => 'customer', 'contact_id' => $scope['contact_id'], 'tax_id' => $tax, 'valid_from' => '2026-01-01', 'valid_to' => '2026-12-31'])
        ->assertSuccessful()->assertJsonPath('data.contact_name', 'Acme Retail')->assertJsonPath('data.tax_name', 'GST 10');

    $id = TaxExemption::query()->latest('id')->value('id');
    $this->putJson("/api/tax-exemptions/{$id}", ['name' => 'Renamed', 'scope' => 'item', 'product_id' => $scope['product_id']])->assertSuccessful();
    expect(TaxExemption::query()->find($id)->contact_id)->toBeNull();

    $this->getJson('/api/tax-exemptions?scope=item')->assertJsonPath('data.total', 1);
    $this->getJson('/api/tax-exemptions?scope=customer')->assertJsonPath('data.total', 0);

    // A scope needs its own target, a foreign tax is refused, the end cannot come before the start.
    $this->postJson('/api/tax-exemptions', ['company_id' => $scope['company_id'], 'name' => 'x', 'scope' => 'item'])->assertUnprocessable()->assertJsonValidationErrors(['product_id']);
    $this->postJson('/api/tax-exemptions', ['company_id' => $scope['company_id'], 'name' => 'x', 'scope' => 'customer', 'contact_id' => $scope['contact_id'], 'tax_id' => $foreignTax])->assertUnprocessable()->assertJsonValidationErrors(['tax_id']);
    $this->postJson('/api/tax-exemptions', ['company_id' => $scope['company_id'], 'name' => 'x', 'scope' => 'customer', 'contact_id' => $scope['contact_id'], 'valid_from' => '2026-05-01', 'valid_to' => '2026-04-01'])->assertUnprocessable()->assertJsonValidationErrors(['valid_to']);

    $this->deleteJson("/api/tax-exemptions/{$id}")->assertSuccessful();
    expect(TaxExemption::query()->count())->toBe(0);
});

test('a rule that exempted a sale cannot be deleted, only switched off', function () {
    $scope = seedSellScope();
    $tax = teTax($scope['company_id'], 'GST 10', 10);
    $rule = teRule($scope, []);
    teSell($scope, $tax);

    $this->deleteJson("/api/tax-exemptions/{$rule->id}")->assertUnprocessable()->assertJsonValidationErrors(['exemption']);
    $this->getJson("/api/tax-exemptions/{$rule->id}")->assertJsonPath('data.used', true);
    $this->putJson("/api/tax-exemptions/{$rule->id}", ['name' => 'Rule', 'scope' => 'customer', 'contact_id' => $scope['contact_id'], 'is_active' => false])->assertSuccessful();
    expect((float) teSell($scope, $tax)->tax_amount)->toBe(24.0);
});
