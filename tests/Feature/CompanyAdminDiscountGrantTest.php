<?php

use App\Models\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The migration that gives the global companyadmin role the Discount page and its hidden rows. The menu rows
 * come from 2026_09_21_200100_add_discount_menu, which finds its group through a legacy page row that exists only in live data,
 * so the tests seed that anchor and run it.
 */
function dsgGrant(): object
{
    return require database_path('migrations/2026_09_21_200200_grant_companyadmin_discount_menu.php');
}

function dsgSeedMenus(): void
{
    DB::table('menus')->where('route_path', '/sell')->delete();

    $row = fn (array $attributes): array => $attributes + [
        'icon' => 'Grid', 'menu_color' => '#199683', 'sort_order' => 5, 'is_hidden' => 0, 'is_active' => 1,
        'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ];

    $groupId = DB::table('menus')->insertGetId($row([
        'parent_id' => null, 'name' => 'Sell', 'route_name' => 'dsg-grant-group', 'route_path' => '#dsg-grant-group',
    ]));
    DB::table('menus')->insert($row(['parent_id' => $groupId, 'name' => 'Legacy page', 'route_name' => 'legacy-dsg-anchor', 'route_path' => '/sell']));

    (require database_path('migrations/2026_09_21_200100_add_discount_menu.php'))->up();
}

function dsgRole(string $name = 'companyadmin', ?int $companyId = null): int
{
    return DB::table('roles')->insertGetId([
        'name' => $name, 'company_id' => $companyId, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * @return list<int>
 */
function dsgMenuIds(): array
{
    return DB::table('menus')->where('route_path', 'like', '/discount%')->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
}

test('the menu rows this grants exist: the page and 6 hidden rows', function () {
    dsgSeedMenus();

    expect(dsgMenuIds())->toHaveCount(7)
        ->and(DB::table('menus')->whereIn('id', dsgMenuIds())->where('is_hidden', 1)->count())->toBe(6);
});

test('the global companyadmin role gets exactly the 7 rows', function () {
    dsgSeedMenus();
    $roleId = dsgRole();

    dsgGrant()->up();

    expect(DB::table('permissions')->where('role_id', $roleId)->orderBy('menu_id')->pluck('menu_id')->map(fn (mixed $id): int => (int) $id)->all())->toBe(dsgMenuIds())
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('status', 1)->count())->toBe(7);

    $row = DB::table('permissions')->where('role_id', $roleId)->first();

    expect($row->company_id)->toBeNull()
        ->and($row->branch_id)->toBeNull()
        ->and($row->department_id)->toBeNull();
});

test('other roles and company specific admin roles are left alone', function () {
    dsgSeedMenus();
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'dsg001', 'name' => 'Grant Company', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    dsgRole('test role', $companyId);
    dsgRole('companyadmin', $companyId);
    dsgRole('superadmin');
    dsgRole('accountant');

    dsgGrant()->up();

    expect(DB::table('permissions')->count())->toBe(0);
});

test('running it twice keeps one enabled row per menu and re-enables a denied one', function () {
    dsgSeedMenus();
    $roleId = dsgRole();
    [$denied, $kept] = dsgMenuIds();

    DB::table('permissions')->insert([
        ['role_id' => $roleId, 'menu_id' => $denied, 'status' => 0, 'created_at' => now(), 'updated_at' => now()],
        ['role_id' => $roleId, 'menu_id' => $kept, 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);

    dsgGrant()->up();
    dsgGrant()->up();

    expect(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(7)
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('menu_id', $denied)->value('status'))->toBe(1)
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('status', 1)->count())->toBe(7);
});

test('an existing permission of the role on another menu is not touched', function () {
    dsgSeedMenus();
    $roleId = dsgRole();
    $anchorId = (int) DB::table('menus')->where('route_path', '/sell')->value('id');
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $anchorId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

    dsgGrant()->up();

    expect(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(8)
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('menu_id', $anchorId)->where('status', 1)->count())->toBe(1);
});

test('without the role or without the menu rows the migration does nothing, and a missing row is skipped', function () {
    dsgGrant()->up();
    expect(DB::table('permissions')->count())->toBe(0);

    dsgSeedMenus();
    dsgGrant()->up();
    expect(DB::table('permissions')->count())->toBe(0);

    $roleId = dsgRole();
    DB::table('menus')->where('route_path', '/discount/export')->delete();

    dsgGrant()->up();
    expect(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(6);

    DB::table('permissions')->delete();
    DB::table('menus')->whereIn('id', dsgMenuIds())->delete();
    dsgGrant()->up();

    expect(DB::table('permissions')->count())->toBe(0);
});

test('the granted paths show up for the role and the menu caches are flushed', function () {
    dsgSeedMenus();
    $roleId = dsgRole();
    $keys = ["user_permission_paths:{$roleId}", "user_menu_permissions_tree:{$roleId}", "user_menu_permissions:{$roleId}"];

    foreach ($keys as $key) {
        Cache::put($key, ['stale'], 600);
    }

    dsgGrant()->up();

    foreach ($keys as $key) {
        expect(Cache::has($key))->toBeFalse();
    }

    expect(Menu::permittedRoutePathsForRole($roleId))->toContain('/discount', '/discount/export', '/discount/:id/edit');
});

test('rolling back removes only those rows', function () {
    dsgSeedMenus();
    $roleId = dsgRole();
    $otherRole = dsgRole('accountant');
    $anchorId = (int) DB::table('menus')->where('route_path', '/sell')->value('id');
    $pageId = (int) DB::table('menus')->where('route_path', '/discount')->value('id');
    DB::table('permissions')->insert([
        ['role_id' => $roleId, 'menu_id' => $anchorId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['role_id' => $otherRole, 'menu_id' => $pageId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);

    dsgGrant()->up();
    dsgGrant()->down();

    expect(DB::table('permissions')->where('role_id', $roleId)->pluck('menu_id')->map(fn (mixed $id): int => (int) $id)->all())->toBe([$anchorId])
        ->and(DB::table('permissions')->where('role_id', $otherRole)->where('menu_id', $pageId)->where('status', 1)->exists())->toBeTrue();
});
