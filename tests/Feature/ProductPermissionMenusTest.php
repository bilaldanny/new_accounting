<?php

use App\Models\Menu;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * `/product` (and its Products group) exist in live data but are not created by any migration, so a
 * fresh schema has no anchor. This recreates the live shape.
 */
function seedProductMenuAnchor(): int
{
    $groupId = DB::table('menus')->insertGetId([
        'parent_id' => null,
        'name' => 'Products',
        'icon' => 'bx bx-buildings',
        'route_name' => '',
        'route_path' => '',
        'menu_color' => '#6a0dad',
        'sort_order' => 1,
        'is_hidden' => 0,
        'is_active' => 1,
        'is_admin' => 0,
        'is_permission' => 0,
        'type' => 2,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return DB::table('menus')->insertGetId([
        'parent_id' => $groupId,
        'name' => 'Product',
        'icon' => 'bx bx-buildings',
        'route_name' => 'product',
        'route_path' => '/product',
        'menu_color' => '#6a0dad',
        'sort_order' => 1,
        'is_hidden' => 0,
        'is_active' => 1,
        'is_admin' => 0,
        'is_permission' => 0,
        'type' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function productPermissionMenusMigration(): object
{
    return require database_path('migrations/2026_09_19_060612_add_product_import_export_trash_menus.php');
}

function grantRoleProductPermission(int $roleId, string $routePath): void
{
    DB::table('permissions')->insert([
        'role_id' => $roleId,
        'menu_id' => DB::table('menus')->where('route_path', $routePath)->value('id'),
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Cache::forget("user_permission_paths:{$roleId}");
    Cache::forget("user_menu_permissions:{$roleId}");
    Cache::forget("user_menu_permissions_tree:{$roleId}");
}

test('a fresh schema without the product anchor gets no orphan rows', function () {
    productPermissionMenusMigration()->up();

    expect(DB::table('menus')->where('route_path', 'like', '/product/%')->exists())->toBeFalse();
});

test('export, import, trash and restore become hidden permission rows under product', function () {
    $productId = seedProductMenuAnchor();
    productPermissionMenusMigration()->up();

    $rows = DB::table('menus')->where('parent_id', $productId)->orderBy('id')->get();

    expect($rows->pluck('route_path')->all())->toBe(['/product/export', '/product/import', '/product/trash', '/product/restore'])
        ->and($rows->pluck('route_name')->all())->toBe(['product.export', 'product.import', 'product.trash', 'product.restore'])
        ->and($rows->every(fn ($row) => (int) $row->is_hidden === 1 && (int) $row->is_active === 1 && (int) $row->is_permission === 1))->toBeTrue();
});

test('every permission key the product controller checks now has a row', function () {
    $productId = seedProductMenuAnchor();
    productPermissionMenusMigration()->up();

    $source = file_get_contents(app_path('Http/Controllers/ProductController.php'));
    preg_match_all("#(?:authorizeMenuPermission|deletepermission|guardedBulkAction)\('(/[^']+)'#", $source, $matches);
    $required = array_values(array_unique($matches[1]));

    // add / edit / delete already exist in live data; import and restore were the missing ones.
    $alreadyInLiveData = ['/product/add', '/product/:id/edit', '/product/delete'];
    $childPaths = DB::table('menus')->where('parent_id', $productId)->pluck('route_path')->all();

    expect($required)->toContain('/product/import', '/product/restore')
        ->and(array_diff($required, $alreadyInLiveData, $childPaths))->toBe([]);
});

test('running the migration twice does not duplicate rows', function () {
    seedProductMenuAnchor();
    productPermissionMenusMigration()->up();
    $count = DB::table('menus')->count();

    productPermissionMenusMigration()->up();

    expect(DB::table('menus')->count())->toBe($count);
});

test('the permission rows never show up as sidebar links', function () {
    seedProductMenuAnchor();
    productPermissionMenusMigration()->up();

    $products = collect(Menu::sidebarMenusForRole(1))->firstWhere('name', 'Products');

    expect(collect($products['children'])->pluck('my_route')->all())->toBe(['/product']);
});

test('a staff role gets a 403 on product import until the import permission is granted', function () {
    seedProductMenuAnchor();
    productPermissionMenusMigration()->up();

    $roleId = DB::table('roles')->insertGetId([
        'name' => 'stockstaff',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $staff = createStaffUserForRole(Role::query()->findOrFail($roleId));

    Sanctum::actingAs($staff);

    $this->postJson('/api/products/import', ['rows' => []])->assertForbidden();

    grantRoleProductPermission($roleId, '/product/import');

    $this->postJson('/api/products/import', ['rows' => []])->assertUnprocessable();
});

test('granted export, trash and restore rows reach the frontend permission paths', function () {
    seedProductMenuAnchor();
    productPermissionMenusMigration()->up();

    $roleId = DB::table('roles')->insertGetId([
        'name' => 'stockviewer',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $staff = createStaffUserForRole(Role::query()->findOrFail($roleId));

    $this->actingAs($staff);

    expect($staff->getPermissionPaths())->not->toContain('/product/export', '/product/trash', '/product/restore');

    foreach (['/product/export', '/product/trash', '/product/restore'] as $path) {
        grantRoleProductPermission($roleId, $path);
    }

    expect($staff->getPermissionPaths())->toContain('/product/export', '/product/trash', '/product/restore');
});

test('rolling the migration back removes the rows and their permissions', function () {
    $productId = seedProductMenuAnchor();
    productPermissionMenusMigration()->up();

    $importId = DB::table('menus')->where('route_path', '/product/import')->value('id');
    DB::table('permissions')->insert([
        'role_id' => 1,
        'menu_id' => $importId,
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    productPermissionMenusMigration()->down();

    expect(DB::table('menus')->where('parent_id', $productId)->exists())->toBeFalse()
        ->and(DB::table('permissions')->where('menu_id', $importId)->exists())->toBeFalse()
        ->and(DB::table('menus')->where('route_path', '/product')->exists())->toBeTrue();
});
