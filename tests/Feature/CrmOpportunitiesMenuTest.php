<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The CRM > Opportunities / Pipeline Board / Pipeline Stages menu migration. Adds three pages to the
 * CRM group the Leads migration creates. Like the other CRM menu migrations, it already ran once as
 * part of RefreshDatabase's bootstrap `migrate`, so tests that need a "not yet run" starting point
 * reset first.
 */
function crmOpportunitiesMigration(): object
{
    return require database_path('migrations/2026_10_01_120200_add_crm_opportunities_menu.php');
}

function crmResetOpportunitiesMenu(): void
{
    foreach (['/opportunities', '/pipeline', '/pipelinestages'] as $path) {
        $ids = DB::table('menus')->where('route_path', $path)->orWhere('route_path', 'like', $path.'/%')->pluck('id');
        DB::table('permissions')->whereIn('menu_id', $ids)->delete();
        DB::table('menus')->whereIn('id', $ids)->delete();
    }
}

test('the menu migration adds all three pages under the existing CRM group', function () {
    crmResetOpportunitiesMenu();
    crmOpportunitiesMigration()->up();

    $groupId = DB::table('menus')->whereNull('parent_id')->where('name', 'CRM')->where('type', 2)->value('id');

    $opportunities = DB::table('menus')->where('route_path', '/opportunities')->first();
    $pipeline = DB::table('menus')->where('route_path', '/pipeline')->first();
    $stages = DB::table('menus')->where('route_path', '/pipelinestages')->first();

    expect((int) $opportunities->parent_id)->toBe($groupId)
        ->and((int) $pipeline->parent_id)->toBe($groupId)
        ->and((int) $stages->parent_id)->toBe($groupId)
        ->and($opportunities->route_name)->toBe('opportunities')
        ->and($pipeline->route_name)->toBe('pipeline')
        ->and($stages->route_name)->toBe('pipelinestages');

    $opportunityChildren = DB::table('menus')->where('parent_id', $opportunities->id)->orderBy('sort_order')->pluck('route_path')->all();
    expect($opportunityChildren)->toBe([
        '/opportunities/add', '/opportunities/:id/edit', '/opportunities/delete',
        '/opportunities/:id/view', '/opportunities/restore', '/opportunities/export',
    ]);

    expect(DB::table('menus')->where('parent_id', $pipeline->id)->count())->toBe(0);

    $stageChildren = DB::table('menus')->where('parent_id', $stages->id)->orderBy('sort_order')->pluck('route_path')->all();
    expect($stageChildren)->toBe([
        '/pipelinestages/add', '/pipelinestages/:id/edit', '/pipelinestages/delete',
        '/pipelinestages/:id/view', '/pipelinestages/restore', '/pipelinestages/export',
    ]);
});

test('the menu migration does nothing when the CRM group is missing', function () {
    crmResetOpportunitiesMenu();
    DB::table('menus')->whereNull('parent_id')->where('name', 'CRM')->where('type', 2)->delete();

    crmOpportunitiesMigration()->up();

    expect(DB::table('menus')->where('route_path', '/opportunities')->exists())->toBeFalse()
        ->and(DB::table('menus')->where('route_path', '/pipeline')->exists())->toBeFalse()
        ->and(DB::table('menus')->where('route_path', '/pipelinestages')->exists())->toBeFalse();
});

test('the menu migration is idempotent and grants nothing to any role', function () {
    $roleId = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true])->id;

    crmOpportunitiesMigration()->up();
    $before = DB::table('menus')->where('route_path', '/opportunities')->orWhere('route_path', 'like', '/opportunities/%')
        ->orWhere('route_path', '/pipeline')
        ->orWhere('route_path', '/pipelinestages')->orWhere('route_path', 'like', '/pipelinestages/%')
        ->count();
    crmOpportunitiesMigration()->up();
    $after = DB::table('menus')->where('route_path', '/opportunities')->orWhere('route_path', 'like', '/opportunities/%')
        ->orWhere('route_path', '/pipeline')
        ->orWhere('route_path', '/pipelinestages')->orWhere('route_path', 'like', '/pipelinestages/%')
        ->count();

    expect($before)->toBe($after)
        ->and(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(0);
});

