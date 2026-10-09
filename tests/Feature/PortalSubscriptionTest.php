<?php

use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerSubscription;
use App\Models\CustomerSubscriptionPlan;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The Customer Self-Service Portal: upgrade/downgrade, pause, resume, cancel — built on the exact same
 * `users.contact_id` + `RestrictPortalUsers` mechanism "Settings > Portal Users" already uses.
 */
function pscCompany(): Company
{
    static $counter = 0;
    $counter++;

    return Company::query()->create([
        'code' => 'CO-E'.str_pad((string) $counter, 4, '0', STR_PAD_LEFT),
        'name' => 'Portal Sub Co '.$counter,
        'is_active' => true, 'max_users' => 10, 'max_branches' => 2,
    ]);
}

function pscCustomer(Company $company): Contact
{
    return Contact::query()->create([
        'company_id' => $company->id, 'business_name' => 'Jane Member', 'first_name' => 'Jane', 'last_name' => 'Member',
        'user_type' => 'customer', 'active' => true, 'address' => '1 Test St', 'code' => 'CUST-'.uniqid(), 'type' => 'local', 'ntn_number' => '',
    ]);
}

function pscPlan(Company $company, array $overrides = []): CustomerSubscriptionPlan
{
    return CustomerSubscriptionPlan::query()->create(array_merge([
        'company_id' => $company->id, 'name' => 'Plan', 'code' => 'P-'.uniqid(),
        'billing_cycle' => 'monthly', 'price' => 30, 'trial_days' => 0,
    ], $overrides));
}

function pscPortalUser(Company $company, int $contactId): User
{
    $portalRoleId = Role::query()->where('name', 'portal')->value('id');

    $user = User::query()->create([
        'company_id' => $company->id, 'role_id' => $portalRoleId,
        'first_name' => 'Portal', 'last_name' => 'Person', 'email' => 'portal'.uniqid().'@example.com',
        'username' => 'portal'.uniqid(), 'password' => Hash::make('portal-pass-1'), 'is_active' => true,
    ]);

    $user->forceFill(['contact_id' => $contactId])->save();

    return $user->refresh();
}

test('a portal customer sees only their own subscription', function () {
    $company = pscCompany();
    $plan = pscPlan($company);
    $customer = pscCustomer($company);
    $otherCustomer = pscCustomer($company);

    $mine = CustomerSubscription::subscribe($company->id, $customer->id, $plan);
    CustomerSubscription::subscribe($company->id, $otherCustomer->id, $plan);

    Sanctum::actingAs(pscPortalUser($company, $customer->id));

    $this->getJson('/api/portal/subscriptions')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id);
});

test('a portal customer can pause, resume and cancel their own subscription', function () {
    $company = pscCompany();
    $plan = pscPlan($company);
    $customer = pscCustomer($company);
    $subscription = CustomerSubscription::subscribe($company->id, $customer->id, $plan);

    Sanctum::actingAs(pscPortalUser($company, $customer->id));

    $this->postJson("/api/portal/subscriptions/{$subscription->id}/pause")->assertSuccessful();
    expect($subscription->fresh()->status)->toBe('paused');

    $this->postJson("/api/portal/subscriptions/{$subscription->id}/resume")->assertSuccessful();
    expect($subscription->fresh()->status)->toBe('active');

    $this->postJson("/api/portal/subscriptions/{$subscription->id}/cancel", ['reason' => 'moving away'])->assertSuccessful();
    expect($subscription->fresh()->status)->toBe('cancelled');
});

test('a portal customer can upgrade/downgrade their own subscription plan', function () {
    $company = pscCompany();
    $plan = pscPlan($company, ['price' => 30]);
    $betterPlan = pscPlan($company, ['name' => 'Better', 'code' => 'BETTER-'.uniqid(), 'price' => 60]);
    $customer = pscCustomer($company);
    $subscription = CustomerSubscription::subscribe($company->id, $customer->id, $plan);

    Sanctum::actingAs(pscPortalUser($company, $customer->id));

    $this->postJson("/api/portal/subscriptions/{$subscription->id}/change-plan", [
        'customer_subscription_plan_id' => $betterPlan->id,
    ])->assertSuccessful();

    expect($subscription->fresh()->customer_subscription_plan_id)->toBe($betterPlan->id);
});

test('a portal customer cannot act on another customer\'s subscription', function () {
    $company = pscCompany();
    $plan = pscPlan($company);
    $customer = pscCustomer($company);
    $otherCustomer = pscCustomer($company);
    $othersSubscription = CustomerSubscription::subscribe($company->id, $otherCustomer->id, $plan);

    Sanctum::actingAs(pscPortalUser($company, $customer->id));

    $this->postJson("/api/portal/subscriptions/{$othersSubscription->id}/cancel")->assertNotFound();
});

test('a portal customer cannot upgrade to a plan from another company', function () {
    $companyA = pscCompany();
    $companyB = pscCompany();
    $plan = pscPlan($companyA);
    $foreignPlan = pscPlan($companyB);
    $customer = pscCustomer($companyA);
    $subscription = CustomerSubscription::subscribe($companyA->id, $customer->id, $plan);

    Sanctum::actingAs(pscPortalUser($companyA, $customer->id));

    $this->postJson("/api/portal/subscriptions/{$subscription->id}/change-plan", [
        'customer_subscription_plan_id' => $foreignPlan->id,
    ])->assertNotFound();
});

test('a portal account is confined to the portal and cannot reach admin subscription routes', function () {
    $company = pscCompany();
    $customer = pscCustomer($company);

    Sanctum::actingAs(pscPortalUser($company, $customer->id));

    $this->getJson('/api/customersubscriptions')->assertForbidden();
});
