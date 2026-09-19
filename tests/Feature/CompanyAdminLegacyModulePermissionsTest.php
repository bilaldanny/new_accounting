<?php

use App\Models\Menu;
use App\Models\Role;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/** The 34 paths the migration grants, grouped by module. */
function legacyGrantPaths(): array
{
    return [
        'stocktransfer' => ['/stocktransfer'],
        'stockadjustment' => ['/stockadjustment'],
        'expense' => ['/expense', '/expense/add', '/expense/:id/edit', '/expense/:id/view', '/expense/delete'],
        'deposit' => ['/deposit', '/deposit/add', '/deposit/:id/edit', '/deposit/:id/view', '/deposit/delete'],
        'fundtransfer' => ['/fundtransfer', '/fundtransfer/add', '/fundtransfer/:id/edit', '/fundtransfer/:id/view', '/fundtransfer/delete'],
        'warehouse' => ['/warehouse', '/warehouse/add', '/warehouse/:id/edit', '/warehouse/delete'],
        'consumer' => ['/consumer', '/consumer/add', '/consumer/:id/edit', '/consumer/delete'],
        'bankissuer' => ['/bankissuer', '/bankissuer/add', '/bankissuer/:id/edit', '/bankissuer/delete'],
        'pricelist' => ['/pricelist', '/pricelist/add', '/pricelist/:id/edit', '/pricelist/delete'],
        'printlabel' => ['/printlabel'],
    ];
}

/** @return list<string> */
function legacyGrantFlatPaths(): array
{
    return array_merge(...array_values(legacyGrantPaths()));
}

function legacyGrantMigration(): object
{
    return require database_path('migrations/2026_09_19_120700_grant_companyadmin_legacy_module_permissions.php');
}

