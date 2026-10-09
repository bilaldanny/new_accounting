<?php

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('the Subscriptions group holds all four Customer Subscription Module pages', function () {
    $groupId = DB::table('menus')->whereNull('parent_id')->where('name', 'Subscriptions')->where('type', 2)->value('id');

    expect($groupId)->not->toBeNull();

    $pageNames = DB::table('menus')->where('parent_id', $groupId)->pluck('name')->all();

    expect($pageNames)->toContain('Subscription Plans', 'Customer Subscriptions', 'Subscription Invoices', 'Subscription Analytics');
});

test('companyadmin is granted the whole Subscriptions group, unlike the superadmin-only Billing group', function () {
    $admin = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true])->id;

    (require database_path('migrations/2026_10_04_120000_grant_companyadmin_customer_subscriptions_menu.php'))->up();

    $grantedPaths = DB::table('permissions')
        ->join('menus', 'menus.id', '=', 'permissions.menu_id')
        ->where('permissions.role_id', $admin)
        ->where('permissions.status', 1)
        ->pluck('menus.route_path')
        ->all();

    expect($grantedPaths)->toContain('/customersubscriptionplans', '/customersubscriptionplans/add', '/customersubscriptions', '/customersubscriptioninvoices', '/customersubscriptionanalytics')
        ->and($grantedPaths)->not->toContain('/subscriptionplans', '/tenants');
});
