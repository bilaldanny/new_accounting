<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The CRM > Lead Sources menu migration (adds a page to the CRM group the Leads migration creates)
 * and the follow-up migration that adds the Lead Import permission to the existing Leads page. Both
 * migrations already ran once as part of RefreshDatabase's bootstrap `migrate` (same as
 * `2026_10_01_100100_add_crm_leads_menu.php` in CrmLeadsMenuTest.php), so tests that need a
 * "not yet run" starting point reset first.
 */
function crmLeadSourcesMigration(): object
{
    return require database_path('migrations/2026_10_01_110200_add_crm_leadsources_menu.php');
}

function leadsImportMenuMigration(): object
{
    return require database_path('migrations/2026_10_01_110300_add_leads_import_menu.php');
}

function crmResetLeadSourcesMenu(): void
{
    $ids = DB::table('menus')->where('route_path', 'like', '/leadsources%')->pluck('id');
    DB::table('permissions')->whereIn('menu_id', $ids)->delete();
    DB::table('menus')->whereIn('id', $ids)->delete();
}

function crmResetLeadsImportMenu(): void
{
    $ids = DB::table('menus')->where('route_path', '/leads/import')->pluck('id');
    DB::table('permissions')->whereIn('menu_id', $ids)->delete();
    DB::table('menus')->whereIn('id', $ids)->delete();
}

test('the menu migration adds Lead Sources under the existing CRM group with the hidden permission rows', function () {
    crmResetLeadSourcesMenu();
    crmLeadSourcesMigration()->up();

    $groupId = DB::table('menus')->whereNull('parent_id')->where('name', 'CRM')->where('type', 2)->value('id');
    $page = DB::table('menus')->where('route_path', '/leadsources')->first();

    expect($groupId)->not->toBeNull()
        ->and($page)->not->toBeNull()
        ->and((int) $page->parent_id)->toBe($groupId)
        ->and($page->route_name)->toBe('leadsources')
        ->and((int) $page->is_hidden)->toBe(0);

    $hidden = DB::table('menus')->where('parent_id', $page->id)->orderBy('sort_order')->get();

    expect($hidden->pluck('route_path')->all())->toBe([
        '/leadsources/add',
        '/leadsources/:id/edit',
        '/leadsources/delete',
        '/leadsources/:id/view',
        '/leadsources/restore',
        '/leadsources/export',
    ])->and($hidden->every(fn (object $row): bool => (int) $row->is_hidden === 1))->toBeTrue();
});

test('the menu migration does nothing when the CRM group is missing', function () {
    crmResetLeadSourcesMenu();
    DB::table('menus')->whereNull('parent_id')->where('name', 'CRM')->where('type', 2)->delete();

    crmLeadSourcesMigration()->up();

    expect(DB::table('menus')->where('route_path', 'like', '/leadsources%')->count())->toBe(0);
});

test('the Lead Sources menu migration is idempotent and grants nothing to any role', function () {
    $roleId = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true])->id;

    crmResetLeadSourcesMenu();
    crmLeadSourcesMigration()->up();
    $rows = DB::table('menus')->where('route_path', 'like', '/leadsources%')->count();
    crmLeadSourcesMigration()->up();

    expect($rows)->toBe(7)
        ->and(DB::table('menus')->where('route_path', 'like', '/leadsources%')->count())->toBe(7)
        ->and(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(0);
});

test('rolling the Lead Sources menu migration back removes its rows and permissions, but not the CRM group', function () {
    $roleId = Role::query()->create(['name' => 'accountant', 'company_id' => null, 'is_active' => true])->id;

    crmResetLeadSourcesMenu();
    crmLeadSourcesMigration()->up();
    $addId = (int) DB::table('menus')->where('route_path', '/leadsources/add')->value('id');
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $addId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

    crmLeadSourcesMigration()->down();

    expect(DB::table('menus')->where('route_path', 'like', '/leadsources%')->count())->toBe(0)
        ->and(DB::table('permissions')->where('menu_id', $addId)->count())->toBe(0)
        ->and(DB::table('menus')->whereNull('parent_id')->where('name', 'CRM')->count())->toBe(1);
});

test('guests are sent away from the lead sources pages', function (string $routeName, array $parameters) {
    $this->get(route($routeName, $parameters))->assertRedirect();
})->with([
    'leadsources list' => ['leadsources', []],
    'leadsources add' => ['leadsources.add', []],
    'leadsources edit' => ['leadsources.edit', [3]],
    'leadsources view' => ['leadsources.view', [3]],
    'leadsources trash' => ['leadsources.trash', []],
]);

test('the superadmin can open every lead sources page', function (string $routeName, array $parameters, string $component) {
    $this->actingAs(User::query()->findOrFail(1))
        ->get(route($routeName, $parameters))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component($component));
})->with([
    'leadsources list' => ['leadsources', [], 'leadsource/index'],
    'leadsources add' => ['leadsources.add', [], 'leadsource/add'],
    'leadsources edit' => ['leadsources.edit', [3], 'leadsource/edit'],
    'leadsources view' => ['leadsources.view', [3], 'leadsource/view'],
    'leadsources trash' => ['leadsources.trash', [], 'leadsource/trash'],
]);

test('a user without the menu permission gets a 403 on each lead sources page', function (string $routeName, array $parameters) {
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true]);

    $this->actingAs(createStaffUserForRole($role))
        ->get(route($routeName, $parameters))
        ->assertForbidden();
})->with([
    'leadsources list' => ['leadsources', []],
    'leadsources add' => ['leadsources.add', []],
]);

// --- Lead Import permission on the existing Leads page --------------------------------------------

test('the leads import menu migration adds Lead Import under the existing Leads page', function () {
    crmResetLeadsImportMenu();
    leadsImportMenuMigration()->up();

    $leadsId = DB::table('menus')->where('route_path', '/leads')->value('id');
    $row = DB::table('menus')->where('route_path', '/leads/import')->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->parent_id)->toBe($leadsId)
        ->and((int) $row->is_hidden)->toBe(1);
});

test('the leads import menu migration is idempotent', function () {
    crmResetLeadsImportMenu();
    leadsImportMenuMigration()->up();
    leadsImportMenuMigration()->up();

    expect(DB::table('menus')->where('route_path', '/leads/import')->count())->toBe(1);
});

test('rolling the leads import menu migration back removes only that row', function () {
    crmResetLeadsImportMenu();
    leadsImportMenuMigration()->up();

    leadsImportMenuMigration()->down();

    expect(DB::table('menus')->where('route_path', '/leads/import')->count())->toBe(0)
        ->and(DB::table('menus')->where('route_path', '/leads')->exists())->toBeTrue();
});

test('the menu migrations clear the cached menu permissions of the roles', function () {
    $roleId = Role::query()->create(['name' => 'accountant', 'company_id' => null, 'is_active' => true])->id;
    crmResetLeadSourcesMenu();
    crmResetLeadsImportMenu();

    foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
        Cache::put("{$key}:{$roleId}", ['stale'], 600);
    }

    crmLeadSourcesMigration()->up();

    foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
        expect(Cache::has("{$key}:{$roleId}"))->toBeFalse();
        Cache::put("{$key}:{$roleId}", ['stale'], 600);
    }

    leadsImportMenuMigration()->up();

    foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
        expect(Cache::has("{$key}:{$roleId}"))->toBeFalse();
    }
});
