<?php

use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function lpPlan(array $extra = []): SubscriptionPlan
{
    return SubscriptionPlan::query()->create($extra + [
        'name' => 'Starter', 'code' => 'starter-'.uniqid(), 'billing_cycle' => 'monthly', 'price' => 49, 'trial_days' => 14,
        'max_users' => 5, 'max_branches' => 1, 'max_warehouses' => 2, 'max_products' => 1000, 'max_invoices_per_month' => 300, 'max_storage_mb' => 2048,
        'sort_order' => 1, 'is_active' => true,
    ]);
}

test('a guest sees the landing page at the home route', function () {
    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('landing/Index'));
});

test('a signed-in user going to the home route lands on the dashboard', function () {
    $this->actingAs(User::query()->findOrFail(1))->get('/')->assertRedirect('/dashboard');
});

test('pricing lists only active plans and only the public fields', function () {
    $active = lpPlan(['name' => 'Growth', 'code' => 'growth']);
    lpPlan(['name' => 'Secret internal', 'code' => 'internal', 'is_active' => false]);
    $deleted = lpPlan(['name' => 'Gone', 'code' => 'gone']);
    $deleted->delete();

    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('plans', 1)
        ->where('plans.0.code', 'growth')
        ->where('plans.0.name', 'Growth')
        ->where('plans.0.price', 49)
        ->where('plans.0.trial_days', 14)
        ->where('plans.0.limits.users', 5)
        ->where('plans.0.limits.storage_mb', 2048)
        ->missing('plans.0.id')
        ->missing('plans.0.is_active')
        ->missing('plans.0.sort_order')
        ->missing('plans.0.created_at'));

    expect($active->id)->not->toBeNull();
});

test('nothing about tenants or companies reaches the guest page', function () {
    lpPlan();

    $props = json_encode($this->get('/')->viewData('page')['props']);

    expect($props)->not->toContain('tenant_status')->not->toContain('company_id')->not->toContain('subscription_plan_id');
});

test('with no active plan the page still renders and offers contact', function () {
    $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->has('plans', 0));
});

test('the sign-up switch and the contact details come from config', function () {
    config(['landing.registration_enabled' => false, 'landing.contact.email' => 'hello@example.test', 'landing.currency_label' => 'PKR']);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('registrationEnabled', false)
        ->where('contact.email', 'hello@example.test')
        ->where('currencyLabel', 'PKR'));
});

test('the landing page makes no claim the product cannot back up', function () {
    $source = strtolower(file_get_contents(resource_path('js/pages/landing/Index.vue')));

    expect($source)->not->toMatch('/fbr[- ](compliant|integrated|approved|certified)/')
        ->and($source)->not->toContain('testimonial')
        ->and($source)->not->toContain('complete erp')
        ->and($source)->toContain('roadmap');
});

test('the public pages load no remote font or script', function () {
    foreach (['resources/js/pages/landing/Index.vue', 'resources/js/pages/landing/Register.vue', 'resources/css/landing.css', 'resources/js/landing.ts'] as $file) {
        expect(file_get_contents(base_path($file)))->not->toMatch('#https?://(?!www\.w3\.org)#i');
    }
});
