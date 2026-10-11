<?php

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * A scope whose customer has a 500 limit; the default sale payload adds 280, so the second sale breaches it.
 *
 * @return array<string, mixed>
 */
function clmScope(?string $mode = null): array
{
    $scope = seedSellScope();
    Contact::query()->findOrFail($scope['contact_id'])->update(['credit_limit' => 500]);

    if ($mode !== null) {
        $setting = CompanySetting::query()->firstOrCreate(['company_id' => $scope['company_id']], CompanySetting::defaultSettingsAttributes('Test'));
        $setting->update(['credit_limit_mode' => $mode]);
    }

    return $scope;
}

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $overrides
 */
function clmSale(array $scope, array $overrides = []): TestResponse
{
    return test()->postJson('/api/sells', validSellPayload($scope, $overrides));
}

beforeEach(function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
});

test('a company that never set the mode keeps the hard stop, with the response it always had', function () {
    $scope = clmScope();

    clmSale($scope)->assertSuccessful();
    $response = clmSale($scope)->assertUnprocessable();

    expect($response->json('code'))->toBe('credit_limit_exceeded')
        ->and(array_keys($response->json()))->toBe(['message', 'errors', 'code', 'credit_limit', 'current_balance', 'sale_amount', 'projected_balance'])
        ->and(Transaction::query()->sells()->count())->toBe(1)
        ->and(CompanySetting::creditLimitMode($scope['company_id']))->toBe('hard');
});

test('an explicit hard mode and an unknown value both stop the sale', function (?string $mode) {
    $scope = clmScope($mode);

    clmSale($scope)->assertSuccessful();
    clmSale($scope)->assertUnprocessable()->assertJsonPath('code', 'credit_limit_exceeded');
})->with(['hard', 'garbage']);

test('a reason sent in hard mode does not let a sale past the limit', function () {
    $scope = clmScope('hard');

    clmSale($scope)->assertSuccessful();
    clmSale($scope, ['credit_override_reason' => 'Owner said ok'])->assertUnprocessable()->assertJsonPath('code', 'credit_limit_exceeded');
});

test('warn mode refuses with a warning the user can pass by giving a reason, and the override is audited', function () {
    $scope = clmScope('warn');

    clmSale($scope)->assertSuccessful();

    $warning = clmSale($scope)->assertUnprocessable();
    expect($warning->json('code'))->toBe('credit_limit_warning')
        ->and($warning->json('requires_override_reason'))->toBeTrue()
        ->and($warning->json('projected_balance'))->toEqual(560)
        ->and(Transaction::query()->sells()->count())->toBe(1);

    clmSale($scope, ['credit_override_reason' => 'x'])->assertUnprocessable()->assertJsonPath('code', 'credit_limit_warning');

    clmSale($scope, ['credit_override_reason' => 'Long standing customer, paying on Friday'])->assertSuccessful();

    expect(Transaction::query()->sells()->count())->toBe(2);

    $log = AuditLog::query()->where('event', 'credit_limit_override')->firstOrFail();

    expect($log->auditable_type)->toBe(Contact::class)
        ->and($log->auditable_id)->toBe($scope['contact_id'])
        ->and($log->company_id)->toBe($scope['company_id'])
        ->and($log->user_id)->toBe(1)
        ->and($log->new_values['reason'])->toBe('Long standing customer, paying on Friday')
        ->and($log->new_values['credit_limit'])->toEqual(500)
        ->and($log->new_values['projected_balance'])->toEqual(560);
});

test('warn mode writes no override entry for a sale that stays within the limit', function () {
    $scope = clmScope('warn');

    clmSale($scope, ['credit_override_reason' => 'not needed'])->assertSuccessful();

    expect(AuditLog::query()->where('event', 'credit_limit_override')->count())->toBe(0);
});

test('off mode lets any sale through without an entry', function () {
    $scope = clmScope('off');

    clmSale($scope)->assertSuccessful();
    clmSale($scope)->assertSuccessful();

    expect(Transaction::query()->sells()->count())->toBe(2)
        ->and(AuditLog::query()->where('event', 'credit_limit_override')->count())->toBe(0);
});

test('the mode of another company does not relax this company', function () {
    $scope = clmScope();
    $other = Company::query()->create(['code' => 'CO-CLM', 'name' => 'Lenient Co', 'is_active' => true, 'max_users' => 5, 'max_branches' => 1]);
    CompanySetting::createCompanySettings($other->id, $other->name)->update(['credit_limit_mode' => 'off']);

    clmSale($scope)->assertSuccessful();
    clmSale($scope)->assertUnprocessable()->assertJsonPath('code', 'credit_limit_exceeded');

    expect(CompanySetting::creditLimitMode($other->id))->toBe('off');
});

test('moving a draft to final in warn mode takes the same reason', function () {
    $scope = clmScope('warn');

    clmSale($scope)->assertSuccessful();
    $id = clmSale($scope, ['status' => 'draft'])->assertSuccessful()->json('id');

    $this->postJson('/api/sells/statusupdate', ['ids' => [$id], 'status' => 'final'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'credit_limit_warning');

    $this->postJson('/api/sells/statusupdate', ['ids' => [$id], 'status' => 'final', 'credit_override_reason' => 'Approved by phone'])
        ->assertSuccessful();

    expect(Transaction::query()->findOrFail($id)->status)->toBe('final');
});

test('the mode is saved and read through the company settings', function () {
    $company = Company::query()->create(['code' => 'CO-CLS', 'name' => 'Settings Co', 'is_active' => true, 'max_users' => 5, 'max_branches' => 1]);
    CompanySetting::createCompanySettings($company->id, $company->name);

    $this->getJson("/api/company-settings/{$company->id}")->assertOk()->assertJsonPath('companySetting.credit_limit_mode', 'hard');

    $this->putJson("/api/company-settings/{$company->id}", ['business_name' => 'Settings Co', 'credit_limit_mode' => 'warn'])->assertOk();

    expect(DB::table('company_settings')->where('company_id', $company->id)->value('credit_limit_mode'))->toBe('warn');

    $this->putJson("/api/company-settings/{$company->id}", ['business_name' => 'Settings Co', 'credit_limit_mode' => 'sometimes'])->assertUnprocessable();
});
