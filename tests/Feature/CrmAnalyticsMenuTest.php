<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The CRM > CRM Analytics menu migration. Adds one page (no hidden children — read-only) to the CRM
 * group the Leads migration creates. Like the other CRM menu migrations, it already ran once as part
 * of RefreshDatabase's bootstrap `migrate`, so tests that need a "not yet run" starting point reset
 * first.
 */
function crmAnalyticsMigration(): object
{
    return require database_path('migrations/2026_10_02_100000_add_crm_analytics_menu.php');
}

function crmResetAnalyticsMenu(): void
{
    $ids = DB::table('menus')->where('route_path', '/crmanalytics')->pluck('id');
    DB::table('permissions')->whereIn('menu_id', $ids)->delete();
    DB::table('menus')->whereIn('id', $ids)->delete();
}

test('the menu migration adds CRM Analytics under the existing CRM group with no hidden children', function () {
    crmResetAnalyticsMenu();
    crmAnalyticsMigration()->up();

    $groupId = DB::table('menus')->whereNull('parent_id')->where('name', 'CRM')->where('type', 2)->value('id');
    $page = DB::table('menus')->where('route_path', '/crmanalytics')->first();

    expect($page)->not->toBeNull()
        ->and((int) $page->parent_id)->toBe($groupId)
        ->and($page->route_name)->toBe('crmanalytics')
        ->and((int) $page->is_hidden)->toBe(0)
        ->and(DB::table('menus')->where('parent_id', $page->id)->count())->toBe(0);
});

test('the menu migration does nothing when the CRM group is missing', function () {
    crmResetAnalyticsMenu();
    DB::table('menus')->whereNull('parent_id')->where('name', 'CRM')->where('type', 2)->delete();

    crmAnalyticsMigration()->up();

    expect(DB::table('menus')->where('route_path', '/crmanalytics')->exists())->toBeFalse();
});

test('the menu migration is idempotent and grants nothing to any role', function () {
    $roleId = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true])->id;

    crmAnalyticsMigration()->up();
    $before = DB::table('menus')->where('route_path', '/crmanalytics')->count();
    crmAnalyticsMigration()->up();
    $after = DB::table('menus')->where('route_path', '/crmanalytics')->count();

    expect($before)->toBe(1)
        ->and($after)->toBe(1)
        ->and(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(0);
});

test('rolling the menu migration back removes exactly its own row', function () {
    $roleId = Role::query()->create(['name' => 'accountant', 'company_id' => null, 'is_active' => true])->id;

    crmAnalyticsMigration()->up();
    $id = (int) DB::table('menus')->where('route_path', '/crmanalytics')->value('id');
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $id, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

    crmAnalyticsMigration()->down();

    expect(DB::table('menus')->where('route_path', '/crmanalytics')->exists())->toBeFalse()
        ->and(DB::table('permissions')->where('menu_id', $id)->count())->toBe(0)
        ->and(DB::table('menus')->where('route_path', '/leads')->exists())->toBeTrue();
});

test('guests are sent away from the crm analytics page', function () {
    $this->get(route('crmanalytics'))->assertRedirect();
});

test('the superadmin can open the crm analytics page', function () {
    $this->actingAs(User::query()->findOrFail(1))
        ->get(route('crmanalytics'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('crmanalytics/index'));
});

test('a user without the menu permission gets a 403', function () {
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true]);

    $this->actingAs(createStaffUserForRole($role))
        ->get(route('crmanalytics'))
        ->assertForbidden();
});
