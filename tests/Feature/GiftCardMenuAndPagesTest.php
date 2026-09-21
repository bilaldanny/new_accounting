<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The Gift Card menu migration and the Gift Card pages. The migration finds its group through a legacy page
 * row (`/sell`) that exists only in live data, so the tests seed that first.
 */
function gcmMigration(): object
{
    return require database_path('migrations/2026_09_21_210100_add_giftcard_menu.php');
}

/**
 * A menu group with one legacy page in it, the way the live data has it. Returns the group's id.
 */
function gcmSeedGroup(): int
{
    DB::table('menus')->where('route_path', '/sell')->delete();

    $row = fn (array $attributes): array => $attributes + [
        'icon' => 'Grid', 'menu_color' => '#199683', 'sort_order' => 5, 'is_hidden' => 0, 'is_active' => 1,
        'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ];

    $groupId = DB::table('menus')->insertGetId($row([
        'parent_id' => null, 'name' => 'Sell', 'route_name' => 'giftcard-test-group', 'route_path' => '#giftcard-test-group',
    ]));

    DB::table('menus')->insert($row([
        'parent_id' => $groupId, 'name' => 'Legacy page', 'route_name' => 'legacy-giftcard-anchor', 'route_path' => '/sell', 'sort_order' => 9,
    ]));

    return $groupId;
}

test('the menu migration adds the page in its group with the hidden permission rows', function () {
    $groupId = gcmSeedGroup();

    gcmMigration()->up();

    $page = DB::table('menus')->where('route_path', '/giftcard')->first();

    expect($page)->not->toBeNull()
        ->and((int) $page->parent_id)->toBe($groupId)
        ->and($page->icon)->toBe('Wallet')
        ->and($page->route_name)->toBe('giftcard')
        ->and((int) $page->is_hidden)->toBe(0)
        ->and((int) $page->is_active)->toBe(1)
        ->and((int) $page->sort_order)->toBe(10);

    $hidden = DB::table('menus')->where('parent_id', $page->id)->orderBy('sort_order')->get();

    expect($hidden->pluck('route_path')->all())->toBe([
        '/giftcard/add',
        '/giftcard/:id/edit',
        '/giftcard/delete',
        '/giftcard/:id/view',
        '/giftcard/restore',
        '/giftcard/export',
        '/giftcard/redeem',
        '/giftcard/topup',
    ])->and($hidden->every(fn (object $row): bool => (int) $row->is_hidden === 1 && (int) $row->is_active === 1))->toBeTrue();
});

test('the menu migration is idempotent and grants nothing to any role', function () {
    gcmSeedGroup();
    $roleId = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true])->id;

    gcmMigration()->up();
    gcmMigration()->up();

    expect(DB::table('menus')->where('route_path', 'like', '/giftcard%')->count())->toBe(9)
        ->and(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(0);
});

test('the menu migration does nothing when the group cannot be found', function () {
    DB::table('menus')->where('route_path', '/sell')->delete();

    gcmMigration()->up();

    expect(DB::table('menus')->where('route_path', 'like', '/giftcard%')->count())->toBe(0);
});

test('rolling the menu migration back removes the rows and the permissions on them', function () {
    gcmSeedGroup();
    $roleId = Role::query()->create(['name' => 'accountant', 'company_id' => null, 'is_active' => true])->id;

    gcmMigration()->up();
    $addId = (int) DB::table('menus')->where('route_path', '/giftcard/add')->value('id');
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $addId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

    gcmMigration()->down();

    expect(DB::table('menus')->where('route_path', 'like', '/giftcard%')->count())->toBe(0)
        ->and(DB::table('permissions')->where('menu_id', $addId)->count())->toBe(0)
        ->and(DB::table('menus')->where('route_path', '/sell')->exists())->toBeTrue();
});

test('the menu migration clears the cached menu permissions of the roles', function () {
    gcmSeedGroup();
    $roleId = Role::query()->create(['name' => 'accountant', 'company_id' => null, 'is_active' => true])->id;

    foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
        Cache::put("{$key}:{$roleId}", ['stale'], 600);
    }

    gcmMigration()->up();

    foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
        expect(Cache::has("{$key}:{$roleId}"))->toBeFalse();
    }
});

test('guests are sent away from the giftcard pages', function (string $routeName, array $parameters) {
    $this->get(route($routeName, $parameters))->assertRedirect();
})->with([
    'list' => ['giftcard', []],
    'add' => ['giftcard.add', []],
    'edit' => ['giftcard.edit', [3]],
    'view' => ['giftcard.view', [3]],
    'trash' => ['giftcard.trash', []],
]);

test('the superadmin can open every giftcard page', function (string $routeName, array $parameters, string $component) {
    $this->actingAs(User::query()->findOrFail(1))
        ->get(route($routeName, $parameters))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component($component));
})->with([
    'list' => ['giftcard', [], 'giftcard/index'],
    'add' => ['giftcard.add', [], 'giftcard/add'],
    'edit' => ['giftcard.edit', [3], 'giftcard/edit'],
    'view' => ['giftcard.view', [3], 'giftcard/view'],
    'trash' => ['giftcard.trash', [], 'giftcard/trash'],
]);

test('the edit and view pages pass the record id', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)->get(route('giftcard.view', 12))
        ->assertInertia(fn ($page) => $page->component('giftcard/view')->where('id', '12'));
    $this->actingAs($superadmin)->get(route('giftcard.edit', 12))
        ->assertInertia(fn ($page) => $page->component('giftcard/edit')->where('id', '12'));
});

test('a user without the menu permission gets a 403 on each page', function (string $routeName, array $parameters) {
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true]);

    $this->actingAs(createStaffUserForRole($role))
        ->get(route($routeName, $parameters))
        ->assertForbidden();
})->with([
    'list' => ['giftcard', []],
    'add' => ['giftcard.add', []],
    'edit' => ['giftcard.edit', [3]],
    'view' => ['giftcard.view', [3]],
    'trash' => ['giftcard.trash', []],
]);

test('a user is let into exactly the pages their menu permissions name', function () {
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true]);
    grantMenuPermission($role->id, '/giftcard');
    grantMenuPermission($role->id, '/giftcard/:id/view');
    $user = createStaffUserForRole($role);

    $this->actingAs($user)->get(route('giftcard'))->assertSuccessful();
    $this->actingAs($user)->get(route('giftcard.view', 3))->assertSuccessful();
    $this->actingAs($user)->get(route('giftcard.add'))->assertForbidden();
    $this->actingAs($user)->get(route('giftcard.edit', 3))->assertForbidden();
    $this->actingAs($user)->get(route('giftcard.trash'))->assertForbidden();
});
