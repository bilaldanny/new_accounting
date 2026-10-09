<?php

use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerSubscription;
use App\Models\CustomerSubscriptionInvoice;
use App\Models\CustomerSubscriptionPlan;
use App\Models\Role;
use App\Models\User;
use App\Services\Reports\CustomerSubscriptionReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function csrCompany(): Company
{
    static $counter = 0;
    $counter++;

    return Company::query()->create([
        'code' => 'CO-F'.str_pad((string) $counter, 4, '0', STR_PAD_LEFT),
        'name' => 'Sub Report Co '.$counter,
        'is_active' => true, 'max_users' => 10, 'max_branches' => 2,
    ]);
}

function csrCustomer(Company $company): Contact
{
    return Contact::query()->create([
        'company_id' => $company->id, 'business_name' => 'Customer '.uniqid(), 'first_name' => 'C', 'last_name' => 'N',
        'user_type' => 'customer', 'active' => true, 'address' => '1 Test St', 'code' => 'CUST-'.uniqid(), 'type' => 'local', 'ntn_number' => '',
    ]);
}

function csrAdmin(Company $company): User
{
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => $company->id, 'is_active' => true]);
    grantMenuPermission((int) $role->id, '/customersubscriptionanalytics');

    return createStaffUserForRole($role, ['company_id' => $company->id]);
}

test('MRR normalizes quarterly and annual plans to a monthly figure', function () {
    $company = csrCompany();

    $monthly = CustomerSubscriptionPlan::query()->create(['company_id' => $company->id, 'name' => 'M', 'code' => 'M-'.uniqid(), 'billing_cycle' => 'monthly', 'price' => 30]);
    $quarterly = CustomerSubscriptionPlan::query()->create(['company_id' => $company->id, 'name' => 'Q', 'code' => 'Q-'.uniqid(), 'billing_cycle' => 'quarterly', 'price' => 90]);
    $annual = CustomerSubscriptionPlan::query()->create(['company_id' => $company->id, 'name' => 'A', 'code' => 'A-'.uniqid(), 'billing_cycle' => 'annual', 'price' => 1200]);

    CustomerSubscription::subscribe($company->id, csrCustomer($company)->id, $monthly);
    CustomerSubscription::subscribe($company->id, csrCustomer($company)->id, $quarterly);
    CustomerSubscription::subscribe($company->id, csrCustomer($company)->id, $annual);

    // 30 + (90/3=30) + (1200/12=100) = 160
    expect(CustomerSubscriptionReport::mrr($company->id))->toBe(160.0);
});

test('the summary endpoint reports counts by status and ARR as 12x MRR', function () {
    $company = csrCompany();
    $plan = CustomerSubscriptionPlan::query()->create(['company_id' => $company->id, 'name' => 'P', 'code' => 'P-'.uniqid(), 'billing_cycle' => 'monthly', 'price' => 50]);
    CustomerSubscription::subscribe($company->id, csrCustomer($company)->id, $plan);

    Sanctum::actingAs(csrAdmin($company));

    $response = $this->getJson('/api/customersubscriptionanalytics/summary')->assertSuccessful();

    expect($response->json('active'))->toBe(1)
        ->and((float) $response->json('mrr'))->toBe(50.0)
        ->and((float) $response->json('arr'))->toBe(600.0);
});

test('LTV averages paid revenue per subscription', function () {
    $company = csrCompany();
    $plan = CustomerSubscriptionPlan::query()->create(['company_id' => $company->id, 'name' => 'P', 'code' => 'P-'.uniqid(), 'billing_cycle' => 'monthly', 'price' => 50]);
    $subscription = CustomerSubscription::subscribe($company->id, csrCustomer($company)->id, $plan);
    $invoice = CustomerSubscriptionInvoice::generateForSubscription($subscription);
    $invoice->markPaid('cash', null, 50, null, null);

    $ltv = CustomerSubscriptionReport::ltv($company->id);

    expect($ltv['subscriptions_counted'])->toBe(1)
        ->and($ltv['average_ltv'])->toBe(50.0);
});

test('renewal due lists subscriptions ending within the window', function () {
    $company = csrCompany();
    $plan = CustomerSubscriptionPlan::query()->create(['company_id' => $company->id, 'name' => 'P', 'code' => 'P-'.uniqid(), 'billing_cycle' => 'monthly', 'price' => 50]);
    $subscription = CustomerSubscription::subscribe($company->id, csrCustomer($company)->id, $plan);
    $subscription->current_period_ends_at = now()->addDays(3)->toDateString();
    $subscription->save();

    $due = CustomerSubscriptionReport::renewalDue($company->id, 7);

    expect($due)->toHaveCount(1);
});
