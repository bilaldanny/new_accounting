<?php

use App\Models\Company;
use App\Models\Coupon;
use App\Models\Role;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function sivPlan(array $overrides = []): SubscriptionPlan
{
    return SubscriptionPlan::query()->create(array_merge([
        'name' => 'Standard', 'code' => 'STD-'.uniqid(), 'billing_cycle' => 'monthly', 'price' => 50,
        'trial_days' => 0, 'max_users' => 10, 'max_branches' => 2,
        'max_warehouses' => 2, 'max_products' => 500, 'max_invoices_per_month' => 200, 'max_storage_mb' => 500,
    ], $overrides));
}

function sivCompany(SubscriptionPlan $plan): Company
{
    static $counter = 0;
    $counter++;

    $company = Company::query()->create([
        'code' => 'CO-7'.str_pad((string) $counter, 4, '0', STR_PAD_LEFT),
        'name' => 'Invoice Test Co '.$counter,
        'is_active' => true, 'max_users' => 10, 'max_branches' => 2,
    ]);
    $company->changePlan($plan);

    return $company->fresh();
}

function sivSuperadmin(): User
{
    $superadmin = User::query()->findOrFail(1);
    grantMenuPermission((int) $superadmin->role_id, '/subscriptioninvoices');
    grantMenuPermission((int) $superadmin->role_id, '/subscriptioninvoices/add');
    grantMenuPermission((int) $superadmin->role_id, '/subscriptioninvoices/:id/edit');

    return $superadmin;
}

test('generating an invoice for a company on a plan creates one for its current period', function () {
    $company = sivCompany(sivPlan(['price' => 75]));

    $invoice = SubscriptionInvoice::generateForCompany($company);

    expect($invoice)->not->toBeNull()
        ->and((float) $invoice->total_amount)->toBe(75.0)
        ->and($invoice->status)->toBe('unpaid');
});

test('generating an invoice twice for the same period is a no-op', function () {
    $company = sivCompany(sivPlan());

    $first = SubscriptionInvoice::generateForCompany($company);
    $second = SubscriptionInvoice::generateForCompany($company->fresh());

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and(SubscriptionInvoice::query()->where('company_id', $company->id)->count())->toBe(1);
});

test('a coupon applied to an invoice discounts its total and gets redeemed', function () {
    $company = sivCompany(sivPlan(['price' => 100]));
    $invoice = SubscriptionInvoice::generateForCompany($company);

    $coupon = Coupon::query()->create(['code' => 'SAVE20', 'type' => 'percent', 'value' => 20, 'is_active' => true]);
    $invoice->applyCoupon('SAVE20');

    expect((float) $invoice->fresh()->total_amount)->toBe(80.0)
        ->and($coupon->fresh()->redemptions_count)->toBe(1);
});

test('a superadmin can mark an invoice paid and it reuses the manual-payment shape', function () {
    $company = sivCompany(sivPlan());
    $invoice = SubscriptionInvoice::generateForCompany($company);
    Sanctum::actingAs(sivSuperadmin());

    $this->postJson("/api/subscriptioninvoices/{$invoice->id}/mark-paid", [
        'method' => 'bank_transfer',
        'reference' => 'TRX-123',
        'paid_amount' => (float) $invoice->total_amount,
    ])->assertSuccessful();

    $invoice->refresh();
    expect($invoice->status)->toBe('paid')
        ->and($invoice->payment_method)->toBe('bank_transfer')
        ->and($invoice->payment_reference)->toBe('TRX-123');
});

test('a companyadmin cannot mark an invoice paid', function () {
    $plan = sivPlan();
    $company = sivCompany($plan);
    $invoice = SubscriptionInvoice::generateForCompany($company);

    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => $company->id, 'is_active' => true]);
    grantMenuPermission((int) $role->id, '/subscriptioninvoices/:id/edit');
    $admin = createStaffUserForRole($role, ['company_id' => $company->id]);
    Sanctum::actingAs($admin);

    $this->postJson("/api/subscriptioninvoices/{$invoice->id}/mark-paid", [
        'method' => 'cash', 'paid_amount' => 50,
    ])->assertForbidden();
});

test('an unpaid invoice past its due date is flagged overdue', function () {
    $company = sivCompany(sivPlan());
    $invoice = SubscriptionInvoice::generateForCompany($company);
    $invoice->due_date = now()->subDay()->toDateString();
    $invoice->save();

    $flagged = SubscriptionInvoice::flagOverdue();

    expect($flagged)->toBe(1)
        ->and($invoice->fresh()->status)->toBe('overdue');
});

test('cancelling an invoice sets its status to cancelled', function () {
    $company = sivCompany(sivPlan());
    $invoice = SubscriptionInvoice::generateForCompany($company);
    Sanctum::actingAs(sivSuperadmin());

    $this->postJson("/api/subscriptioninvoices/{$invoice->id}/cancel")->assertSuccessful();

    expect($invoice->fresh()->status)->toBe('cancelled');
});
