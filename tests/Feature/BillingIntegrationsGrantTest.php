<?php

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The global companyadmin role (company_id null) only exists in live data, not a fresh schema — create
 * it and re-run the grant migrations, the same fixture ApiKeysTest uses for the identical situation.
 */
function rerunBillingIntegrationsGrants(): int
{
    $admin = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true])->id;

    (require database_path('migrations/2026_10_03_130000_grant_companyadmin_integrations_menu.php'))->up();
    (require database_path('migrations/2026_10_03_130100_grant_companyadmin_subscriptioninvoices_view.php'))->up();

    return $admin;
}

test('companyadmin is granted Webhooks and API Logs but not Subscription Plans or Tenant Directory', function () {
    $companyAdminRoleId = rerunBillingIntegrationsGrants();

    $grantedPaths = DB::table('permissions')
        ->join('menus', 'menus.id', '=', 'permissions.menu_id')
        ->where('permissions.role_id', $companyAdminRoleId)
        ->where('permissions.status', 1)
        ->pluck('menus.route_path')
        ->all();

    expect($grantedPaths)->toContain('/webhooks', '/apilogs', '/subscriptioninvoices')
        ->and($grantedPaths)->not->toContain('/subscriptionplans', '/coupons', '/tenants');
});

test('the subscription invoices grant only covers the view page, not add/edit', function () {
    $companyAdminRoleId = rerunBillingIntegrationsGrants();

    $grantedPaths = DB::table('permissions')
        ->join('menus', 'menus.id', '=', 'permissions.menu_id')
        ->where('permissions.role_id', $companyAdminRoleId)
        ->where('permissions.status', 1)
        ->pluck('menus.route_path')
        ->all();

    expect($grantedPaths)->not->toContain('/subscriptioninvoices/add', '/subscriptioninvoices/:id/edit');
});
