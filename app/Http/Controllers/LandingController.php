<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPlan;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public page at `/`. It only reads the plan catalog, and hands the page the fields a visitor may see: never an id, a
 * tenant, a company or anything about who has subscribed.
 */
class LandingController extends Controller
{
    public function index(): Response
    {
        $plans = SubscriptionPlan::query()->where('is_active', true)->orderBy('sort_order')->orderBy('price')->get()
            ->map(fn (SubscriptionPlan $plan): array => [
                'code' => $plan->code,
                'name' => $plan->name,
                'billing_cycle' => $plan->billing_cycle,
                'price' => (float) $plan->price,
                'trial_days' => (int) $plan->trial_days,
                'limits' => [
                    'users' => (int) $plan->max_users,
                    'branches' => (int) $plan->max_branches,
                    'warehouses' => (int) $plan->max_warehouses,
                    'products' => (int) $plan->max_products,
                    'invoices_per_month' => (int) $plan->max_invoices_per_month,
                    'storage_mb' => (int) $plan->max_storage_mb,
                ],
            ])->values()->all();

        return Inertia::render('landing/Index', [
            'plans' => $plans,
            'brand' => config('landing.brand'),
            'currencyLabel' => config('landing.currency_label'),
            'contact' => array_filter(config('landing.contact')),
            'registrationEnabled' => (bool) config('landing.registration_enabled'),
        ]);
    }
}
