<?php

use App\Models\Company;
use App\Models\CustomerSubscriptionPlan;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function cspCompany(): Company
{
    static $counter = 0;
    $counter++;

    return Company::query()->create([
        'code' => 'CO-B'.str_pad((string) $counter, 4, '0', STR_PAD_LEFT),
        'name' => 'Customer Sub Co '.$counter,
        'is_active' => true, 'max_users' => 10, 'max_branches' => 2,
    ]);
}

function cspCompanyAdmin(Company $company): User
{
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => $company->id, 'is_active' => true]);
    grantMenuPermission((int) $role->id, '/customersubscriptionplans');
    grantMenuPermission((int) $role->id, '/customersubscriptionplans/add');
    grantMenuPermission((int) $role->id, '/customersubscriptionplans/:id/edit');
    grantMenuPermission((int) $role->id, '/customersubscriptionplans/delete');

    return createStaffUserForRole($role, ['company_id' => $company->id]);
}

test('a company admin can create their own customer subscription plan', function () {
    $company = cspCompany();
    Sanctum::actingAs(cspCompanyAdmin($company));

    $this->postJson('/api/customersubscriptionplans', [
        'name' => 'Gold Membership', 'code' => 'GOLD', 'billing_cycle' => 'monthly', 'price' => 49.99,
    ])->assertSuccessful();

    $plan = CustomerSubscriptionPlan::query()->where('code', 'GOLD')->firstOrFail();
    expect($plan->company_id)->toBe($company->id)
        ->and((float) $plan->price)->toBe(49.99);
});

test('a metered plan stores its unit label, included units and overage rate', function () {
    $company = cspCompany();
    Sanctum::actingAs(cspCompanyAdmin($company));

    $this->postJson('/api/customersubscriptionplans', [
        'name' => 'API Access', 'code' => 'API', 'billing_cycle' => 'monthly', 'price' => 10,
        'is_metered' => true, 'unit_label' => 'API calls', 'included_units' => 1000, 'overage_rate' => 0.01,
    ])->assertSuccessful();

    $plan = CustomerSubscriptionPlan::query()->where('code', 'API')->firstOrFail();
    expect($plan->is_metered)->toBeTrue()
        ->and($plan->unit_label)->toBe('API calls')
        ->and($plan->included_units)->toBe(1000)
        ->and((float) $plan->overage_rate)->toBe(0.01);
});

test('a plan from another company is not visible', function () {
    $companyA = cspCompany();
    $companyB = cspCompany();
    CustomerSubscriptionPlan::query()->create(['company_id' => $companyB->id, 'name' => 'B Plan', 'code' => 'BPLAN', 'billing_cycle' => 'monthly', 'price' => 5]);

    Sanctum::actingAs(cspCompanyAdmin($companyA));

    $this->getJson('/api/customersubscriptionplans')
        ->assertSuccessful()
        ->assertJsonCount(0, 'data.data');
});
