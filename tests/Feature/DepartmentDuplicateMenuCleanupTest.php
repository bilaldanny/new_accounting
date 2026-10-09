<?php

use App\Models\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Live data has two sets of the same three "Department" action rows (Export/Import/Trash): one wrongly
 * parented under the "Menus" group (dead, unreferenced), one correctly parented under "Department" (live,
 * granted to companyadmin). A fresh schema has neither, so this recreates the live shape.
 *
 * @return array{dead_group: int, dead: array<string, int>, live_group: int, live: array<string, int>}
 */
function seedDuplicateDepartmentMenus(): array
{
    $now = now();
    $insert = fn (array $overrides) => DB::table('menus')->insertGetId(array_merge([
        'icon' => '', 'route_name' => '', 'route_path' => '', 'menu_color' => '#6a0dad', 'sort_order' => 1, 'is_hidden' => 1,
        'is_active' => 1, 'is_admin' => 0, 'is_permission' => 1, 'type' => 1, 'created_at' => $now, 'updated_at' => $now,
    ], $overrides));

    $deadGroupId = $insert(['parent_id' => null, 'name' => 'Menus', 'route_path' => '/menu', 'is_hidden' => 0]);
    $liveGroupId = $insert(['parent_id' => null, 'name' => 'Products', 'is_hidden' => 0]);
    $liveParentId = $insert(['parent_id' => $liveGroupId, 'name' => 'Department', 'route_path' => '/department', 'is_hidden' => 0]);

    $paths = ['export' => '/department/export', 'import' => '/department/import', 'trash' => '/department/trash'];

    $dead = collect($paths)->mapWithKeys(fn ($path, $key) => [$key => $insert(['parent_id' => $deadGroupId, 'name' => "Department {$key}", 'route_path' => $path])])->all();
    $live = collect($paths)->mapWithKeys(fn ($path, $key) => [$key => $insert(['parent_id' => $liveParentId, 'name' => "Department {$key}", 'route_path' => $path])])->all();

    return ['dead_group' => $deadGroupId, 'dead' => $dead, 'live_group' => $liveGroupId, 'live' => $live];
}

function departmentDuplicateCleanupMigration(): object
{
    return require database_path('migrations/2026_09_26_160000_remove_duplicate_department_menu_rows.php');
}

test('a fresh schema without the duplicate rows is left untouched', function () {
    $before = DB::table('menus')->count();

    departmentDuplicateCleanupMigration()->up();

    expect(DB::table('menus')->count())->toBe($before);
});

test('only the dead, wrongly-parented rows are soft deleted; the live set is untouched', function () {
    $ids = seedDuplicateDepartmentMenus();

    departmentDuplicateCleanupMigration()->up();

    expect(Menu::query()->whereIn('id', $ids['dead'])->exists())->toBeFalse()
        ->and(Menu::onlyTrashed()->whereIn('id', $ids['dead'])->count())->toBe(3)
        ->and(Menu::query()->whereIn('id', $ids['live'])->count())->toBe(3)
        ->and(DB::table('menus')->where('id', $ids['dead_group'])->whereNull('deleted_at')->exists())->toBeTrue();
});

test('a permission granted through the live rows survives, and the dead rows never had one to begin with', function () {
    $ids = seedDuplicateDepartmentMenus();
    $roleId = DB::table('roles')->insertGetId(['name' => 'deptclerk', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $ids['live']['export'], 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

    expect(Menu::permittedRoutePathsForRole($roleId))->toContain('/department/export');

    departmentDuplicateCleanupMigration()->up();

    expect(Menu::permittedRoutePathsForRole($roleId))->toContain('/department/export')
        ->and(DB::table('permissions')->where('menu_id', $ids['live']['export'])->exists())->toBeTrue();
});

test('running the migration twice keeps the original deletion time, and rolling back restores the rows', function () {
    $ids = seedDuplicateDepartmentMenus();

    departmentDuplicateCleanupMigration()->up();
    $deletedAt = DB::table('menus')->where('id', $ids['dead']['export'])->value('deleted_at');

    test()->travel(5)->minutes();
    departmentDuplicateCleanupMigration()->up();
    expect(DB::table('menus')->where('id', $ids['dead']['export'])->value('deleted_at'))->toBe($deletedAt);

    departmentDuplicateCleanupMigration()->down();
    expect(Menu::query()->whereIn('id', $ids['dead'])->count())->toBe(3);
});
