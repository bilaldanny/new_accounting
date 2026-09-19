<?php

use App\Models\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

/**
 * The dead Opening Stock rows exist in live data but are not created by any migration, so a fresh
 * schema has none. This recreates the live shape (hidden row plus its add/edit child).
 *
 * @return array{parent: int, child: int, group: int}
 */
function seedDeadOpeningStockMenus(): array
{
    $now = now();

    $groupId = DB::table('menus')->insertGetId([
        'parent_id' => null, 'name' => 'Products', 'icon' => 'bx bx-buildings', 'route_name' => '', 'route_path' => '',
        'menu_color' => '#6a0dad', 'sort_order' => 1, 'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0,
        'is_permission' => 0, 'type' => 2, 'created_at' => $now, 'updated_at' => $now,
    ]);

    $parentId = DB::table('menus')->insertGetId([
        'parent_id' => $groupId, 'name' => 'Opening Stock', 'icon' => 'bx bx-buildings', 'route_name' => 'openingstock',
        'route_path' => '/openingstock', 'menu_color' => '#6a0dad', 'sort_order' => 1, 'is_hidden' => 1, 'is_active' => 1,
        'is_admin' => 0, 'is_permission' => 0, 'type' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);

    $childId = DB::table('menus')->insertGetId([
        'parent_id' => $parentId, 'name' => 'Add / Edit Opening Stock', 'icon' => '', 'route_name' => 'addopeningstock',
        'route_path' => '/openingstock/add', 'menu_color' => '#6a0dad', 'sort_order' => 1, 'is_hidden' => 1, 'is_active' => 1,
        'is_admin' => 0, 'is_permission' => 0, 'type' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);

    return ['parent' => $parentId, 'child' => $childId, 'group' => $groupId];
}

function openingStockCleanupMigration(): object
{
    return require database_path('migrations/2026_09_19_060918_drop_dead_opening_stock_menus.php');
}

test('the opening stock path has no web route, which is why the rows are dead', function () {
    expect(fn () => app('router')->getRoutes()->match(Request::create('/openingstock', 'GET')))
        ->toThrow(NotFoundHttpException::class);
});

test('a fresh schema without the legacy rows is left untouched', function () {
    $before = DB::table('menus')->count();

    openingStockCleanupMigration()->up();

    expect(DB::table('menus')->count())->toBe($before);
});

test('the dead rows are soft deleted and disappear from every menu query', function () {
    $ids = seedDeadOpeningStockMenus();

    openingStockCleanupMigration()->up();

    expect(Menu::query()->whereIn('id', [$ids['parent'], $ids['child']])->exists())->toBeFalse()
        ->and(Menu::onlyTrashed()->whereIn('id', [$ids['parent'], $ids['child']])->count())->toBe(2)
        ->and(DB::table('menus')->where('id', $ids['group'])->whereNull('deleted_at')->exists())->toBeTrue();
});

test('running the migration twice keeps the original deletion time', function () {
    $ids = seedDeadOpeningStockMenus();

    openingStockCleanupMigration()->up();
    $deletedAt = DB::table('menus')->where('id', $ids['parent'])->value('deleted_at');

    $this->travel(5)->minutes();
    openingStockCleanupMigration()->up();

    expect(DB::table('menus')->where('id', $ids['parent'])->value('deleted_at'))->toBe($deletedAt);
});

test('a role that had the opening stock permission no longer gets the dead path', function () {
    $ids = seedDeadOpeningStockMenus();

    $roleId = DB::table('roles')->insertGetId([
        'name' => 'stockclerk', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('permissions')->insert([
        'role_id' => $roleId, 'menu_id' => $ids['parent'], 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(Menu::permittedRoutePathsForRole($roleId))->toContain('/openingstock');

    openingStockCleanupMigration()->up();

    expect(Menu::permittedRoutePathsForRole($roleId))->not->toContain('/openingstock')
        ->and(DB::table('permissions')->where('menu_id', $ids['parent'])->exists())->toBeTrue();
});

test('rolling back restores the rows', function () {
    $ids = seedDeadOpeningStockMenus();

    openingStockCleanupMigration()->up();
    openingStockCleanupMigration()->down();

    expect(Menu::query()->whereIn('id', [$ids['parent'], $ids['child']])->count())->toBe(2);
});

test('stock adjustment still offers the opening type that replaces the dead menu', function () {
    $source = file_get_contents(resource_path('js/pages/stockadjustment/Fields.vue'));
    $rules = file_get_contents(app_path('Http/Controllers/StockAdjustmentController.php'));

    expect($source)->toContain("value: 'opening'")
        ->and($rules)->toContain('normal,abnormal,unboxing,opening');
});
