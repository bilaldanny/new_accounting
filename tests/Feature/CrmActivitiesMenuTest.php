<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The CRM > Activities menu migration. Adds one page to the CRM group the Leads migration creates.
 * Like the other CRM menu migrations, it already ran once as part of RefreshDatabase's bootstrap
 * `migrate`, so tests that need a "not yet run" starting point reset first.
 */
function crmActivitiesMigration(): object
{
    return require database_path('migrations/2026_10_01_130100_add_crm_activities_menu.php');
}

function crmResetActivitiesMenu(): void
{
    $ids = DB::table('menus')->where('route_path', '/activities')->orWhere('route_path', 'like', '/activities/%')->pluck('id');
    DB::table('permissions')->whereIn('menu_id', $ids)->delete();
    DB::table('menus')->whereIn('id', $ids)->delete();
}

test('the menu migration adds Activities under the existing CRM group with the hidden permission rows', function () {
    crmResetActivitiesMenu();
    crmActivitiesMigration()->up();

    $groupId = DB::table('menus')->whereNull('parent_id')->where('name', 'CRM')->where('type', 2)->value('id');
    $page = DB::table('menus')->where('route_path', '/activities')->first();

    expect($page)->not->toBeNull()
        ->and((int) $page->parent_id)->toBe($groupId)
        ->and($page->route_name)->toBe('activities')
        ->and((int) $page->is_hidden)->toBe(0);

    $hidden = DB::table('menus')->where('parent_id', $page->id)->orderBy('sort_order')->pluck('route_path')->all();

    expect($hidden)->toBe([
        '/activities/add', '/activities/:id/edit', '/activities/delete',
        '/activities/:id/view', '/activities/restore', '/activities/export', '/activities/:id/complete',
    ]);
});

test('the menu migration does nothing when the CRM group is missing', function () {
    crmResetActivitiesMenu();
    DB::table('menus')->whereNull('parent_id')->where('name', 'CRM')->where('type', 2)->delete();

    crmActivitiesMigration()->up();

    expect(DB::table('menus')->where('route_path', '/activities')->exists())->toBeFalse();
});

test('the menu migration is idempotent and grants nothing to any role', function () {
    $roleId = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true])->id;

    crmActivitiesMigration()->up();
    $before = DB::table('menus')->where('route_path', '/activities')->orWhere('route_path', 'like', '/activities/%')->count();
    crmActivitiesMigration()->up();
    $after = DB::table('menus')->where('route_path', '/activities')->orWhere('route_path', 'like', '/activities/%')->count();

    expect($before)->toBe($after)
        ->and(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(0);
});

test('rolling the menu migration back removes exactly its own rows', function () {
    $roleId = Role::query()->create(['name' => 'accountant', 'company_id' => null, 'is_active' => true])->id;

    crmActivitiesMigration()->up();
    $addId = (int) DB::table('menus')->where('route_path', '/activities/add')->value('id');
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $addId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

    crmActivitiesMigration()->down();

    expect(DB::table('menus')->where('route_path', '/activities')->exists())->toBeFalse()
        ->and(DB::table('permissions')->where('menu_id', $addId)->count())->toBe(0)
        ->and(DB::table('menus')->where('route_path', '/leads')->exists())->toBeTrue()
        ->and(DB::table('menus')->where('route_path', '/opportunities')->exists())->toBeTrue();
});

test('the menu migration clears the cached menu permissions of the roles', function () {
    crmResetActivitiesMenu();
    $roleId = Role::query()->create(['name' => 'accountant', 'company_id' => null, 'is_active' => true])->id;

    foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
        Cache::put("{$key}:{$roleId}", ['stale'], 600);
    }

    crmActivitiesMigration()->up();

    foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
        expect(Cache::has("{$key}:{$roleId}"))->toBeFalse();
    }
});

test('guests are sent away from the activities pages', function (string $routeName, array $parameters) {
    $this->get(route($routeName, $parameters))->assertRedirect();
})->with([
    'activities list' => ['activities', []],
    'activities add' => ['activities.add', []],
    'activities edit' => ['activities.edit', [3]],
    'activities view' => ['activities.view', [3]],
    'activities trash' => ['activities.trash', []],
]);

test('the superadmin can open every activities page', function (string $routeName, array $parameters, string $component) {
    $this->actingAs(User::query()->findOrFail(1))
        ->get(route($routeName, $parameters))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component($component));
})->with([
    'activities list' => ['activities', [], 'activity/index'],
    'activities add' => ['activities.add', [], 'activity/add'],
    'activities edit' => ['activities.edit', [3], 'activity/edit'],
    'activities view' => ['activities.view', [3], 'activity/view'],
    'activities trash' => ['activities.trash', [], 'activity/trash'],
]);

test('the activities add page passes lead_id/opportunity_id/contact_id query params through as props', function () {
    $this->actingAs(User::query()->findOrFail(1))
        ->get('/activities/add?lead_id=5&opportunity_id=7&contact_id=9')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('activity/add')
            ->where('leadId', '5')
            ->where('opportunityId', '7')
            ->where('contactId', '9'));
});

test('a user without the menu permission gets a 403 on each activities page', function () {
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true]);

    $this->actingAs(createStaffUserForRole($role))
        ->get(route('activities'))
        ->assertForbidden();
});
