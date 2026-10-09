<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The CRM > Leads menu migration. Unlike Transporter/Commission Agent (anchored to a legacy group),
 * this one creates its own top-level "CRM" group the first time it runs, the same way the Reports
 * group migration does — which means RefreshDatabase's own initial `migrate` already runs it once
 * before any test body executes. Tests that need a "not yet run" starting point delete the `/leads%`
 * rows first (crmResetLeadsMenu()).
 */
function crmLeadsMigration(): object
{
    return require database_path('migrations/2026_10_01_100100_add_crm_leads_menu.php');
}

/**
 * Undo what RefreshDatabase's bootstrap migrate already did, back to a "migration never ran" state.
 * $keepGroup true leaves the CRM group row in place (to test that it gets reused, not duplicated).
 */
function crmResetLeadsMenu(bool $keepGroup): void
{
    // Exact /leads path + its children — NOT a bare "LIKE /leads%", which would also match the
    // unrelated /leadsources% page.
    $ids = DB::table('menus')->where('route_path', '/leads')->orWhere('route_path', 'like', '/leads/%')->pluck('id');
    DB::table('permissions')->whereIn('menu_id', $ids)->delete();
    DB::table('menus')->whereIn('id', $ids)->delete();

    if (! $keepGroup) {
        DB::table('menus')->whereNull('parent_id')->where('name', 'CRM')->where('type', 2)->delete();
    }
}

test('the menu migration creates the CRM group and the Leads page with the hidden permission rows', function () {
    crmResetLeadsMenu(keepGroup: false);
    crmLeadsMigration()->up();

    $group = DB::table('menus')->whereNull('parent_id')->where('name', 'CRM')->where('type', 2)->first();

    expect($group)->not->toBeNull();

    $page = DB::table('menus')->where('route_path', '/leads')->first();

    expect($page)->not->toBeNull()
        ->and((int) $page->parent_id)->toBe($group->id)
        ->and($page->route_name)->toBe('leads')
        ->and((int) $page->is_hidden)->toBe(0)
        ->and((int) $page->is_active)->toBe(1);

    $hidden = DB::table('menus')->where('parent_id', $page->id)->orderBy('sort_order')->get();

    expect($hidden->pluck('route_path')->all())->toBe([
        '/leads/add',
        '/leads/:id/edit',
        '/leads/delete',
        '/leads/:id/view',
        '/leads/restore',
        '/leads/export',
    ])->and($hidden->every(fn (object $row): bool => (int) $row->is_hidden === 1 && (int) $row->is_active === 1))->toBeTrue();
});

test('the menu migration reuses an existing CRM group instead of creating a second one', function () {
    crmResetLeadsMenu(keepGroup: true);
    $groupId = DB::table('menus')->whereNull('parent_id')->where('name', 'CRM')->where('type', 2)->value('id');

    crmLeadsMigration()->up();

    expect(DB::table('menus')->whereNull('parent_id')->where('name', 'CRM')->where('type', 2)->count())->toBe(1)
        ->and((int) DB::table('menus')->where('route_path', '/leads')->value('parent_id'))->toBe($groupId);
});

test('the menu migration is idempotent and grants nothing to any role', function () {
    // 8, not 7: the Lead Import child (2026_10_01_110300_add_leads_import_menu.php) also runs during
    // RefreshDatabase's bootstrap migrate and is already present alongside the original 7 Leads rows.
    $roleId = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true])->id;

    crmLeadsMigration()->up();
    $menuRows = DB::table('menus')->where('route_path', '/leads')->orWhere('route_path', 'like', '/leads/%')->count();
    crmLeadsMigration()->up();

    expect($menuRows)->toBe(8)
        ->and(DB::table('menus')->where('route_path', '/leads')->orWhere('route_path', 'like', '/leads/%')->count())->toBe(8)
        ->and(DB::table('menus')->whereNull('parent_id')->where('name', 'CRM')->count())->toBe(1)
        ->and(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(0);
});

