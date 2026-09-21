<?php

use App\Models\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The migration that gives the global companyadmin role the Stock Take page and its hidden rows. The menu rows
 * come from 2026_09_21_230100_add_stocktake_menu, which finds its group through a legacy page row that exists only in live data,
 * so the tests seed that anchor and run it.
 */
function stkmGrant(): object
{
    return require database_path('migrations/2026_09_21_230200_grant_companyadmin_stocktake_menu.php');
}

function stkmSeedMenus(): void
{
    DB::table('menus')->where('route_path', '/product')->delete();

    $row = fn (array $attributes): array => $attributes + [
        'icon' => 'Grid', 'menu_color' => '#199683', 'sort_order' => 5, 'is_hidden' => 0, 'is_active' => 1,
        'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ];

    $groupId = DB::table('menus')->insertGetId($row([
        'parent_id' => null, 'name' => 'Products', 'route_name' => 'stkm-grant-group', 'route_path' => '#stkm-grant-group',
    ]));
    DB::table('menus')->insert($row(['parent_id' => $groupId, 'name' => 'Legacy page', 'route_name' => 'legacy-stkm-anchor', 'route_path' => '/product']));

    (require database_path('migrations/2026_09_21_230100_add_stocktake_menu.php'))->up();
}

function stkmRole(string $name = 'companyadmin', ?int $companyId = null): int
{
    return DB::table('roles')->insertGetId([
        'name' => $name, 'company_id' => $companyId, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * @return list<int>
 */
function stkmMenuIds(): array
{
    return DB::table('menus')->where('route_path', 'like', '/stocktake%')->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
}

test('the menu rows this grants exist: the page and 7 hidden rows', function () {
    stkmSeedMenus();

    expect(stkmMenuIds())->toHaveCount(8)
        ->and(DB::table('menus')->whereIn('id', stkmMenuIds())->where('is_hidden', 1)->count())->toBe(7);
});

test('the global companyadmin role gets exactly the 8 rows', function () {
    stkmSeedMenus();
    $roleId = stkmRole();

    stkmGrant()->up();

    expect(DB::table('permissions')->where('role_id', $roleId)->orderBy('menu_id')->pluck('menu_id')->map(fn (mixed $id): int => (int) $id)->all())->toBe(stkmMenuIds())
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('status', 1)->count())->toBe(8);

    $row = DB::table('permissions')->where('role_id', $roleId)->first();

    expect($row->company_id)->toBeNull()
        ->and($row->branch_id)->toBeNull()
        ->and($row->department_id)->toBeNull();
});

test('other roles and company specific admin roles are left alone', function () {
    stkmSeedMenus();
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'stkm001', 'name' => 'Grant Company', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    stkmRole('test role', $companyId);
    stkmRole('companyadmin', $companyId);
    stkmRole('superadmin');
    stkmRole('accountant');

    stkmGrant()->up();

    expect(DB::table('permissions')->count())->toBe(0);
});

test('running it twice keeps one enabled row per menu and re-enables a denied one', function () {
    stkmSeedMenus();
    $roleId = stkmRole();
    [$denied, $kept] = stkmMenuIds();

    DB::table('permissions')->insert([
        ['role_id' => $roleId, 'menu_id' => $denied, 'status' => 0, 'created_at' => now(), 'updated_at' => now()],
        ['role_id' => $roleId, 'menu_id' => $kept, 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);

    stkmGrant()->up();
    stkmGrant()->up();

    expect(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(8)
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('menu_id', $denied)->value('status'))->toBe(1)
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('status', 1)->count())->toBe(8);
});

test('an existing permission of the role on another menu is not touched', function () {
    stkmSeedMenus();
    $roleId = stkmRole();
    $anchorId = (int) DB::table('menus')->where('route_path', '/product')->value('id');
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $anchorId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

    stkmGrant()->up();

    expect(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(9)
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('menu_id', $anchorId)->where('status', 1)->count())->toBe(1);
});

test('without the role or without the menu rows the migration does nothing, and a missing row is skipped', function () {
    stkmGrant()->up();
    expect(DB::table('permissions')->count())->toBe(0);

    stkmSeedMenus();
    stkmGrant()->up();
    expect(DB::table('permissions')->count())->toBe(0);

    $roleId = stkmRole();
    DB::table('menus')->where('route_path', '/stocktake/export')->delete();

    stkmGrant()->up();
    expect(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(7);

    DB::table('permissions')->delete();
    DB::table('menus')->whereIn('id', stkmMenuIds())->delete();
    stkmGrant()->up();

    expect(DB::table('permissions')->count())->toBe(0);
});

test('the granted paths show up for the role and the menu caches are flushed', function () {
    stkmSeedMenus();
    $roleId = stkmRole();
    $keys = ["user_permission_paths:{$roleId}", "user_menu_permissions_tree:{$roleId}", "user_menu_permissions:{$roleId}"];

    foreach ($keys as $key) {
        Cache::put($key, ['stale'], 600);
    }

    stkmGrant()->up();

    foreach ($keys as $key) {
        expect(Cache::has($key))->toBeFalse();
    }

    expect(Menu::permittedRoutePathsForRole($roleId))->toContain('/stocktake', '/stocktake/add', '/stocktake/export');
});

test('rolling back removes only those rows', function () {
    stkmSeedMenus();
    $roleId = stkmRole();
    $otherRole = stkmRole('accountant');
    $anchorId = (int) DB::table('menus')->where('route_path', '/product')->value('id');
    $pageId = (int) DB::table('menus')->where('route_path', '/stocktake')->value('id');
    DB::table('permissions')->insert([
        ['role_id' => $roleId, 'menu_id' => $anchorId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['role_id' => $otherRole, 'menu_id' => $pageId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);

    stkmGrant()->up();
    stkmGrant()->down();

    expect(DB::table('permissions')->where('role_id', $roleId)->pluck('menu_id')->map(fn (mixed $id): int => (int) $id)->all())->toBe([$anchorId])
        ->and(DB::table('permissions')->where('role_id', $otherRole)->where('menu_id', $pageId)->where('status', 1)->exists())->toBeTrue();
});
