<?php

use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Duplicate `permissions` rows (same role, menu and scope): the cleanup migration keeps the row that grants and
 * changes nobody's access, and Permission::CreatePermission no longer adds a second one.
 */
function dprMigration(): object
{
    return require database_path('migrations/2026_09_23_120000_remove_duplicate_permission_rows.php');
}

function dprMenu(string $path): int
{
    return grantMenuPermission(Role::query()->create(['name' => 'scratch'.uniqid(), 'is_active' => true])->id, $path, ltrim($path, '/').uniqid());
}

/**
 * @param  array<string, mixed>  $attributes
 */
function dprRow(int $roleId, int $menuId, int $status, array $attributes = []): int
{
    return DB::table('permissions')->insertGetId(array_merge([
        'role_id' => $roleId, 'menu_id' => $menuId, 'status' => $status, 'company_id' => null, 'branch_id' => null, 'department_id' => null,
        'created_at' => now(), 'updated_at' => now(),
    ], $attributes));
}

/**
 * The menu ids a role is granted: the way permittedMenusQuery reads them (any row with status 1).
 *
 * @return list<int>
 */
function dprGranted(int $roleId): array
{
    return Permission::query()->where('role_id', $roleId)->where('status', 1)->pluck('menu_id')->map(fn (mixed $id): int => (int) $id)->unique()->sort()->values()->all();
}

test('the on row is kept and the off twin removed, and access is unchanged', function () {
    $role = Role::query()->create(['name' => 'companyadmin', 'is_active' => true])->id;
    $menus = [dprMenu('/role'), dprMenu('/role/add'), dprMenu('/role/:id/edit')];
    $ids = [];

    foreach ($menus as $menuId) {
        $ids[$menuId] = dprRow($role, $menuId, 1);
    }

    foreach ($menus as $menuId) {
        dprRow($role, $menuId, 0);
    }

    $grantedBefore = dprGranted($role);
    expect(Permission::query()->where('role_id', $role)->count())->toBe(6);

    dprMigration()->up();

    expect(Permission::query()->where('role_id', $role)->count())->toBe(3)
        ->and(Permission::query()->where('role_id', $role)->pluck('id')->sort()->values()->all())->toBe(collect($ids)->sort()->values()->all())
        ->and(Permission::query()->where('role_id', $role)->where('status', 0)->count())->toBe(0)
        ->and(dprGranted($role))->toBe($grantedBefore);
});

test('when the off row comes first the on row is still the one kept', function () {
    $role = Role::query()->create(['name' => 'companyadmin', 'is_active' => true])->id;
    $menu = dprMenu('/role');
    dprRow($role, $menu, 0);
    $on = dprRow($role, $menu, 1);
    dprRow($role, $menu, 1);

    dprMigration()->up();

    expect(Permission::query()->where('role_id', $role)->pluck('id')->all())->toBe([$on])
        ->and(dprGranted($role))->toBe([$menu]);
});

