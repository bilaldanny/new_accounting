<?php

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\Tax;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
});

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function trpCustomer(array $scope, array $overrides = []): array
{
    return array_merge([
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'business_name' => 'New Customer Co', 'first_name' => 'Jane',
        'mobile' => '03007654321', 'user_type' => 'customer', 'type' => 'local', 'pay_type' => 'day', 'active' => true,
        'address' => '123 Customer Street', 'ntn_number' => '1234567-8',
    ], $overrides);
}

function trpSale(array $scope): Transaction
{
    test()->postJson('/api/sells', validSellPayload($scope))->assertSuccessful();

    return Transaction::query()->where('type', 'sell')->latest('id')->firstOrFail();
}

test('the invoice carries the NTN and STRN of the company and the customer, unless Invoice Settings switch them off', function () {
    $scope = seedSellScope();
    Company::query()->whereKey($scope['company_id'])->update(['ntn_no' => '1111111-1', 'strn_no' => 'S-2222']);
    Contact::query()->whereKey($scope['contact_id'])->update(['ntn_number' => '7654321', 'strn_number' => 'C-3333']);
    $sale = trpSale($scope);

    $this->getJson("/api/sells/{$sale->id}")->assertSuccessful()
        ->assertJsonPath('print_settings.tax_numbers', ['company_ntn' => '1111111-1', 'company_strn' => 'S-2222', 'customer_ntn' => '7654321', 'customer_strn' => 'C-3333'])
        ->assertJsonPath('print_settings.tax_breakdown', true);

    $this->putJson('/api/document-settings/invoice', ['company_id' => $scope['company_id'], 'show_tax_numbers' => false, 'show_tax_breakdown' => false])->assertSuccessful();

    $this->getJson("/api/sells/{$sale->id}")
        ->assertJsonPath('print_settings.tax_numbers', null)
        ->assertJsonPath('print_settings.tax_breakdown', false);
});

test('a number that is not filled in is left out, not printed empty', function () {
    $scope = seedSellScope();
    Company::query()->whereKey($scope['company_id'])->update(['ntn_no' => '1111111-1', 'strn_no' => null]);
    Contact::query()->whereKey($scope['contact_id'])->update(['strn_number' => '  ']);
    $sale = trpSale($scope);

    $this->getJson("/api/sells/{$sale->id}")->assertJsonPath('print_settings.tax_numbers.company_strn', null)->assertJsonPath('print_settings.tax_numbers.customer_strn', null)->assertJsonPath('print_settings.tax_numbers.company_ntn', '1111111-1');
});

test('the invoice payload carries the tax of the sale and of every line', function () {
    $scope = seedSellScope();
    $tax = Tax::query()->create(['company_id' => $scope['company_id'], 'name' => 'GST 10', 'percentage' => 10, 'type' => 0, 'status' => true])->id;
    $payload = validSellPayload($scope, ['withholding_tax_id' => null]);
    $payload['selllines'][0]['tax_id'] = $tax;
    $this->postJson('/api/sells', $payload)->assertSuccessful();
    $sale = Transaction::query()->where('type', 'sell')->latest('id')->firstOrFail();

    $shown = $this->getJson("/api/sells/{$sale->id}")->assertSuccessful()->json();
    expect((float) $shown['tax_amount'])->toBe(24.0)
        ->and($shown['tax_inclusive'])->toBeFalse()
        ->and((float) $shown['selllines'][0]['tax_amount'])->toBe(24.0);
});

test('a customer or supplier can be saved with an STRN, and without one', function () {
    $scope = seedSellScope();

    $this->postJson('/api/customers', trpCustomer($scope, ['strn_number' => 'STRN-77']))->assertOk();
    expect(Contact::query()->where('business_name', 'New Customer Co')->value('strn_number'))->toBe('STRN-77');

    $this->postJson('/api/customers', trpCustomer($scope, ['business_name' => 'No STRN Co', 'mobile' => '03007654322']))->assertOk();
    expect(Contact::query()->where('business_name', 'No STRN Co')->value('strn_number'))->toBeNull();
});

test('the company default for tax-inclusive prices is saved with the tax settings and reaches the settings payload', function () {
    $scope = seedSellScope();
    CompanySetting::query()->where('company_id', $scope['company_id'])->exists() || CompanySetting::createCompanySettings($scope['company_id'], 'X');

    $this->getJson('/api/fbr-settings?company_id='.$scope['company_id'])->assertJsonPath('data.tax_inclusive_pricing', false);

    $this->putJson('/api/fbr-settings', ['company_id' => $scope['company_id'], 'enabled' => false, 'environment' => 'sandbox', 'tax_inclusive_pricing' => true])
        ->assertSuccessful()->assertJsonPath('data.tax_inclusive_pricing', true);

    expect(CompanySetting::formatForResponse(CompanySetting::query()->where('company_id', $scope['company_id'])->firstOrFail(), Company::query()->findOrFail($scope['company_id']))['tax_inclusive_pricing'])->toBeTrue();
});
