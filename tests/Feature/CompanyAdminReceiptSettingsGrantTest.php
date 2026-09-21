<?php

use App\Models\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The migration that gives the global companyadmin role the Receipt Printer Settings page and its hidden rows. The menu rows
 * come from 2026_09_21_240500_add_receipt_settings_menu, which finds its group through a legacy page row that exists only in live data,
 * so the tests seed that anchor and run it.
 */
function rcsGrant(): object
{
    return require database_path('migrations/2026_09_21_240600_grant_companyadmin_receipt_settings_menu.php');
}

function rcsSeedMenus(): void
{
    DB::table('menus')->where('route_path', '/business/settings')->delete();

    $row = fn (array $attributes): array => $attributes + [
        'icon' => 'Grid', 'menu_color' => '#199683', 'sort_order' => 5, 'is_hidden' => 0, 'is_active' => 1,
        'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ];

    $groupId = DB::table('menus')->insertGetId($row([
        'parent_id' => null, 'name' => 'Company Settings', 'route_name' => 'rcs-grant-group', 'route_path' => '#rcs-grant-group',
    ]));
    DB::table('menus')->insert($row(['parent_id' => $groupId, 'name' => 'Legacy page', 'route_name' => 'legacy-rcs-anchor', 'route_path' => '/business/settings']));

    (require database_path('migrations/2026_09_21_240500_add_receipt_settings_menu.php'))->up();
}

function rcsRole(string $name = 'companyadmin', ?int $companyId = null): int
{
    return DB::table('roles')->insertGetId([
        'name' => $name, 'company_id' => $companyId, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * @return list<int>
 */
function rcsMenuIds(): array
{
    return DB::table('menus')->where('route_path', 'like', '/receipt/settings%')->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
}

test('the menu rows this grants exist: the page and 1 hidden rows', function () {
    rcsSeedMenus();

    expect(rcsMenuIds())->toHaveCount(2)
        ->and(DB::table('menus')->whereIn('id', rcsMenuIds())->where('is_hidden', 1)->count())->toBe(1);
});

test('the global companyadmin role gets exactly the 2 rows', function () {
    rcsSeedMenus();
    $roleId = rcsRole();

    rcsGrant()->up();

    expect(DB::table('permissions')->where('role_id', $roleId)->orderBy('menu_id')->pluck('menu_id')->map(fn (mixed $id): int => (int) $id)->all())->toBe(rcsMenuIds())
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('status', 1)->count())->toBe(2);

    $row = DB::table('permissions')->where('role_id', $roleId)->first();

    expect($row->company_id)->toBeNull()
        ->and($row->branch_id)->toBeNull()
        ->and($row->department_id)->toBeNull();
});

test('other roles and company specific admin roles are left alone', function () {
    rcsSeedMenus();
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'rcs001', 'name' => 'Grant Company', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    rcsRole('test role', $companyId);
    rcsRole('companyadmin', $companyId);
    rcsRole('superadmin');
    rcsRole('accountant');

    rcsGrant()->up();

    expect(DB::table('permissions')->count())->toBe(0);
});

test('running it twice keeps one enabled row per menu and re-enables a denied one', function () {
    rcsSeedMenus();
    $roleId = rcsRole();
    [$denied, $kept] = rcsMenuIds();

    DB::table('permissions')->insert([
        ['role_id' => $roleId, 'menu_id' => $denied, 'status' => 0, 'created_at' => now(), 'updated_at' => now()],
        ['role_id' => $roleId, 'menu_id' => $kept, 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);

    rcsGrant()->up();
    rcsGrant()->up();

    expect(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(2)
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('menu_id', $denied)->value('status'))->toBe(1)
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('status', 1)->count())->toBe(2);
});

test('an existing permission of the role on another menu is not touched', function () {
    rcsSeedMenus();
    $roleId = rcsRole();
    $anchorId = (int) DB::table('menus')->where('route_path', '/business/settings')->value('id');
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $anchorId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

    rcsGrant()->up();

    expect(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(3)
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('menu_id', $anchorId)->where('status', 1)->count())->toBe(1);
});

test('without the role or without the menu rows the migration does nothing, and a missing row is skipped', function () {
    rcsGrant()->up();
    expect(DB::table('permissions')->count())->toBe(0);

    rcsSeedMenus();
    rcsGrant()->up();
    expect(DB::table('permissions')->count())->toBe(0);

    $roleId = rcsRole();
    DB::table('menus')->where('route_path', '/receipt/settings/update')->delete();

    rcsGrant()->up();
    expect(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(1);

    DB::table('permissions')->delete();
    DB::table('menus')->whereIn('id', rcsMenuIds())->delete();
    rcsGrant()->up();

    expect(DB::table('permissions')->count())->toBe(0);
});

test('the granted paths show up for the role and the menu caches are flushed', function () {
    rcsSeedMenus();
    $roleId = rcsRole();
    $keys = ["user_permission_paths:{$roleId}", "user_menu_permissions_tree:{$roleId}", "user_menu_permissions:{$roleId}"];

    foreach ($keys as $key) {
        Cache::put($key, ['stale'], 600);
    }

    rcsGrant()->up();

    foreach ($keys as $key) {
        expect(Cache::has($key))->toBeFalse();
    }

    expect(Menu::permittedRoutePathsForRole($roleId))->toContain('/receipt/settings', '/receipt/settings/update', '/receipt/settings/update');
});

test('rolling back removes only those rows', function () {
    rcsSeedMenus();
    $roleId = rcsRole();
    $otherRole = rcsRole('accountant');
    $anchorId = (int) DB::table('menus')->where('route_path', '/business/settings')->value('id');
    $pageId = (int) DB::table('menus')->where('route_path', '/receipt/settings')->value('id');
    DB::table('permissions')->insert([
        ['role_id' => $roleId, 'menu_id' => $anchorId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['role_id' => $otherRole, 'menu_id' => $pageId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);

    rcsGrant()->up();
    rcsGrant()->down();

    expect(DB::table('permissions')->where('role_id', $roleId)->pluck('menu_id')->map(fn (mixed $id): int => (int) $id)->all())->toBe([$anchorId])
        ->and(DB::table('permissions')->where('role_id', $otherRole)->where('menu_id', $pageId)->where('status', 1)->exists())->toBeTrue();
});
