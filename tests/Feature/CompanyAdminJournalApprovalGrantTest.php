<?php

use App\Models\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function jagMigration(): object
{
    return require database_path('migrations/2026_09_20_090000_grant_companyadmin_journal_entry_approval_menu.php');
}

function jagMenu(string $name = 'Journal Entry Approval', string $path = '/journalentry/approval'): int
{
    return DB::table('menus')->insertGetId([
        'parent_id' => null, 'name' => $name, 'icon' => '', 'route_name' => ltrim($path, '/'), 'route_path' => $path,
        'menu_color' => '#000000', 'sort_order' => 1, 'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0,
        'is_permission' => 0, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function jagRole(string $name = 'companyadmin', ?int $companyId = null): int
{
    return DB::table('roles')->insertGetId([
        'name' => $name, 'company_id' => $companyId, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('the global companyadmin role gets exactly the journal entry approval row', function () {
    $menuId = jagMenu();
    $otherMenuId = jagMenu('Something else', '/else');
    $roleId = jagRole();

    jagMigration()->up();

    $rows = DB::table('permissions')->where('role_id', $roleId)->get();

    expect($rows)->toHaveCount(1)
        ->and((int) $rows->first()->menu_id)->toBe($menuId)
        ->and((int) $rows->first()->status)->toBe(1)
        ->and(DB::table('permissions')->where('menu_id', $otherMenuId)->exists())->toBeFalse();
});

test('other roles and company specific admin roles are left alone', function () {
    jagMenu();
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'JAG001', 'name' => 'Grant Company', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    jagRole('test role', $companyId);
    jagRole('companyadmin', $companyId);
    jagRole('superadmin');

    jagMigration()->up();

    expect(DB::table('permissions')->count())->toBe(0);
});

test('running it twice keeps a single enabled row and re-enables a denied one', function () {
    $menuId = jagMenu();
    $roleId = jagRole();
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $menuId, 'status' => 0, 'created_at' => now(), 'updated_at' => now()]);

    jagMigration()->up();
    jagMigration()->up();

    $rows = DB::table('permissions')->where('role_id', $roleId)->where('menu_id', $menuId)->get();

    expect($rows)->toHaveCount(1)
        ->and((int) $rows->first()->status)->toBe(1);
});

test('without the menu row or the role the migration does nothing', function () {
    jagRole();
    jagMigration()->up();
    expect(DB::table('permissions')->count())->toBe(0);

    DB::table('roles')->delete();
    jagMenu();
    jagMigration()->up();
    expect(DB::table('permissions')->count())->toBe(0);
});

test('the granted path shows up for the role and the menu cache is flushed', function () {
    jagMenu();
    $roleId = jagRole();
    Cache::put("user_permission_paths:{$roleId}", ['stale'], 600);

    jagMigration()->up();

    expect(Cache::has("user_permission_paths:{$roleId}"))->toBeFalse()
        ->and(Menu::permittedRoutePathsForRole($roleId))->toContain('/journalentry/approval');
});

test('rolling back removes only that row', function () {
    $menuId = jagMenu();
    $otherMenuId = jagMenu('Something else', '/else');
    $roleId = jagRole();
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $otherMenuId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

    jagMigration()->up();
    jagMigration()->down();

    expect(DB::table('permissions')->where('menu_id', $menuId)->exists())->toBeFalse()
        ->and(DB::table('permissions')->where('menu_id', $otherMenuId)->where('status', 1)->exists())->toBeTrue();
});
