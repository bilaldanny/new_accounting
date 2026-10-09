<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const PHASE1_GRANTED_PATHS = [
    '/purchasereturn/approval', '/stockadjustment/approval', '/stocktransfer/approval', '/cashcollection/approval',
    '/pricelist/approval', '/creditlimit/approval', '/creditlimit/reject', '/creditlimit/add',
    '/contacts/duplicates', '/contacts/duplicates/merge', '/auditlogs',
];

function p1gMigration(): object
{
    return require database_path('migrations/2026_10_06_200000_grant_companyadmin_phase1_menus.php');
}

function p1gMenu(string $path): int
{
    return DB::table('menus')->insertGetId([
        'parent_id' => null, 'name' => $path, 'icon' => '', 'route_name' => ltrim(str_replace('/', '', $path), '/').uniqid(), 'route_path' => $path,
        'menu_color' => '#000000', 'sort_order' => 1, 'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0,
        'is_permission' => 0, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function p1gRole(string $name, ?int $companyId = null): int
{
    return DB::table('roles')->insertGetId([
        'name' => $name, 'company_id' => $companyId, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('the global companyadmin role gets exactly the eleven phase 1 rows', function () {
    DB::table('permissions')->delete();
    DB::table('menus')->delete();
    $granted = array_map('p1gMenu', PHASE1_GRANTED_PATHS);
    $other = p1gMenu('/purchase/approval');
    $roleId = p1gRole('companyadmin');

    p1gMigration()->up();

    $rows = DB::table('permissions')->where('role_id', $roleId)->get();

    expect($rows)->toHaveCount(11)
        ->and($rows->pluck('menu_id')->map(fn ($id): int => (int) $id)->sort()->values()->all())->toBe(collect($granted)->sort()->values()->all())
        ->and($rows->every(fn ($row): bool => (int) $row->status === 1))->toBeTrue()
        ->and(DB::table('permissions')->where('menu_id', $other)->exists())->toBeFalse();
});

test('other roles are left alone and running it twice changes nothing', function () {
    DB::table('permissions')->delete();
    DB::table('menus')->delete();
    array_map('p1gMenu', PHASE1_GRANTED_PATHS);
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'P1G001', 'name' => 'Grant Company', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $adminId = p1gRole('companyadmin');
    $others = [p1gRole('test role', $companyId), p1gRole('companyadmin', $companyId), p1gRole('portal')];

    p1gMigration()->up();
    p1gMigration()->up();

    expect(DB::table('permissions')->where('role_id', $adminId)->count())->toBe(11)
        ->and(DB::table('permissions')->whereIn('role_id', $others)->exists())->toBeFalse();

    p1gMigration()->down();

    expect(DB::table('permissions')->where('role_id', $adminId)->count())->toBe(0);
});
