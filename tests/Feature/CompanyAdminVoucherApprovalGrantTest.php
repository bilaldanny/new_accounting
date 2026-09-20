<?php

use App\Models\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** The 13 route paths the migration grants: 4 list rows, 8 approve and reject rows, and the payment view row. */
const VOUCHER_APPROVAL_GRANTED_PATHS = [
    '/acpayment/approval', '/acpayment/:id/approve', '/acpayment/:id/reject', '/acpayment/:id/view',
    '/expense/approval', '/expense/:id/approve', '/expense/:id/reject',
    '/deposit/approval', '/deposit/:id/approve', '/deposit/:id/reject',
    '/fundtransfer/approval', '/fundtransfer/:id/approve', '/fundtransfer/:id/reject',
];

function vagMigration(): object
{
    return require database_path('migrations/2026_09_20_120000_grant_companyadmin_voucher_approval_menus.php');
}

function vagMenu(string $path, int $hidden = 0): int
{
    return DB::table('menus')->insertGetId([
        'parent_id' => null, 'name' => $path, 'icon' => '', 'route_name' => ltrim(str_replace('/', '', $path), '/').uniqid(), 'route_path' => $path,
        'menu_color' => '#000000', 'sort_order' => 1, 'is_hidden' => $hidden, 'is_active' => 1, 'is_admin' => 0,
        'is_permission' => 0, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * The 13 rows the menu migration creates, and a few nearby rows that must not be granted.
 *
 * @return array{granted: list<int>, others: list<int>}
 */
function vagSeedMenus(): array
{
    DB::table('permissions')->delete();
    DB::table('menus')->delete();

    $granted = array_map(fn (string $path): int => vagMenu($path, str_contains($path, ':id') ? 1 : 0), VOUCHER_APPROVAL_GRANTED_PATHS);
    $others = array_map(fn (string $path): int => vagMenu($path), ['/purchase/approval', '/journalentry/approval', '/acpayment', '/expense/:id/view']);

    return ['granted' => $granted, 'others' => $others];
}

function vagRole(string $name = 'companyadmin', ?int $companyId = null): int
{
    return DB::table('roles')->insertGetId([
        'name' => $name, 'company_id' => $companyId, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('the global companyadmin role gets exactly the thirteen voucher approval rows', function () {
    $menus = vagSeedMenus();
    $roleId = vagRole();

    vagMigration()->up();

    $rows = DB::table('permissions')->where('role_id', $roleId)->get();
    $granted = $rows->pluck('menu_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
    $expected = collect($menus['granted'])->sort()->values()->all();

    expect(VOUCHER_APPROVAL_GRANTED_PATHS)->toHaveCount(13)
        ->and($rows)->toHaveCount(13)
        ->and($granted)->toBe($expected)
        ->and($rows->every(fn ($row): bool => (int) $row->status === 1))->toBeTrue()
        ->and(DB::table('permissions')->whereIn('menu_id', $menus['others'])->exists())->toBeFalse();
});

test('other roles and company specific admin roles are left alone', function () {
    vagSeedMenus();
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'VAG001', 'name' => 'Grant Company', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    vagRole('test role', $companyId);
    vagRole('companyadmin', $companyId);
    vagRole('superadmin');

    vagMigration()->up();

    expect(DB::table('permissions')->count())->toBe(0);
});

test('running it twice keeps one enabled row each and re-enables a denied one', function () {
    $menus = vagSeedMenus();
    $roleId = vagRole();
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $menus['granted'][0], 'status' => 0, 'created_at' => now(), 'updated_at' => now()]);

    vagMigration()->up();
    vagMigration()->up();

    $rows = DB::table('permissions')->where('role_id', $roleId)->get();

    expect($rows)->toHaveCount(13)
        ->and($rows->every(fn ($row): bool => (int) $row->status === 1))->toBeTrue();
});

test('a menu row that does not exist is skipped and without the role nothing happens', function () {
    DB::table('permissions')->delete();
    DB::table('menus')->delete();
    vagMenu('/expense/approval');
    vagMenu('/expense/:id/approve', 1);

    vagMigration()->up();
    expect(DB::table('permissions')->count())->toBe(0);

    $roleId = vagRole();
    vagMigration()->up();

    expect(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(2);
});

test('the granted paths show up for the role and the menu cache is flushed', function () {
    vagSeedMenus();
    $roleId = vagRole();
    Cache::put("user_permission_paths:{$roleId}", ['stale'], 600);

    vagMigration()->up();

    expect(Cache::has("user_permission_paths:{$roleId}"))->toBeFalse()
        ->and(Menu::permittedRoutePathsForRole($roleId))->toContain('/expense/:id/approve', '/fundtransfer/approval', '/acpayment/:id/view');
});

test('rolling back removes only those rows', function () {
    $menus = vagSeedMenus();
    $roleId = vagRole();
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $menus['others'][1], 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

    vagMigration()->up();
    vagMigration()->down();

    expect(DB::table('permissions')->whereIn('menu_id', $menus['granted'])->exists())->toBeFalse()
        ->and(DB::table('permissions')->where('menu_id', $menus['others'][1])->where('status', 1)->exists())->toBeTrue();
});

test('the granted paths are exactly the rows the menu migration creates', function () {
    $source = file_get_contents(database_path('migrations/2026_09_20_110000_add_voucher_approval_menus.php'));

    foreach (['/acpayment', '/expense', '/deposit', '/fundtransfer'] as $path) {
        expect(VOUCHER_APPROVAL_GRANTED_PATHS)->toContain("{$path}/approval", "{$path}/:id/approve", "{$path}/:id/reject")
            ->and($source)->toContain("'{$path}' =>");
    }

    expect(VOUCHER_APPROVAL_GRANTED_PATHS)->toContain('/acpayment/:id/view')
        ->and($source)->toContain("'/acpayment/:id/view'");
});
