<?php

use App\Models\Menu;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** The 21 leaf and hidden permission paths the migration grants (the Reports group makes 22). */
const GRANTED_MENU_PATHS = [
    '/sell/return', '/sell/return/add', '/sell/return/:id/edit', '/sell/return/delete', '/sell/return/restore', '/sell/return/:id/view',
    '/sell/payment', '/sell/payment/add', '/sell/payment/:id/edit', '/sell/payment/delete',
    '/purchase/payment', '/purchase/payment/add', '/purchase/payment/:id/edit', '/purchase/payment/delete',
    '/product/export', '/product/import', '/product/trash', '/product/restore',
    '/lowstock', '/lowstock/export',
    '/report/ledger',
];

function grantMigration(): object
{
    return require database_path('migrations/2026_09_19_072122_grant_companyadmin_new_menu_permissions.php');
}

function insertPlainMenu(string $name, string $path, ?int $parentId = null, int $type = 1, int $hidden = 0): int
{
    return DB::table('menus')->insertGetId([
        'parent_id' => $parentId, 'name' => $name, 'icon' => '', 'route_name' => ltrim($path, '/'), 'route_path' => $path,
        'menu_color' => '#000000', 'sort_order' => 1, 'is_hidden' => $hidden, 'is_active' => 1, 'is_admin' => 0,
        'is_permission' => 0, 'type' => $type, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * Recreates the menu rows the earlier migrations add in live data (Sell/Purchase/Product anchors do
 * not exist in a fresh schema), then returns the Reports group id.
 */
function seedGrantableMenus(): int
{
    $groupId = DB::table('menus')->whereNull('parent_id')->where('name', 'Reports')->where('type', 2)->value('id');

    foreach (GRANTED_MENU_PATHS as $path) {
        if ($path !== '/report/ledger') {
            insertPlainMenu($path, $path, null, 1, 1);
        }
    }

    return (int) $groupId;
}

function makeCompanyAdminRole(?int $companyId = null): int
{
    return DB::table('roles')->insertGetId([
        'name' => 'companyadmin', 'company_id' => $companyId, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * @return list<int>
 */
function grantedMenuIdsFor(int $roleId): array
{
    return DB::table('permissions')->where('role_id', $roleId)->where('status', 1)->pluck('menu_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
}

test('without a global companyadmin role the migration does nothing', function () {
    seedGrantableMenus();
    $before = DB::table('permissions')->count();

    $companyId = DB::table('companies')->insertGetId([
        'code' => 'GRT001', 'name' => 'Grant Test Company', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    makeCompanyAdminRole(companyId: $companyId);
    grantMigration()->up();

    expect(DB::table('permissions')->count())->toBe($before);
});

test('the company admin role gets exactly the 22 new rows, active, with no scope columns', function () {
    $groupId = seedGrantableMenus();
    $roleId = makeCompanyAdminRole();

    grantMigration()->up();

    $expected = DB::table('menus')->whereIn('route_path', GRANTED_MENU_PATHS)->pluck('id')->map(fn ($id): int => (int) $id)->push($groupId)->sort()->values()->all();
    $rows = DB::table('permissions')->where('role_id', $roleId)->get();

    expect($expected)->toHaveCount(22)
        ->and(grantedMenuIdsFor($roleId))->toBe($expected)
        ->and($rows->every(fn ($row) => $row->company_id === null && $row->branch_id === null && $row->department_id === null))->toBeTrue();
});

test('rows that already exist are switched on and reruns do not duplicate anything', function () {
    seedGrantableMenus();
    $roleId = makeCompanyAdminRole();
    $importId = DB::table('menus')->where('route_path', '/product/import')->value('id');

    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $importId, 'status' => 0, 'created_at' => now(), 'updated_at' => now()]);

    grantMigration()->up();
    $count = DB::table('permissions')->where('role_id', $roleId)->count();
    grantMigration()->up();

    expect(DB::table('permissions')->where('role_id', $roleId)->count())->toBe($count)
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('menu_id', $importId)->value('status'))->toBe(1)
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('menu_id', $importId)->count())->toBe(1);
});

test('other roles and unrelated menu rows are left alone', function () {
    seedGrantableMenus();
    $roleId = makeCompanyAdminRole();
    $otherRoleId = DB::table('roles')->insertGetId(['name' => 'storekeeper', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $deniedMenuId = insertPlainMenu('Menus', '/menu');
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $deniedMenuId, 'status' => 0, 'created_at' => now(), 'updated_at' => now()]);

    grantMigration()->up();

    expect(DB::table('permissions')->where('role_id', $otherRoleId)->exists())->toBeFalse()
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('menu_id', $deniedMenuId)->value('status'))->toBe(0);
});

test('deleted menu rows are never granted', function () {
    seedGrantableMenus();
    $roleId = makeCompanyAdminRole();
    $lowStockId = DB::table('menus')->where('route_path', '/lowstock')->value('id');
    DB::table('menus')->where('id', $lowStockId)->update(['deleted_at' => now()]);

    grantMigration()->up();

    expect(grantedMenuIdsFor($roleId))->not->toContain((int) $lowStockId);
});

test('a company admin user can now open the ledger report and import products, but only after the grant', function () {
    seedGrantableMenus();
    $roleId = makeCompanyAdminRole();
    $admin = createStaffUserForRole(Role::query()->findOrFail($roleId), ['company_id' => null]);

    $this->actingAs($admin)->get(route('report.ledger'))->assertForbidden();

    grantMigration()->up();
    Cache::forget("user_permission_paths:{$roleId}");

    $this->actingAs($admin)
        ->get(route('report.ledger'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('report/ledger'));

    Sanctum::actingAs($admin);
    $this->postJson('/api/products/import', ['rows' => []])->assertUnprocessable();
});

test('the granted role sees reports and the new pages in its sidebar', function () {
    seedGrantableMenus();
    $roleId = makeCompanyAdminRole();

    grantMigration()->up();

    $reports = collect(Menu::sidebarMenusForRole($roleId))->firstWhere('name', 'Reports');

    expect($reports)->not->toBeNull()
        ->and(collect($reports['children'])->pluck('my_route')->all())->toBe(['/report/ledger'])
        ->and(Menu::permittedRoutePathsForRole($roleId))->toContain('/lowstock', '/sell/return', '/sell/payment', '/purchase/payment', '/product/export');
});

test('rolling back removes only those grants', function () {
    seedGrantableMenus();
    $roleId = makeCompanyAdminRole();
    $keptId = insertPlainMenu('Kept', '/kept');
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $keptId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

    grantMigration()->up();
    expect(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(23);

    grantMigration()->down();

    expect(grantedMenuIdsFor($roleId))->toBe([$keptId]);
});
