<?php

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The Stock Take menu migration and the Stock Take pages. The migration finds its group through a legacy page
 * row (`/product`) that exists only in live data, so the tests seed that first.
 */
function stkmMigration(): object
{
    return require database_path('migrations/2026_09_21_230100_add_stocktake_menu.php');
}

/**
 * A menu group with one legacy page in it, the way the live data has it. Returns the group's id.
 */
function stkmSeedGroup(): int
{
    DB::table('menus')->where('route_path', '/product')->delete();

    $row = fn (array $attributes): array => $attributes + [
        'icon' => 'Grid', 'menu_color' => '#199683', 'sort_order' => 5, 'is_hidden' => 0, 'is_active' => 1,
        'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ];

    $groupId = DB::table('menus')->insertGetId($row([
        'parent_id' => null, 'name' => 'Products', 'route_name' => 'stocktake-test-group', 'route_path' => '#stocktake-test-group',
    ]));

    DB::table('menus')->insert($row([
        'parent_id' => $groupId, 'name' => 'Legacy page', 'route_name' => 'legacy-stocktake-anchor', 'route_path' => '/product', 'sort_order' => 9,
    ]));

    return $groupId;
}

test('the menu migration adds the page in its group with the hidden permission rows', function () {
    $groupId = stkmSeedGroup();

    stkmMigration()->up();

    $page = DB::table('menus')->where('route_path', '/stocktake')->first();

    expect($page)->not->toBeNull()
        ->and((int) $page->parent_id)->toBe($groupId)
        ->and($page->icon)->toBe('Layers')
        ->and($page->route_name)->toBe('stocktake')
        ->and((int) $page->is_hidden)->toBe(0)
        ->and((int) $page->is_active)->toBe(1)
        ->and((int) $page->sort_order)->toBe(10);

    $hidden = DB::table('menus')->where('parent_id', $page->id)->orderBy('sort_order')->get();

    expect($hidden->pluck('route_path')->all())->toBe([
        '/stocktake/add',
        '/stocktake/:id/view',
        '/stocktake/count',
        '/stocktake/complete',
        '/stocktake/delete',
        '/stocktake/restore',
        '/stocktake/export',
    ])->and($hidden->every(fn (object $row): bool => (int) $row->is_hidden === 1 && (int) $row->is_active === 1))->toBeTrue();
});

test('the menu migration is idempotent and grants nothing to any role', function () {
    stkmSeedGroup();
    $roleId = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true])->id;

    stkmMigration()->up();
    stkmMigration()->up();

    expect(DB::table('menus')->where('route_path', 'like', '/stocktake%')->count())->toBe(8)
        ->and(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(0);
});

test('the menu migration does nothing when the group cannot be found', function () {
    DB::table('menus')->where('route_path', '/product')->delete();

    stkmMigration()->up();

    expect(DB::table('menus')->where('route_path', 'like', '/stocktake%')->count())->toBe(0);
});

test('rolling the menu migration back removes the rows and the permissions on them', function () {
    stkmSeedGroup();
    $roleId = Role::query()->create(['name' => 'accountant', 'company_id' => null, 'is_active' => true])->id;

    stkmMigration()->up();
    $addId = (int) DB::table('menus')->where('route_path', '/stocktake/add')->value('id');
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $addId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

    stkmMigration()->down();

    expect(DB::table('menus')->where('route_path', 'like', '/stocktake%')->count())->toBe(0)
        ->and(DB::table('permissions')->where('menu_id', $addId)->count())->toBe(0)
        ->and(DB::table('menus')->where('route_path', '/product')->exists())->toBeTrue();
});

test('the menu migration clears the cached menu permissions of the roles', function () {
    stkmSeedGroup();
    $roleId = Role::query()->create(['name' => 'accountant', 'company_id' => null, 'is_active' => true])->id;

    foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
        Cache::put("{$key}:{$roleId}", ['stale'], 600);
    }

    stkmMigration()->up();

    foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
        expect(Cache::has("{$key}:{$roleId}"))->toBeFalse();
    }
});