test('rolling the menu migration back removes exactly its own rows', function () {
    $roleId = Role::query()->create(['name' => 'accountant', 'company_id' => null, 'is_active' => true])->id;

    crmOpportunitiesMigration()->up();
    $addId = (int) DB::table('menus')->where('route_path', '/opportunities/add')->value('id');
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $addId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

    crmOpportunitiesMigration()->down();

    expect(DB::table('menus')->where('route_path', '/opportunities')->exists())->toBeFalse()
        ->and(DB::table('menus')->where('route_path', '/pipeline')->exists())->toBeFalse()
        ->and(DB::table('menus')->where('route_path', '/pipelinestages')->exists())->toBeFalse()
        ->and(DB::table('permissions')->where('menu_id', $addId)->count())->toBe(0)
        // Leads and Lead Sources, added by earlier CRM migrations, are untouched
        ->and(DB::table('menus')->where('route_path', '/leads')->exists())->toBeTrue()
        ->and(DB::table('menus')->where('route_path', '/leadsources')->exists())->toBeTrue();
});

test('the menu migration clears the cached menu permissions of the roles', function () {
    crmResetOpportunitiesMenu();
    $roleId = Role::query()->create(['name' => 'accountant', 'company_id' => null, 'is_active' => true])->id;

    foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
        Cache::put("{$key}:{$roleId}", ['stale'], 600);
    }

    crmOpportunitiesMigration()->up();

    foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
        expect(Cache::has("{$key}:{$roleId}"))->toBeFalse();
    }
});

test('guests are sent away from the opportunities and pipeline pages', function (string $routeName, array $parameters) {
    $this->get(route($routeName, $parameters))->assertRedirect();
})->with([
    'opportunities list' => ['opportunities', []],
    'opportunities add' => ['opportunities.add', []],
    'opportunities edit' => ['opportunities.edit', [3]],
    'opportunities view' => ['opportunities.view', [3]],
    'opportunities trash' => ['opportunities.trash', []],
    'pipeline board' => ['pipeline', []],
    'pipelinestages list' => ['pipelinestages', []],
    'pipelinestages add' => ['pipelinestages.add', []],
]);

test('the superadmin can open every opportunities and pipeline page', function (string $routeName, array $parameters, string $component) {
    $this->actingAs(User::query()->findOrFail(1))
        ->get(route($routeName, $parameters))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component($component));
})->with([
    'opportunities list' => ['opportunities', [], 'opportunity/index'],
    'opportunities add' => ['opportunities.add', [], 'opportunity/add'],
    'opportunities edit' => ['opportunities.edit', [3], 'opportunity/edit'],
    'opportunities view' => ['opportunities.view', [3], 'opportunity/view'],
    'opportunities trash' => ['opportunities.trash', [], 'opportunity/trash'],
    'pipeline board' => ['pipeline', [], 'opportunity/pipeline'],
    'pipelinestages list' => ['pipelinestages', [], 'pipelinestage/index'],
    'pipelinestages add' => ['pipelinestages.add', [], 'pipelinestage/add'],
]);

test('the opportunities add page passes the lead_id query param through as a prop', function () {
    $this->actingAs(User::query()->findOrFail(1))
        ->get('/opportunities/add?lead_id=42')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('opportunity/add')->where('leadId', '42'));
});

test('a user without the menu permission gets a 403 on each page', function (string $routeName, array $parameters) {
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true]);

    $this->actingAs(createStaffUserForRole($role))
        ->get(route($routeName, $parameters))
        ->assertForbidden();
})->with([
    'opportunities list' => ['opportunities', []],
    'pipeline board' => ['pipeline', []],
    'pipelinestages list' => ['pipelinestages', []],
]);
