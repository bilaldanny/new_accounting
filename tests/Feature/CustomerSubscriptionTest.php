<?php

use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerSubscription;
use App\Models\CustomerSubscriptionPlan;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function csCompany(): Company
{
    static $counter = 0;
    $counter++;

    return Company::query()->create([
        'code' => 'CO-C'.str_pad((string) $counter, 4, '0', STR_PAD_LEFT),
        'name' => 'Sub Contract Co '.$counter,
        'is_active' => true, 'max_users' => 10, 'max_branches' => 2,
    ]);
}

function csPlan(Company $company, array $overrides = []): CustomerSubscriptionPlan
{
    return CustomerSubscriptionPlan::query()->create(array_merge([
        'company_id' => $company->id, 'name' => 'Gym Membership', 'code' => 'GYM-'.uniqid(),
        'billing_cycle' => 'monthly', 'price' => 30, 'setup_fee' => 10, 'trial_days' => 0,
    ], $overrides));
}

function csCustomer(Company $company): Contact
{
    return Contact::query()->create([
        'company_id' => $company->id, 'business_name' => 'Jane Member', 'first_name' => 'Jane', 'last_name' => 'Member',
        'user_type' => 'customer', 'active' => true, 'address' => '1 Test St', 'code' => 'CUST-'.uniqid(), 'type' => 'local', 'ntn_number' => '',
    ]);
}

function csCompanyAdmin(Company $company): User
{
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => $company->id, 'is_active' => true]);
    grantMenuPermission((int) $role->id, '/customersubscriptions');
    grantMenuPermission((int) $role->id, '/customersubscriptions/add');
    grantMenuPermission((int) $role->id, '/customersubscriptions/:id/edit');

    return createStaffUserForRole($role, ['company_id' => $company->id]);
}

test('subscribing a customer with no trial starts them active immediately', function () {
    $company = csCompany();
    $plan = csPlan($company, ['trial_days' => 0]);

    $subscription = CustomerSubscription::subscribe($company->id, csCustomer($company)->id, $plan);

    expect($subscription->status)->toBe('active')
        ->and($subscription->trial_ends_at)->toBeNull();
});

test('subscribing a customer with a trial period starts them in trial', function () {
    $company = csCompany();
    $plan = csPlan($company, ['trial_days' => 14]);

    $subscription = CustomerSubscription::subscribe($company->id, csCustomer($company)->id, $plan);

    expect($subscription->status)->toBe('trial')
        ->and($subscription->trial_ends_at)->not->toBeNull();
});

test('pause, resume and cancel follow the allowed state transitions', function () {
    $company = csCompany();
    $plan = csPlan($company);
    $subscription = CustomerSubscription::subscribe($company->id, csCustomer($company)->id, $plan);

    $subscription->pause();
    expect($subscription->status)->toBe('paused');

    expect(fn () => $subscription->pause())->toThrow(ValidationException::class);

    $subscription->resume();
    expect($subscription->status)->toBe('active');

    $subscription->cancel('No longer needed');
    expect($subscription->status)->toBe('cancelled')
        ->and($subscription->auto_renew)->toBeFalse()
        ->and($subscription->cancellation_reason)->toBe('No longer needed');
});

test('a company admin can create a subscription via the API and change its plan', function () {
    $company = csCompany();
    $plan = csPlan($company);
    $upgradedPlan = csPlan($company, ['name' => 'Platinum', 'code' => 'PLAT-'.uniqid(), 'price' => 60]);
    $customer = csCustomer($company);
    Sanctum::actingAs(csCompanyAdmin($company));

    $response = $this->postJson('/api/customersubscriptions', [
        'contact_id' => $customer->id,
        'customer_subscription_plan_id' => $plan->id,
    ])->assertSuccessful();

    $subscriptionId = $response->json('data.id');

    $this->postJson("/api/customersubscriptions/{$subscriptionId}/change-plan", [
        'customer_subscription_plan_id' => $upgradedPlan->id,
    ])->assertSuccessful();

    expect(CustomerSubscription::query()->findOrFail($subscriptionId)->customer_subscription_plan_id)->toBe($upgradedPlan->id);
});

test('recording usage against a non-metered plan is rejected', function () {
    $company = csCompany();
    $plan = csPlan($company, ['is_metered' => false]);
    $subscription = CustomerSubscription::subscribe($company->id, csCustomer($company)->id, $plan);
    Sanctum::actingAs(csCompanyAdmin($company));

    $this->postJson("/api/customersubscriptions/{$subscription->id}/usage", ['quantity' => 5])
        ->assertStatus(422);
});
