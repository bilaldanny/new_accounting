<?php

use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerSubscription;
use App\Models\CustomerSubscriptionInvoice;
use App\Models\CustomerSubscriptionPlan;
use App\Models\Role;
use App\Services\Reports\CustomerSubscriptionReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The Subscription Analytics page reads a stored snapshot: its figures are exactly the report's own, it is used only for the day it
 * was made and is dropped the moment a subscription, invoice or plan of the company changes, and one company never reads another's.
 */
function csaWorld(string $code): array
{
    $company = Company::query()->create(['code' => $code, 'name' => 'Snapshot Co '.$code, 'is_active' => true, 'max_users' => 10, 'max_branches' => 2]);
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => $company->id, 'is_active' => true]);
    grantMenuPermission((int) $role->id, '/customersubscriptionanalytics');
    $plan = CustomerSubscriptionPlan::query()->create(['company_id' => $company->id, 'name' => 'P', 'code' => 'P-'.uniqid(), 'billing_cycle' => 'monthly', 'price' => 50]);
    $customer = fn () => Contact::query()->create([
        'company_id' => $company->id, 'business_name' => 'Customer '.uniqid(), 'first_name' => 'C', 'last_name' => 'N', 'user_type' => 'customer', 'active' => true,
        'address' => '1 Test St', 'code' => 'CUST-'.uniqid(), 'type' => 'local', 'ntn_number' => '',
    ]);

    return ['company' => $company, 'user' => createStaffUserForRole($role, ['company_id' => $company->id]), 'plan' => $plan, 'customer' => $customer];
}

function csaFigures(?int $company): array
{
    return [
        'summary' => CustomerSubscriptionReport::summary($company), 'churn' => CustomerSubscriptionReport::churn($company, 30),
        'ltv' => CustomerSubscriptionReport::ltv($company), 'revenue' => ['data' => CustomerSubscriptionReport::revenueByMonth($company, 6)],
    ];
}

test('the snapshot figures are the live report figures, on the first read, on a repeat and after the daily refresh', function () {
    $world = csaWorld('CSA1');
    $subscription = CustomerSubscription::subscribe($world['company']->id, $world['customer']()->id, $world['plan']);
    CustomerSubscriptionInvoice::generateForSubscription($subscription)->markPaid('cash', null, 50, null, null);
    CustomerSubscription::subscribe($world['company']->id, $world['customer']()->id, $world['plan']);
    Sanctum::actingAs($world['user']);
    $live = csaFigures($world['company']->id);

    foreach (['summary', 'churn', 'ltv', 'revenue'] as $kind) {
        $first = $this->getJson("/api/customersubscriptionanalytics/{$kind}")->assertSuccessful();
        $again = $this->getJson("/api/customersubscriptionanalytics/{$kind}")->assertSuccessful();

        expect($first->json())->toEqual($live[$kind])->and($again->json())->toEqual($live[$kind])->and($again->headers->get('X-Snapshot-At'))->not->toBeEmpty();
    }

    expect(DB::table('customer_subscription_analytics_snapshots')->where('scope', 'company:'.$world['company']->id)->count())->toBe(4);

    $this->artisan('customersubscriptions:refresh-analytics', ['--company' => $world['company']->id])->assertSuccessful();

    foreach (['summary', 'churn', 'ltv', 'revenue'] as $kind) {
        expect($this->getJson("/api/customersubscriptionanalytics/{$kind}")->json())->toEqual($live[$kind]);
    }
});

test('a change to a subscription, an invoice or a plan drops the snapshot so the next read is exact', function () {
    $world = csaWorld('CSA2');
    $subscription = CustomerSubscription::subscribe($world['company']->id, $world['customer']()->id, $world['plan']);
    Sanctum::actingAs($world['user']);

    expect((float) $this->getJson('/api/customersubscriptionanalytics/summary')->json('mrr'))->toBe(50.0);

    $world['plan']->update(['price' => 80]);
    expect((float) $this->getJson('/api/customersubscriptionanalytics/summary')->json('mrr'))->toBe(80.0);

    CustomerSubscription::subscribe($world['company']->id, $world['customer']()->id, $world['plan']);
    expect((float) $this->getJson('/api/customersubscriptionanalytics/summary')->json('mrr'))->toBe(160.0);

    $this->getJson('/api/customersubscriptionanalytics/ltv')->assertSuccessful();
    CustomerSubscriptionInvoice::generateForSubscription($subscription)->markPaid('cash', null, 80, null, null);
    expect($this->getJson('/api/customersubscriptionanalytics/ltv')->json('total_revenue'))->toEqual(80);
});

test('a snapshot of another day is not used, other windows are worked out live, and companies are kept apart', function () {
    $mine = csaWorld('CSA3');
    $other = csaWorld('CSA4');
    CustomerSubscription::subscribe($mine['company']->id, $mine['customer']()->id, $mine['plan']);
    CustomerSubscription::subscribe($other['company']->id, $other['customer']()->id, $other['plan']);
    CustomerSubscription::subscribe($other['company']->id, $other['customer']()->id, $other['plan']);
    Sanctum::actingAs($mine['user']);

    expect($this->getJson('/api/customersubscriptionanalytics/summary')->json('active'))->toBe(1);

    // yesterday's snapshot is ignored (and replaced)
    DB::table('customer_subscription_analytics_snapshots')->update(['payload' => json_encode(['active' => 99]), 'generated_at' => now()->subDay()]);
    expect($this->getJson('/api/customersubscriptionanalytics/summary')->json('active'))->toBe(1);

    // a window other than the stored one is never answered from the snapshot
    expect($this->getJson('/api/customersubscriptionanalytics/churn?days=90')->json('days'))->toBe(90)
        ->and($this->getJson('/api/customersubscriptionanalytics/revenue?months=3')->json('data'))->toHaveCount(3);

    $this->artisan('customersubscriptions:refresh-analytics')->assertSuccessful();
    expect(DB::table('customer_subscription_analytics_snapshots')->where('scope', 'company:'.$other['company']->id)->where('kind', 'summary')->value('payload'))->toContain('"active":2')
        ->and($this->getJson('/api/customersubscriptionanalytics/summary')->json('active'))->toBe(1);
});
