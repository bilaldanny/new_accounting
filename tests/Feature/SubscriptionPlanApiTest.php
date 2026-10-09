<?php

use App\Models\Company;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function spSuperadmin(): User
{
    $superadmin = User::query()->findOrFail(1);
    grantMenuPermission((int) $superadmin->role_id, '/subscriptionplans');
    grantMenuPermission((int) $superadmin->role_id, '/subscriptionplans/add');
    grantMenuPermission((int) $superadmin->role_id, '/subscriptionplans/:id/edit');
    grantMenuPermission((int) $superadmin->role_id, '/subscriptionplans/delete');

    return $superadmin;
}

test('a superadmin can create, list and update a subscription plan', function () {
    Sanctum::actingAs(spSuperadmin());

    $this->postJson('/api/subscriptionplans', [
        'name' => 'Standard',
        'code' => 'STANDARD',
        'billing_cycle' => 'monthly',
        'price' => 29.99,
        'trial_days' => 14,
        'max_users' => 10,
        'max_branches' => 2,
        'max_warehouses' => 2,
        'max_products' => 500,
        'max_invoices_per_month' => 200,
        'max_storage_mb' => 500,
    ])->assertSuccessful();

    $plan = SubscriptionPlan::query()->where('code', 'STANDARD')->firstOrFail();
    expect((float) $plan->price)->toBe(29.99);

    $this->getJson('/api/subscriptionplans')
        ->assertSuccessful()
        ->assertJsonPath('data.data.0.code', 'STANDARD');

    $this->putJson('/api/subscriptionplans/'.$plan->id, [
        'name' => 'Standard',
        'code' => 'STANDARD',
        'billing_cycle' => 'annual',
        'price' => 299,
    ])->assertSuccessful();

    expect($plan->fresh()->billing_cycle)->toBe('annual');
});

test('a duplicate plan code is rejected', function () {
    Sanctum::actingAs(spSuperadmin());
    SubscriptionPlan::query()->create([
        'name' => 'Basic', 'code' => 'BASIC', 'billing_cycle' => 'monthly', 'price' => 10,
    ]);

    $this->postJson('/api/subscriptionplans', [
        'name' => 'Basic Copy', 'code' => 'BASIC', 'billing_cycle' => 'monthly', 'price' => 10,
    ])->assertUnprocessable()->assertJsonValidationErrors(['code']);
});

test('deleting a plan soft deletes it', function () {
    Sanctum::actingAs(spSuperadmin());
    $plan = SubscriptionPlan::query()->create([
        'name' => 'Basic', 'code' => 'BASIC', 'billing_cycle' => 'monthly', 'price' => 10,
    ]);

    $this->deleteJson('/api/subscriptionplans/'.$plan->id)->assertSuccessful();

    expect(SubscriptionPlan::query()->find($plan->id))->toBeNull()
        ->and(SubscriptionPlan::onlyTrashed()->find($plan->id))->not->toBeNull();
});

test('a company subscribing to a plan snapshots its limits and starts a trial', function () {
    $plan = SubscriptionPlan::query()->create([
        'name' => 'Standard', 'code' => 'STANDARD', 'billing_cycle' => 'monthly', 'price' => 20,
        'trial_days' => 7, 'max_users' => 5, 'max_branches' => 1,
        'max_warehouses' => 1, 'max_products' => 100, 'max_invoices_per_month' => 50, 'max_storage_mb' => 100,
    ]);

    $company = Company::query()->create([
        'code' => 'CO-55501', 'name' => 'Sub Test Co', 'is_active' => true, 'max_users' => 10, 'max_branches' => 2,
    ]);

    $company->changePlan($plan);
    $company->refresh();

    expect($company->subscription_plan_id)->toBe($plan->id)
        ->and($company->max_users)->toBe(5)
        ->and($company->tenant_status)->toBe('trial')
        ->and($company->trial_ends_at)->not->toBeNull();
});
