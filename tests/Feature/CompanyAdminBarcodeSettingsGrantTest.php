<?php

use App\Models\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The migration that gives the global companyadmin role the Barcode Settings page and its hidden rows. The menu rows
 * come from 2026_09_21_240100_add_barcode_settings_menu, which finds its group through a legacy page row that exists only in live data,
 * so the tests seed that anchor and run it.
 */
function bcsGrant(): object
{
    return require database_path('migrations/2026_09_21_240200_grant_companyadmin_barcode_settings_menu.php');
}

function bcsSeedMenus(): void
{
    DB::table('menus')->where('route_path', '/business/settings')->delete();

    $row = fn (array $attributes): array => $attributes + [
        'icon' => 'Grid', 'menu_color' => '#199683', 'sort_order' => 5, 'is_hidden' => 0, 'is_active' => 1,
        'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ];

    $groupId = DB::table('menus')->insertGetId($row([
        'parent_id' => null, 'name' => 'Company Settings', 'route_name' => 'bcs-grant-group', 'route_path' => '#bcs-grant-group',
    ]));
    DB::table('menus')->insert($row(['parent_id' => $groupId, 'name' => 'Legacy page', 'route_name' => 'legacy-bcs-anchor', 'route_path' => '/business/settings']));

    (require database_path('migrations/2026_09_21_240100_add_barcode_settings_menu.php'))->up();
}

function bcsRole(string $name = 'companyadmin', ?int $companyId = null): int
{
    return DB::table('roles')->insertGetId([
        'name' => $name, 'company_id' => $companyId, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * @return list<int>
 */
function bcsMenuIds(): array
{
    return DB::table('menus')->where('route_path', 'like', '/barcode/settings%')->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
}

test('the menu rows this grants exist: the page and 1 hidden rows', function () {
    bcsSeedMenus();

    expect(bcsMenuIds())->toHaveCount(2)
        ->and(DB::table('menus')->whereIn('id', bcsMenuIds())->where('is_hidden', 1)->count())->toBe(1);
});

test('the global companyadmin role gets exactly the 2 rows', function () {
    bcsSeedMenus();
    $roleId = bcsRole();

    bcsGrant()->up();

    expect(DB::table('permissions')->where('role_id', $roleId)->orderBy('menu_id')->pluck('menu_id')->map(fn (mixed $id): int => (int) $id)->all())->toBe(bcsMenuIds())
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('status', 1)->count())->toBe(2);

    $row = DB::table('permissions')->where('role_id', $roleId)->first();

    expect($row->company_id)->toBeNull()
        ->and($row->branch_id)->toBeNull()
        ->and($row->department_id)->toBeNull();
});

test('other roles and company specific admin roles are left alone', function () {
    bcsSeedMenus();
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'bcs001', 'name' => 'Grant Company', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    bcsRole('test role', $companyId);
    bcsRole('companyadmin', $companyId);
    bcsRole('superadmin');
    bcsRole('accountant');

    bcsGrant()->up();

    expect(DB::table('permissions')->count())->toBe(0);
});

test('running it twice keeps one enabled row per menu and re-enables a denied one', function () {
    bcsSeedMenus();
    $roleId = bcsRole();
    [$denied, $kept] = bcsMenuIds();

    DB::table('permissions')->insert([
        ['role_id' => $roleId, 'menu_id' => $denied, 'status' => 0, 'created_at' => now(), 'updated_at' => now()],
        ['role_id' => $roleId, 'menu_id' => $kept, 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);

    bcsGrant()->up();
    bcsGrant()->up();

    expect(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(2)
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('menu_id', $denied)->value('status'))->toBe(1)
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('status', 1)->count())->toBe(2);
});

test('an existing permission of the role on another menu is not touched', function () {
    bcsSeedMenus();
    $roleId = bcsRole();
    $anchorId = (int) DB::table('menus')->where('route_path', '/business/settings')->value('id');
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $anchorId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

    bcsGrant()->up();

    expect(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(3)
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('menu_id', $anchorId)->where('status', 1)->count())->toBe(1);
});

test('without the role or without the menu rows the migration does nothing, and a missing row is skipped', function () {
    bcsGrant()->up();
    expect(DB::table('permissions')->count())->toBe(0);

    bcsSeedMenus();
    bcsGrant()->up();
    expect(DB::table('permissions')->count())->toBe(0);

    $roleId = bcsRole();
    DB::table('menus')->where('route_path', '/barcode/settings/update')->delete();

    bcsGrant()->up();
    expect(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(1);

    DB::table('permissions')->delete();
    DB::table('menus')->whereIn('id', bcsMenuIds())->delete();
    bcsGrant()->up();

    expect(DB::table('permissions')->count())->toBe(0);
});

test('the granted paths show up for the role and the menu caches are flushed', function () {
    bcsSeedMenus();
    $roleId = bcsRole();
    $keys = ["user_permission_paths:{$roleId}", "user_menu_permissions_tree:{$roleId}", "user_menu_permissions:{$roleId}"];

    foreach ($keys as $key) {
        Cache::put($key, ['stale'], 600);
    }

    bcsGrant()->up();

    foreach ($keys as $key) {
        expect(Cache::has($key))->toBeFalse();
    }

    expect(Menu::permittedRoutePathsForRole($roleId))->toContain('/barcode/settings', '/barcode/settings/update', '/barcode/settings/update');
});

test('rolling back removes only those rows', function () {
    bcsSeedMenus();
    $roleId = bcsRole();
    $otherRole = bcsRole('accountant');
    $anchorId = (int) DB::table('menus')->where('route_path', '/business/settings')->value('id');
    $pageId = (int) DB::table('menus')->where('route_path', '/barcode/settings')->value('id');
    DB::table('permissions')->insert([
        ['role_id' => $roleId, 'menu_id' => $anchorId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['role_id' => $otherRole, 'menu_id' => $pageId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);

    bcsGrant()->up();
    bcsGrant()->down();

    expect(DB::table('permissions')->where('role_id', $roleId)->pluck('menu_id')->map(fn (mixed $id): int => (int) $id)->all())->toBe([$anchorId])
        ->and(DB::table('permissions')->where('role_id', $otherRole)->where('menu_id', $pageId)->where('status', 1)->exists())->toBeTrue();
});
