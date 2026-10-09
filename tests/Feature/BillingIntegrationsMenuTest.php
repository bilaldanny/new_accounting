<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The menu migrations for Module 17 (SaaS Tenant Billing → "Billing" group) and Module 16 (Integrations
 * & Open API → "Integrations" group), mirroring the pattern CrmLeadsMenuTest already proved out for the
 * CRM group: the first page in a group creates the group, later pages reuse it, and a page's own
 * down() never swallows a sibling page it didn't create.
 */
test('the Billing group holds all four SaaS Tenant Billing pages', function () {
    $groupId = DB::table('menus')->whereNull('parent_id')->where('name', 'Billing')->where('type', 2)->value('id');

    expect($groupId)->not->toBeNull();

    $pageNames = DB::table('menus')->where('parent_id', $groupId)->pluck('name')->all();

    expect($pageNames)->toContain('Subscription Plans', 'Coupons', 'Tenant Directory', 'Subscription Invoices');
});

test('the Integrations group holds Webhooks and API Logs', function () {
    $groupId = DB::table('menus')->whereNull('parent_id')->where('name', 'Integrations')->where('type', 2)->value('id');

    expect($groupId)->not->toBeNull();

    $pageNames = DB::table('menus')->where('parent_id', $groupId)->pluck('name')->all();

    expect($pageNames)->toContain('Webhooks', 'API Logs');
});

test('rolling back Subscription Plans removes only its own rows, not the Coupons page in the same group', function () {
    $groupId = DB::table('menus')->whereNull('parent_id')->where('name', 'Billing')->where('type', 2)->value('id');

    $migration = require base_path('database/migrations/2026_10_03_110000_add_billing_subscriptionplans_menu.php');
    $migration->down();

    expect(DB::table('menus')->where('route_path', '/subscriptionplans')->exists())->toBeFalse()
        ->and(DB::table('menus')->where('route_path', '/coupons')->exists())->toBeTrue()
        ->and(DB::table('menus')->whereNull('parent_id')->where('name', 'Billing')->exists())->toBeTrue();
});

test('the API Keys page still lives under Settings, untouched by the new Integrations group', function () {
    $settingsGroupId = DB::table('menus')->where('route_path', '/software/setting')->value('parent_id');
    $integrationsGroupId = DB::table('menus')->whereNull('parent_id')->where('name', 'Integrations')->where('type', 2)->value('id');

    $apiKeysParent = DB::table('menus')->where('route_path', '/apikeys')->value('parent_id');

    if ($settingsGroupId !== null) {
        expect($apiKeysParent)->toBe($settingsGroupId)
            ->and($apiKeysParent)->not->toBe($integrationsGroupId);
    }
});
