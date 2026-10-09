<?php

use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('the default packages are three plans in two billing cycles, each with a trial and the limits of its tier', function () {
    $this->artisan('plans:seed-defaults')->assertSuccessful();

    $plans = SubscriptionPlan::query()->orderBy('sort_order')->get()->keyBy('code');

    expect($plans->keys()->all())->toBe(['starter-monthly', 'growth-monthly', 'business-monthly', 'starter-annual', 'growth-annual', 'business-annual'])
        ->and($plans->every(fn (SubscriptionPlan $plan) => $plan->is_active && $plan->trial_days === 14))->toBeTrue()
        ->and($plans['starter-monthly']->max_users)->toBeLessThan($plans['growth-monthly']->max_users)
        ->and($plans['growth-monthly']->max_users)->toBeLessThan($plans['business-monthly']->max_users)
        ->and($plans['growth-monthly']->max_invoices_per_month)->toBeLessThan($plans['business-monthly']->max_invoices_per_month)
        ->and((float) $plans['growth-annual']->price)->toBe((float) $plans['growth-monthly']->price * 10)
        ->and($plans->where('is_featured', true)->keys()->all())->toBe(['growth-monthly', 'growth-annual']);
});

test('running it again changes nothing and never overwrites an edited plan', function () {
    $this->artisan('plans:seed-defaults')->assertSuccessful();
    SubscriptionPlan::query()->where('code', 'starter-monthly')->update(['price' => 1234, 'max_users' => 99, 'is_active' => false]);

    $this->artisan('plans:seed-defaults')->assertSuccessful();

    $starter = SubscriptionPlan::query()->where('code', 'starter-monthly')->firstOrFail();
    expect(SubscriptionPlan::query()->count())->toBe(6)->and((float) $starter->price)->toBe(1234.0)->and($starter->max_users)->toBe(99)->and($starter->is_active)->toBeFalse();
});

test('the dry run lists the plans and creates none', function () {
    $this->artisan('plans:seed-defaults', ['--dry-run' => true])->expectsOutputToContain('starter-monthly: would be created')->assertSuccessful();

    expect(SubscriptionPlan::query()->count())->toBe(0);
});

test('the public pricing marks only the recommended plan and carries the flag, never an id', function () {
    $this->artisan('plans:seed-defaults')->assertSuccessful();

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->has('plans', 6)
        ->where('plans.1.code', 'growth-monthly')
        ->where('plans.1.featured', true)
        ->where('plans.0.featured', false)
        ->missing('plans.0.id')
        ->missing('plans.0.is_featured'));
});

test('with no plan flagged nothing is recommended', function () {
    $this->artisan('plans:seed-defaults')->assertSuccessful();
    SubscriptionPlan::query()->update(['is_featured' => false]);

    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('plans.0.featured', false)->where('plans.1.featured', false)->where('plans.2.featured', false));
});

test('the superadmin can flag a plan as recommended from the plan form', function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
    $plan = SubscriptionPlan::query()->create(['name' => 'Solo', 'code' => 'solo', 'billing_cycle' => 'monthly', 'price' => 10, 'trial_days' => 7]);

    $this->putJson("/api/subscriptionplans/{$plan->id}", ['name' => 'Solo', 'code' => 'solo', 'billing_cycle' => 'monthly', 'price' => 10, 'trial_days' => 7, 'is_featured' => true])->assertSuccessful();

    expect($plan->refresh()->is_featured)->toBeTrue();
});