/** Some of these rows already come from earlier menu migrations; create only the missing ones. */
function ensureLegacyMenu(string $path, ?int $parentId = null, int $hidden = 0): int
{
    $existing = DB::table('menus')->where('route_path', $path)->whereNull('deleted_at')->value('id');

    if ($existing !== null) {
        return (int) $existing;
    }

    return DB::table('menus')->insertGetId([
        'parent_id' => $parentId, 'name' => $path, 'icon' => '', 'route_name' => ltrim($path, '/'), 'route_path' => $path,
        'menu_color' => '#000000', 'sort_order' => 1, 'is_hidden' => $hidden, 'is_active' => 1, 'is_admin' => 0,
        'is_permission' => 0, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @return list<int> ids of all 34 grantable rows, creating any that a fresh schema lacks */
function seedLegacyGrantMenus(): array
{
    $ids = [];

    foreach (legacyGrantPaths() as $paths) {
        $rootId = ensureLegacyMenu($paths[0]);
        $ids[] = $rootId;

        foreach (array_slice($paths, 1) as $child) {
            $ids[] = ensureLegacyMenu($child, $rootId, 1);
        }
    }

    return $ids;
}

function legacyCompanyAdminRole(?int $companyId = null): int
{
    return DB::table('roles')->insertGetId([
        'name' => 'companyadmin', 'company_id' => $companyId, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** @return list<int> */
function legacyActiveGrants(int $roleId): array
{
    return DB::table('permissions')->where('role_id', $roleId)->where('status', 1)->pluck('menu_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
}

test('the list really is the 34 rows that were confirmed', function () {
    expect(legacyGrantFlatPaths())->toHaveCount(34)
        ->and(array_unique(legacyGrantFlatPaths()))->toHaveCount(34);
});

test('without a global companyadmin role the migration does nothing', function () {
    seedLegacyGrantMenus();
    $before = DB::table('permissions')->count();

    $companyId = DB::table('companies')->insertGetId(['code' => 'LGP001', 'name' => 'Legacy Grant Co', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    legacyCompanyAdminRole($companyId);
    legacyGrantMigration()->up();

    expect(DB::table('permissions')->count())->toBe($before);
});

test('the company admin gets exactly the 34 rows, active, with no scope columns', function () {
    $ids = seedLegacyGrantMenus();
    $roleId = legacyCompanyAdminRole();

    legacyGrantMigration()->up();

    sort($ids);
    $rows = DB::table('permissions')->where('role_id', $roleId)->get();

    expect($ids)->toHaveCount(34)
        ->and(legacyActiveGrants($roleId))->toBe($ids)
        ->and($rows->every(fn ($row) => $row->company_id === null && $row->branch_id === null && $row->department_id === null))->toBeTrue();
});

test('system settings and explicitly denied rows are never granted', function () {
    seedLegacyGrantMenus();
    $roleId = legacyCompanyAdminRole();

    $untouched = collect(['/currency', '/timezone', '/country', '/state', '/city', '/software/setting', '/menu', '/role', '/user'])
        ->mapWithKeys(fn (string $path) => [$path => ensureLegacyMenu($path)]);
    $deniedCompanyId = ensureLegacyMenu('/company');
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $deniedCompanyId, 'status' => 0, 'created_at' => now(), 'updated_at' => now()]);

    legacyGrantMigration()->up();

    expect(DB::table('permissions')->where('role_id', $roleId)->whereIn('menu_id', $untouched->values())->exists())->toBeFalse()
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('menu_id', $deniedCompanyId)->value('status'))->toBe(0);
});

test('rows that already exist are switched on and reruns do not duplicate anything', function () {
    seedLegacyGrantMenus();
    $roleId = legacyCompanyAdminRole();
    $expenseId = DB::table('menus')->where('route_path', '/expense')->value('id');

    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $expenseId, 'status' => 0, 'created_at' => now(), 'updated_at' => now()]);

    legacyGrantMigration()->up();
    $count = DB::table('permissions')->where('role_id', $roleId)->count();
    legacyGrantMigration()->up();

    expect(DB::table('permissions')->where('role_id', $roleId)->count())->toBe($count)
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('menu_id', $expenseId)->count())->toBe(1)
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('menu_id', $expenseId)->value('status'))->toBe(1);
});

test('other roles and deleted menu rows are left alone', function () {
    seedLegacyGrantMenus();
    $roleId = legacyCompanyAdminRole();
    $otherRoleId = DB::table('roles')->insertGetId(['name' => 'storekeeper', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $warehouseId = DB::table('menus')->where('route_path', '/warehouse')->value('id');
    DB::table('menus')->where('id', $warehouseId)->update(['deleted_at' => now()]);

    legacyGrantMigration()->up();

    expect(DB::table('permissions')->where('role_id', $otherRoleId)->exists())->toBeFalse()
        ->and(legacyActiveGrants($roleId))->not->toContain((int) $warehouseId)
        ->and(legacyActiveGrants($roleId))->toHaveCount(33);
});

test('the company admin can use the granted modules only after the grant', function () {
    seedLegacyGrantMenus();
    $roleId = legacyCompanyAdminRole();
    $admin = createStaffUserForRole(Role::query()->findOrFail($roleId), ['company_id' => null]);

    Sanctum::actingAs($admin);

    // Each store() authorises its `/module/add` key before validating, so 403 vs 422 shows the permission.
    $this->postJson('/api/warehouses', [])->assertForbidden();
    $this->postJson('/api/expenses', [])->assertForbidden();

    legacyGrantMigration()->up();
    Cache::forget("user_permission_paths:{$roleId}");

    $this->postJson('/api/warehouses', [])->assertUnprocessable();
    $this->postJson('/api/expenses', [])->assertUnprocessable();
});

test('the granted role sees the modules in its sidebar', function () {
    seedLegacyGrantMenus();
    $roleId = legacyCompanyAdminRole();

    legacyGrantMigration()->up();

    $visible = collect(Menu::sidebarMenusForRole($roleId))->pluck('my_route')->filter()->all();
    $paths = Menu::permittedRoutePathsForRole($roleId);

    expect($visible)->toContain('/stocktransfer', '/stockadjustment', '/warehouse', '/consumer', '/printlabel')
        ->and($paths)->toContain('/expense', '/deposit', '/fundtransfer', '/bankissuer', '/pricelist');
});

test('rolling back removes only those grants', function () {
    seedLegacyGrantMenus();
    $roleId = legacyCompanyAdminRole();
    $keptId = ensureLegacyMenu('/kept-elsewhere');
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $keptId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

    legacyGrantMigration()->up();
    expect(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(35);

    legacyGrantMigration()->down();

    expect(legacyActiveGrants($roleId))->toBe([$keptId]);
});
