<?php

use App\Models\Company;
use App\Models\Role;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function tdCompany(): Company
{
    static $counter = 0;
    $counter++;

    return Company::query()->create([
        'code' => 'CO-8'.str_pad((string) $counter, 4, '0', STR_PAD_LEFT),
        'name' => 'Tenant Co '.$counter,
        'is_active' => true, 'max_users' => 10, 'max_branches' => 2,
    ]);
}

function tdSuperadmin(): User
{
    $superadmin = User::query()->findOrFail(1);
    grantMenuPermission((int) $superadmin->role_id, '/tenants');
    grantMenuPermission((int) $superadmin->role_id, '/tenants/:id/edit');

    return $superadmin;
}

test('a superadmin can list tenants with their plan and usage', function () {
    $company = tdCompany();
    Sanctum::actingAs(tdSuperadmin());

    $this->getJson('/api/tenants')
        ->assertSuccessful()
        ->assertJsonFragment(['id' => $company->id]);
});

test('a superadmin can change a tenant status', function () {
    $company = tdCompany();
    Sanctum::actingAs(tdSuperadmin());

    $this->postJson("/api/tenants/{$company->id}/status", ['status' => 'suspended'])
        ->assertSuccessful();

    expect($company->fresh()->tenant_status)->toBe('suspended');
});

test('an invalid tenant status is rejected', function () {
    $company = tdCompany();
    Sanctum::actingAs(tdSuperadmin());

    $this->postJson("/api/tenants/{$company->id}/status", ['status' => 'bogus'])
        ->assertUnprocessable();
});

test('a non-superadmin cannot access the tenant directory', function () {
    $company = tdCompany();
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => $company->id, 'is_active' => true]);
    grantMenuPermission((int) $role->id, '/tenants');
    $admin = createStaffUserForRole($role, ['company_id' => $company->id]);
    Sanctum::actingAs($admin);

    $this->getJson('/api/tenants')->assertForbidden();
});

test('a superadmin can change a tenant plan, which is Upgrade/Downgrade/Plan Change', function () {
    $company = tdCompany();
    $plan = SubscriptionPlan::query()->create([
        'name' => 'Premium', 'code' => 'PREMIUM', 'billing_cycle' => 'annual', 'price' => 500,
        'max_users' => 50, 'max_branches' => 10,
    ]);
    Sanctum::actingAs(tdSuperadmin());

    $this->postJson("/api/tenants/{$company->id}/plan", ['subscription_plan_id' => $plan->id])
        ->assertSuccessful();

    $company->refresh();
    expect($company->subscription_plan_id)->toBe($plan->id)
        ->and($company->max_users)->toBe(50);
});
