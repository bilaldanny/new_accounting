<?php

use App\Http\Controllers\Reports\FinancialReportController;
use App\Http\Controllers\Reports\LedgerReportController;
use App\Http\Controllers\Reports\PartyReportController;
use App\Http\Controllers\Reports\ProductReportController;
use App\Http\Controllers\Reports\StockReportController;
use App\Http\Controllers\Reports\TransactionReportController;
use App\Models\Menu;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The migration that gives the global companyadmin role the report pages and their hidden export rows.
 * The report menu rows themselves come from the report menu migrations, which the test database has
 * already run.
 */
function crgMigration(): object
{
    return require database_path('migrations/2026_09_21_170000_grant_companyadmin_report_menu_permissions.php');
}

function crgRole(string $name = 'companyadmin', ?int $companyId = null): int
{
    return DB::table('roles')->insertGetId([
        'name' => $name, 'company_id' => $companyId, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * The 30 report page paths (every report but the customer / supplier ledger) and their export paths.
 *
 * @return list<string>
 */
function crgPaths(): array
{
    $pages = array_values([
        ...TransactionReportController::PERMISSIONS,
        ...PartyReportController::PERMISSIONS,
        ...ProductReportController::PERMISSIONS,
        ...StockReportController::PERMISSIONS,
        ...LedgerReportController::PERMISSIONS,
        ...FinancialReportController::PERMISSIONS,
    ]);

    return [...$pages, ...array_map(fn (string $page): string => $page.'/export', $pages)];
}

/**
 * @return list<int>
 */
function crgMenuIds(): array
{
    return DB::table('menus')->whereIn('route_path', crgPaths())->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
}

test('the report menu rows this grants exist: 30 pages and 30 export rows', function () {
    expect(crgPaths())->toHaveCount(60)
        ->and(crgMenuIds())->toHaveCount(60)
        ->and(DB::table('menus')->whereIn('route_path', crgPaths())->where('route_path', 'like', '%/export')->where('is_hidden', 1)->count())->toBe(30);
});

test('the global companyadmin role gets exactly the 60 report rows', function () {
    $roleId = crgRole();
    $ledgerId = (int) DB::table('menus')->where('route_path', '/report/ledger')->value('id');

    crgMigration()->up();

    $granted = DB::table('permissions')->where('role_id', $roleId)->pluck('menu_id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();

    expect($granted)->toBe(collect(crgMenuIds())->sort()->values()->all())
        ->and($granted)->toHaveCount(60)
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('status', 1)->count())->toBe(60)
        ->and($granted)->not->toContain($ledgerId);

    $row = DB::table('permissions')->where('role_id', $roleId)->first();

    expect($row->company_id)->toBeNull()
        ->and($row->branch_id)->toBeNull()
        ->and($row->department_id)->toBeNull();
});

test('other roles and company specific admin roles are left alone', function () {
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'CRG001', 'name' => 'Grant Company', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    crgRole('test role', $companyId);
    crgRole('companyadmin', $companyId);
    crgRole('superadmin');
    crgRole('accountant');

    crgMigration()->up();

    expect(DB::table('permissions')->count())->toBe(0);
});

test('running it twice keeps one enabled row per menu and re-enables a denied one', function () {
    $roleId = crgRole();
    $menuIds = crgMenuIds();
    $denied = $menuIds[0];
    $kept = $menuIds[1];

    DB::table('permissions')->insert([
        ['role_id' => $roleId, 'menu_id' => $denied, 'status' => 0, 'created_at' => now(), 'updated_at' => now()],
        ['role_id' => $roleId, 'menu_id' => $kept, 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);

    crgMigration()->up();
    crgMigration()->up();

    expect(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(60)
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('menu_id', $denied)->value('status'))->toBe(1)
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('menu_id', $kept)->count())->toBe(1)
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('status', 1)->count())->toBe(60);
});

test('an existing permission of the role on another menu is not touched', function () {
    $roleId = crgRole();
    $ledgerId = (int) DB::table('menus')->where('route_path', '/report/ledger')->value('id');
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $ledgerId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

    crgMigration()->up();

    expect(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(61)
        ->and(DB::table('permissions')->where('role_id', $roleId)->where('menu_id', $ledgerId)->where('status', 1)->count())->toBe(1);
});

test('without the role or without the menu rows the migration does nothing, and a missing row is skipped', function () {
    crgMigration()->up();
    expect(DB::table('permissions')->count())->toBe(0);

    $roleId = crgRole();
    $gone = (int) DB::table('menus')->where('route_path', '/report/tax/export')->value('id');
    DB::table('menus')->where('id', $gone)->delete();

    crgMigration()->up();

    expect(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(59);

    DB::table('permissions')->delete();
    DB::table('menus')->whereIn('route_path', crgPaths())->delete();
    crgMigration()->up();

    expect(DB::table('permissions')->count())->toBe(0);
});

test('the granted paths show up for the role and the menu caches are flushed', function () {
    $roleId = crgRole();

    foreach (["user_permission_paths:{$roleId}", "user_menu_permissions_tree:{$roleId}", "user_menu_permissions:{$roleId}"] as $key) {
        Cache::put($key, ['stale'], 600);
    }

    crgMigration()->up();

    foreach (["user_permission_paths:{$roleId}", "user_menu_permissions_tree:{$roleId}", "user_menu_permissions:{$roleId}"] as $key) {
        expect(Cache::has($key))->toBeFalse();
    }

    $paths = Menu::permittedRoutePathsForRole($roleId);

    expect($paths)->toContain('/report/sell', '/report/sell/export', '/report/trial-balance', '/report/balance-sheet/export', '/report/customer-aging');
});

test('the company admin can then call the reports', function () {
    $roleId = crgRole();
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'CRG002', 'name' => 'Admin Company', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $branchId = DB::table('branches')->insertGetId([
        'code' => 'CRGB01', 'company_id' => $companyId, 'name' => 'Main', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $admin = createStaffUserForRole(Role::query()->findOrFail($roleId), [
        'company_id' => $companyId, 'branch_id' => $branchId,
    ]);

    Sanctum::actingAs($admin);
    test()->getJson('/api/reports/sell')->assertForbidden();

    crgMigration()->up();

    test()->getJson('/api/reports/sell')->assertSuccessful();
    test()->getJson('/api/reports/stock')->assertSuccessful();
});

test('rolling back removes only those rows', function () {
    $roleId = crgRole();
    $otherRole = crgRole('accountant');
    $ledgerId = (int) DB::table('menus')->where('route_path', '/report/ledger')->value('id');
    $sellId = (int) DB::table('menus')->where('route_path', '/report/sell')->value('id');
    DB::table('permissions')->insert([
        ['role_id' => $roleId, 'menu_id' => $ledgerId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['role_id' => $otherRole, 'menu_id' => $sellId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);

    crgMigration()->up();
    crgMigration()->down();

    expect(DB::table('permissions')->where('role_id', $roleId)->pluck('menu_id')->map(fn (mixed $id): int => (int) $id)->all())->toBe([$ledgerId])
        ->and(DB::table('permissions')->where('role_id', $otherRole)->where('menu_id', $sellId)->where('status', 1)->exists())->toBeTrue();

    crgMigration()->up();

    expect(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(61);
});
