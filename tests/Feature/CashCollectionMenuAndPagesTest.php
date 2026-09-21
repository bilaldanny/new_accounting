<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The Cash Collection menu migration and the Cash Collection pages. The migration finds its group through a legacy page
 * row (`/sell`) that exists only in live data, so the tests seed that first.
 */
function ccmMigration(): object
{
    return require database_path('migrations/2026_09_21_260100_add_cashcollection_menu.php');
}

/**
 * A menu group with one legacy page in it, the way the live data has it. Returns the group's id.
 */
function ccmSeedGroup(): int
{
    DB::table('menus')->where('route_path', '/sell')->delete();

    $row = fn (array $attributes): array => $attributes + [
        'icon' => 'Grid', 'menu_color' => '#199683', 'sort_order' => 5, 'is_hidden' => 0, 'is_active' => 1,
        'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ];

    $groupId = DB::table('menus')->insertGetId($row([
        'parent_id' => null, 'name' => 'Sell', 'route_name' => 'cashcollection-test-group', 'route_path' => '#cashcollection-test-group',
    ]));

    DB::table('menus')->insert($row([
        'parent_id' => $groupId, 'name' => 'Legacy page', 'route_name' => 'legacy-cashcollection-anchor', 'route_path' => '/sell', 'sort_order' => 9,
    ]));

    return $groupId;
}

test('the menu migration adds the page in its group with the hidden permission rows', function () {
    $groupId = ccmSeedGroup();

    ccmMigration()->up();

    $page = DB::table('menus')->where('route_path', '/cashcollection')->first();

    expect($page)->not->toBeNull()
        ->and((int) $page->parent_id)->toBe($groupId)
        ->and($page->icon)->toBe('Wallet')
        ->and($page->route_name)->toBe('cashcollection')
        ->and((int) $page->is_hidden)->toBe(0)
        ->and((int) $page->is_active)->toBe(1)
        ->and((int) $page->sort_order)->toBe(10);

    $hidden = DB::table('menus')->where('parent_id', $page->id)->orderBy('sort_order')->get();

    expect($hidden->pluck('route_path')->all())->toBe([
        '/cashcollection/add',
        '/cashcollection/:id/edit',
        '/cashcollection/delete',
        '/cashcollection/:id/view',
        '/cashcollection/restore',
        '/cashcollection/export',
        '/cashcollection/complete',
        '/cashcollection/cancel',
    ])->and($hidden->every(fn (object $row): bool => (int) $row->is_hidden === 1 && (int) $row->is_active === 1))->toBeTrue();
});

test('the menu migration is idempotent and grants nothing to any role', function () {
    ccmSeedGroup();
    $roleId = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true])->id;

    ccmMigration()->up();
    ccmMigration()->up();

    expect(DB::table('menus')->where('route_path', 'like', '/cashcollection%')->count())->toBe(9)
        ->and(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(0);
});

test('the menu migration does nothing when the group cannot be found', function () {
    DB::table('menus')->where('route_path', '/sell')->delete();

    ccmMigration()->up();

    expect(DB::table('menus')->where('route_path', 'like', '/cashcollection%')->count())->toBe(0);
});

test('rolling the menu migration back removes the rows and the permissions on them', function () {
    ccmSeedGroup();
    $roleId = Role::query()->create(['name' => 'accountant', 'company_id' => null, 'is_active' => true])->id;

    ccmMigration()->up();
    $addId = (int) DB::table('menus')->where('route_path', '/cashcollection/add')->value('id');
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $addId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

    ccmMigration()->down();

    expect(DB::table('menus')->where('route_path', 'like', '/cashcollection%')->count())->toBe(0)
        ->and(DB::table('permissions')->where('menu_id', $addId)->count())->toBe(0)
        ->and(DB::table('menus')->where('route_path', '/sell')->exists())->toBeTrue();
});

test('the menu migration clears the cached menu permissions of the roles', function () {
    ccmSeedGroup();
    $roleId = Role::query()->create(['name' => 'accountant', 'company_id' => null, 'is_active' => true])->id;

    foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
        Cache::put("{$key}:{$roleId}", ['stale'], 600);
    }

    ccmMigration()->up();

    foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
        expect(Cache::has("{$key}:{$roleId}"))->toBeFalse();
    }
});

test('guests are sent away from the cashcollection pages', function (string $routeName, array $parameters) {
    $this->get(route($routeName, $parameters))->assertRedirect();
})->with([
    'list' => ['cashcollection', []],
    'add' => ['cashcollection.add', []],
    'edit' => ['cashcollection.edit', [3]],
    'view' => ['cashcollection.view', [3]],
    'trash' => ['cashcollection.trash', []],
]);

test('the superadmin can open every cashcollection page', function (string $routeName, array $parameters, string $component) {
    $this->actingAs(User::query()->findOrFail(1))
        ->get(route($routeName, $parameters))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component($component));
})->with([
    'list' => ['cashcollection', [], 'cashcollection/index'],
    'add' => ['cashcollection.add', [], 'cashcollection/add'],
    'edit' => ['cashcollection.edit', [3], 'cashcollection/edit'],
    'view' => ['cashcollection.view', [3], 'cashcollection/view'],
    'trash' => ['cashcollection.trash', [], 'cashcollection/trash'],
]);

test('the edit and view pages pass the record id', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)->get(route('cashcollection.view', 12))
        ->assertInertia(fn ($page) => $page->component('cashcollection/view')->where('id', '12'));
    $this->actingAs($superadmin)->get(route('cashcollection.edit', 12))
        ->assertInertia(fn ($page) => $page->component('cashcollection/edit')->where('id', '12'));
});

test('a user without the menu permission gets a 403 on each page', function (string $routeName, array $parameters) {
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true]);

    $this->actingAs(createStaffUserForRole($role))
        ->get(route($routeName, $parameters))
        ->assertForbidden();
})->with([
    'list' => ['cashcollection', []],
    'add' => ['cashcollection.add', []],
    'edit' => ['cashcollection.edit', [3]],
    'view' => ['cashcollection.view', [3]],
    'trash' => ['cashcollection.trash', []],
]);

test('a user is let into exactly the pages their menu permissions name', function () {
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true]);
    grantMenuPermission($role->id, '/cashcollection');
    grantMenuPermission($role->id, '/cashcollection/:id/view');
    $user = createStaffUserForRole($role);

    $this->actingAs($user)->get(route('cashcollection'))->assertSuccessful();
    $this->actingAs($user)->get(route('cashcollection.view', 3))->assertSuccessful();
    $this->actingAs($user)->get(route('cashcollection.add'))->assertForbidden();
    $this->actingAs($user)->get(route('cashcollection.edit', 3))->assertForbidden();
    $this->actingAs($user)->get(route('cashcollection.trash'))->assertForbidden();
});