test('rolling the menu migration back removes the Leads rows and, if empty, the CRM group', function () {
    // Isolate: Leads must be the CRM group's only child for the "if empty, delete the group" branch
    // to actually fire. Every other CRM page (Lead Sources, Opportunities, Pipeline Board, Pipeline
    // Stages, Activities, and whatever future steps add) also lives under CRM from RefreshDatabase's
    // bootstrap migrate, so the group is never empty after removing Leads alone unless cleared first —
    // deleting every CRM-group child except /leads keeps this test from rotting as more CRM pages ship.
    $groupId = DB::table('menus')->whereNull('parent_id')->where('name', 'CRM')->where('type', 2)->value('id');
    $otherTopLevelIds = DB::table('menus')->where('parent_id', $groupId)->where('route_path', '!=', '/leads')->pluck('id');
    $otherIds = $otherTopLevelIds->flatMap(fn ($id) => DB::table('menus')->where('parent_id', $id)->pluck('id')->push($id));
    DB::table('permissions')->whereIn('menu_id', $otherIds)->delete();
    DB::table('menus')->whereIn('id', $otherIds)->delete();
    $roleId = Role::query()->create(['name' => 'accountant', 'company_id' => null, 'is_active' => true])->id;

    crmLeadsMigration()->up();
    $addId = (int) DB::table('menus')->where('route_path', '/leads/add')->value('id');
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $addId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

    crmLeadsMigration()->down();

    expect(DB::table('menus')->where('route_path', '/leads')->orWhere('route_path', 'like', '/leads/%')->count())->toBe(0)
        ->and(DB::table('permissions')->where('menu_id', $addId)->count())->toBe(0)
        ->and(DB::table('menus')->whereNull('parent_id')->where('name', 'CRM')->count())->toBe(0);
});

test('rolling the menu migration back keeps the CRM group when Lead Sources is still in it', function () {
    crmLeadsMigration()->up();

    crmLeadsMigration()->down();

    expect(DB::table('menus')->where('route_path', '/leads')->orWhere('route_path', 'like', '/leads/%')->count())->toBe(0)
        ->and(DB::table('menus')->whereNull('parent_id')->where('name', 'CRM')->count())->toBe(1)
        ->and(DB::table('menus')->where('route_path', '/leadsources')->exists())->toBeTrue();
});

test('the menu migration clears the cached menu permissions of the roles', function () {
    crmResetLeadsMenu(keepGroup: true);
    $roleId = Role::query()->create(['name' => 'accountant', 'company_id' => null, 'is_active' => true])->id;

    foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
        Cache::put("{$key}:{$roleId}", ['stale'], 600);
    }

    crmLeadsMigration()->up();

    foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
        expect(Cache::has("{$key}:{$roleId}"))->toBeFalse();
    }
});

test('guests are sent away from the leads pages', function (string $routeName, array $parameters) {
    $this->get(route($routeName, $parameters))->assertRedirect();
})->with([
    'leads list' => ['leads', []],
    'leads add' => ['leads.add', []],
    'leads edit' => ['leads.edit', [3]],
    'leads view' => ['leads.view', [3]],
    'leads trash' => ['leads.trash', []],
]);

test('the superadmin can open every leads page', function (string $routeName, array $parameters, string $component) {
    $this->actingAs(User::query()->findOrFail(1))
        ->get(route($routeName, $parameters))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component($component));
})->with([
    'leads list' => ['leads', [], 'lead/index'],
    'leads add' => ['leads.add', [], 'lead/add'],
    'leads edit' => ['leads.edit', [3], 'lead/edit'],
    'leads view' => ['leads.view', [3], 'lead/view'],
    'leads trash' => ['leads.trash', [], 'lead/trash'],
]);

test('a user without the menu permission gets a 403 on each leads page', function (string $routeName, array $parameters) {
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true]);

    $this->actingAs(createStaffUserForRole($role))
        ->get(route($routeName, $parameters))
        ->assertForbidden();
})->with([
    'leads list' => ['leads', []],
    'leads add' => ['leads.add', []],
    'leads edit' => ['leads.edit', [3]],
    'leads view' => ['leads.view', [3]],
    'leads trash' => ['leads.trash', []],
]);

test('a user is let into exactly the leads pages their menu permissions name', function () {
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true]);
    grantMenuPermission($role->id, '/leads');
    grantMenuPermission($role->id, '/leads/:id/view');
    $user = createStaffUserForRole($role);

    $this->actingAs($user)->get(route('leads'))->assertSuccessful();
    $this->actingAs($user)->get(route('leads.view', 3))->assertSuccessful();
    $this->actingAs($user)->get(route('leads.add'))->assertForbidden();
    $this->actingAs($user)->get(route('leads.edit', 3))->assertForbidden();
});
