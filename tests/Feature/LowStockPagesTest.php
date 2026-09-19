<?php

use App\Models\Menu;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * `/product` (and its Products group) exist in live data but are not created by any migration, so a
 * fresh schema has no anchor. This recreates the live shape and returns the group id.
 */
function seedLowStockMenuAnchor(): int
{
    $groupId = DB::table('menus')->insertGetId([
        'parent_id' => null, 'name' => 'Products', 'icon' => 'bx bx-buildings', 'route_name' => '', 'route_path' => '',
        'menu_color' => '#6a0dad', 'sort_order' => 1, 'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0,
        'is_permission' => 0, 'type' => 2, 'created_at' => now(), 'updated_at' => now(),
    ]);

    DB::table('menus')->insert([
        'parent_id' => $groupId, 'name' => 'Product', 'icon' => 'bx bx-buildings', 'route_name' => 'product', 'route_path' => '/product',
        'menu_color' => '#6a0dad', 'sort_order' => 1, 'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0,
        'is_permission' => 0, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $groupId;
}

function lowStockMenuMigration(): object
{
    return require database_path('migrations/2026_09_19_064006_add_lowstock_menu.php');
}

test('guests cannot open the low stock page', function () {
    $this->get(route('lowstock'))->assertRedirect();
});

test('authenticated users can open the low stock page', function () {
    $this->actingAs(User::query()->findOrFail(1))
        ->get(route('lowstock'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('lowstock/index'));

    expect(route('lowstock', absolute: false))->toBe('/lowstock');
});

test('a fresh schema without the product anchor gets no orphan rows', function () {
    lowStockMenuMigration()->up();

    expect(DB::table('menus')->where('route_path', 'like', '/lowstock%')->exists())->toBeFalse();
});

test('the low stock row lands in the products group with a hidden export permission', function () {
    $groupId = seedLowStockMenuAnchor();
    lowStockMenuMigration()->up();

    $row = DB::table('menus')->where('route_path', '/lowstock')->first();
    $export = DB::table('menus')->where('route_path', '/lowstock/export')->first();

    expect((int) $row->parent_id)->toBe($groupId)
        ->and($row->route_name)->toBe('lowstock')
        ->and((int) $row->is_hidden)->toBe(0)
        ->and((int) $row->is_active)->toBe(1)
        ->and((int) $export->parent_id)->toBe((int) $row->id)
        ->and((int) $export->is_hidden)->toBe(1);
});

test('the superadmin sidebar lists low stock under products and hides the export permission', function () {
    seedLowStockMenuAnchor();
    lowStockMenuMigration()->up();

    $products = collect(Menu::sidebarMenusForRole(1))->firstWhere('name', 'Products');

    expect(collect($products['children'])->pluck('my_route')->all())->toBe(['/product', '/lowstock']);
});

test('the sidebar path resolves to the named web route', function () {
    $match = app('router')->getRoutes()->match(Request::create('/lowstock', 'GET'));

    expect($match->getName())->toBe('lowstock');
});

test('running the migration twice does not duplicate rows and rollback removes them', function () {
    seedLowStockMenuAnchor();
    lowStockMenuMigration()->up();
    $count = DB::table('menus')->count();

    lowStockMenuMigration()->up();
    expect(DB::table('menus')->count())->toBe($count);

    $menuId = DB::table('menus')->where('route_path', '/lowstock')->value('id');
    DB::table('permissions')->insert(['role_id' => 1, 'menu_id' => $menuId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

    lowStockMenuMigration()->down();

    expect(DB::table('menus')->where('route_path', 'like', '/lowstock%')->exists())->toBeFalse()
        ->and(DB::table('permissions')->where('menu_id', $menuId)->exists())->toBeFalse();
});