test('duplicates that are all off leave the oldest one, and rows of other scopes, roles and menus are never merged', function () {
    $admin = Role::query()->create(['name' => 'companyadmin', 'is_active' => true])->id;
    $other = Role::query()->create(['name' => 'accountant', 'is_active' => true])->id;
    $menu = dprMenu('/role');
    $second = dprMenu('/role/add');
    $companyId = DB::table('companies')->insertGetId(['code' => 'DPR01', 'name' => 'Scope Co', 'address' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    $oldestOff = dprRow($admin, $menu, 0);
    dprRow($admin, $menu, 0);
    // scoped to a company: a different permission, not a duplicate of the unscoped one
    $scoped = dprRow($admin, $menu, 1, ['company_id' => $companyId]);
    $scopedTwin = dprRow($admin, $menu, 0, ['company_id' => $companyId]);
    // the same menu for another role and another menu for the same role
    $otherRole = dprRow($other, $menu, 1);
    $otherMenu = dprRow($admin, $second, 1);

    dprMigration()->up();

    $left = Permission::query()->pluck('id')->sort()->values()->all();

    expect($left)->toContain($oldestOff, $scoped, $otherRole, $otherMenu)
        ->and($left)->not->toContain($scopedTwin)
        ->and(Permission::query()->where('role_id', $admin)->where('menu_id', $menu)->whereNull('company_id')->count())->toBe(1)
        ->and(Permission::query()->where('role_id', $admin)->where('menu_id', $menu)->where('company_id', $companyId)->count())->toBe(1);
});

test('running it again, or with nothing duplicated, changes nothing, and the caches of the roles are cleared', function () {
    $role = Role::query()->create(['name' => 'companyadmin', 'is_active' => true])->id;
    $menu = dprMenu('/role');
    dprRow($role, $menu, 1);
    dprRow($role, $menu, 0);
    Cache::put("user_permission_paths:{$role}", ['stale'], 600);

    dprMigration()->up();
    $after = Permission::query()->orderBy('id')->get()->toArray();
    dprMigration()->up();

    expect(Permission::query()->orderBy('id')->get()->toArray())->toBe($after)
        ->and(Cache::has("user_permission_paths:{$role}"))->toBeFalse()
        ->and(fn () => dprMigration()->down())->not->toThrow(Throwable::class);
});

test('the effective menu of a user is the same before and after the cleanup', function () {
    $role = Role::query()->create(['name' => 'companyadmin', 'is_active' => true])->id;
    $menu = dprMenu('/role');
    $add = dprMenu('/role/add');
    dprRow($role, $menu, 1);
    dprRow($role, $menu, 0);
    dprRow($role, $add, 0);
    dprRow($role, $add, 0);

    $before = Menu::permittedRoutePathsForRole($role);
    dprMigration()->up();
    Cache::flush();

    expect(Menu::permittedRoutePathsForRole($role))->toBe($before)
        ->and($before)->toContain('/role')->not->toContain('/role/add');
});

// --- the guard -----------------------------------------------------------------------------------

test('recording a permission that already exists sets it instead of adding a second row', function () {
    $role = Role::query()->create(['name' => 'accountant', 'is_active' => true])->id;
    $menu = dprMenu('/role');
    $request = (object) ['role_id' => $role, 'company_id' => null, 'branch_id' => null, 'department_id' => null];

    $first = Permission::CreatePermission($request, $menu, 0);
    $again = Permission::CreatePermission($request, $menu, 1);
    $third = Permission::CreatePermission($request, $menu, 1);

    expect($again->id)->toBe($first->id)
        ->and($third->id)->toBe($first->id)
        ->and(Permission::query()->where('role_id', $role)->where('menu_id', $menu)->count())->toBe(1)
        ->and(Permission::query()->find($first->id)->status)->toBe(1);
});

test('the same permission in another scope is still its own row', function () {
    $role = Role::query()->create(['name' => 'accountant', 'is_active' => true])->id;
    $menu = dprMenu('/role');
    $companyId = DB::table('companies')->insertGetId(['code' => 'DPR02', 'name' => 'Scope Two', 'address' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    Permission::CreatePermission((object) ['role_id' => $role, 'company_id' => null, 'branch_id' => null, 'department_id' => null], $menu, 1);
    Permission::CreatePermission((object) ['role_id' => $role, 'company_id' => $companyId, 'branch_id' => null, 'department_id' => null], $menu, 1);
    Permission::CreatePermission((object) ['role_id' => $role, 'company_id' => $companyId, 'branch_id' => null, 'department_id' => null], $menu, 0);

    expect(Permission::query()->where('role_id', $role)->where('menu_id', $menu)->count())->toBe(2);
});

test('saving the same role permission twice through the screen never leaves a duplicate', function () {
    $role = Role::query()->create(['name' => 'accountant', 'is_active' => true]);
    $menu = dprMenu('/role');
    Sanctum::actingAs(User::query()->findOrFail(1));

    foreach ([1, 1, 0, 1] as $status) {
        $this->postJson('/api/permissions', ['role_id' => $role->id, 'menuid' => $menu, 'status' => $status])->assertSuccessful();
    }

    $duplicates = Permission::query()->where('role_id', $role->id)->select('menu_id', DB::raw('count(*) c'))->groupBy('menu_id')->having('c', '>', 1)->count();

    expect($duplicates)->toBe(0);
});
