<?php

use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerSubscription;
use App\Models\CustomerSubscriptionInvoice;
use App\Models\CustomerSubscriptionPlan;
use App\Models\CustomerSubscriptionUsage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function csiCompany(): Company
{
    static $counter = 0;
    $counter++;

    return Company::query()->create([
        'code' => 'CO-D'.str_pad((string) $counter, 4, '0', STR_PAD_LEFT),
        'name' => 'Sub Invoice Co '.$counter,
        'is_active' => true, 'max_users' => 10, 'max_branches' => 2,
    ]);
}

function csiPlan(Company $company, array $overrides = []): CustomerSubscriptionPlan
{
    return CustomerSubscriptionPlan::query()->create(array_merge([
        'company_id' => $company->id, 'name' => 'Plan', 'code' => 'P-'.uniqid(),
        'billing_cycle' => 'monthly', 'price' => 30, 'setup_fee' => 10, 'trial_days' => 0,
    ], $overrides));
}

function csiCustomer(Company $company): Contact
{
    return Contact::query()->create([
        'company_id' => $company->id, 'business_name' => 'Jane Member', 'first_name' => 'Jane', 'last_name' => 'Member',
        'user_type' => 'customer', 'active' => true, 'address' => '1 Test St', 'code' => 'CUST-'.uniqid(), 'type' => 'local', 'ntn_number' => '',
    ]);
}

function csiCompanyAdmin(Company $company): User
{
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => $company->id, 'is_active' => true]);
    grantMenuPermission((int) $role->id, '/customersubscriptioninvoices');
    grantMenuPermission((int) $role->id, '/customersubscriptioninvoices/add');
    grantMenuPermission((int) $role->id, '/customersubscriptioninvoices/:id/edit');

    return createStaffUserForRole($role, ['company_id' => $company->id]);
}

test('the first invoice for a subscription includes the plan setup fee', function () {
    $company = csiCompany();
    $plan = csiPlan($company, ['price' => 30, 'setup_fee' => 10]);
    $subscription = CustomerSubscription::subscribe($company->id, csiCustomer($company)->id, $plan);

    $invoice = CustomerSubscriptionInvoice::generateForSubscription($subscription);

    expect($invoice)->not->toBeNull()
        ->and((float) $invoice->amount)->toBe(30.0)
        ->and((float) $invoice->setup_fee_amount)->toBe(10.0)
        ->and((float) $invoice->total_amount)->toBe(40.0);
});

test('a second generation for the same period is a no-op, but renew() opens the next one with no setup fee', function () {
    $company = csiCompany();
    $plan = csiPlan($company);
    $subscription = CustomerSubscription::subscribe($company->id, csiCustomer($company)->id, $plan);

    $first = CustomerSubscriptionInvoice::generateForSubscription($subscription);
    $duplicate = CustomerSubscriptionInvoice::generateForSubscription($subscription->fresh());

    expect($first)->not->toBeNull()
        ->and($duplicate)->toBeNull();

    $renewed = CustomerSubscriptionInvoice::renew($subscription->fresh());

    expect($renewed)->not->toBeNull()
        ->and((float) $renewed->setup_fee_amount)->toBe(0.0)
        ->and(CustomerSubscriptionInvoice::query()->where('customer_subscription_id', $subscription->id)->count())->toBe(2);
});

test('a metered plan bills only the overage beyond included units', function () {
    $company = csiCompany();
    $plan = csiPlan($company, ['is_metered' => true, 'unit_label' => 'calls', 'included_units' => 100, 'overage_rate' => 0.50]);
    $subscription = CustomerSubscription::subscribe($company->id, csiCustomer($company)->id, $plan);

    CustomerSubscriptionUsage::record($subscription, 150);

    $invoice = CustomerSubscriptionInvoice::generateForSubscription($subscription);

    // 150 used - 100 included = 50 overage * 0.50 = 25.00
    expect((float) $invoice->metered_amount)->toBe(25.0);
});

test('usage within the included allowance bills no overage', function () {
    $company = csiCompany();
    $plan = csiPlan($company, ['is_metered' => true, 'included_units' => 100, 'overage_rate' => 0.50]);
    $subscription = CustomerSubscription::subscribe($company->id, csiCustomer($company)->id, $plan);

    CustomerSubscriptionUsage::record($subscription, 60);

    $invoice = CustomerSubscriptionInvoice::generateForSubscription($subscription);

    expect((float) $invoice->metered_amount)->toBe(0.0);
});

test('a company admin can mark an invoice paid, reusing the Payment manual shape', function () {
    $company = csiCompany();
    $plan = csiPlan($company);
    $subscription = CustomerSubscription::subscribe($company->id, csiCustomer($company)->id, $plan);
    $invoice = CustomerSubscriptionInvoice::generateForSubscription($subscription);
    Sanctum::actingAs(csiCompanyAdmin($company));

    $this->postJson("/api/customersubscriptioninvoices/{$invoice->id}/mark-paid", [
        'method' => 'cash', 'paid_amount' => (float) $invoice->total_amount,
    ])->assertSuccessful();

    expect($invoice->fresh()->status)->toBe('paid');
});

test('refunding an invoice requires it to already be paid', function () {
    $company = csiCompany();
    $plan = csiPlan($company);
    $subscription = CustomerSubscription::subscribe($company->id, csiCustomer($company)->id, $plan);
    $invoice = CustomerSubscriptionInvoice::generateForSubscription($subscription);
    Sanctum::actingAs(csiCompanyAdmin($company));

    $this->postJson("/api/customersubscriptioninvoices/{$invoice->id}/refund", ['amount' => 10])
        ->assertStatus(422);

    $invoice->markPaid('cash', null, (float) $invoice->total_amount, null, null);

    $this->postJson("/api/customersubscriptioninvoices/{$invoice->id}/refund", ['amount' => 10])
        ->assertSuccessful();

    expect($invoice->fresh()->status)->toBe('refunded')
        ->and((float) $invoice->fresh()->refunded_amount)->toBe(10.0);
});

test('an unpaid invoice past its due date is flagged overdue', function () {
    $company = csiCompany();
    $plan = csiPlan($company);
    $subscription = CustomerSubscription::subscribe($company->id, csiCustomer($company)->id, $plan);
    $invoice = CustomerSubscriptionInvoice::generateForSubscription($subscription);
    $invoice->due_date = now()->subDay()->toDateString();
    $invoice->save();

    $flagged = CustomerSubscriptionInvoice::flagOverdue();

    expect($flagged)->toBe(1)
        ->and($invoice->fresh()->status)->toBe('overdue');
});

test('a cancelled subscription never generates a new invoice', function () {
    $company = csiCompany();
    $plan = csiPlan($company);
    $subscription = CustomerSubscription::subscribe($company->id, csiCustomer($company)->id, $plan);
    $subscription->cancel();

    expect(CustomerSubscriptionInvoice::generateForSubscription($subscription))->toBeNull()
        ->and(CustomerSubscriptionInvoice::renew($subscription))->toBeNull();
});
