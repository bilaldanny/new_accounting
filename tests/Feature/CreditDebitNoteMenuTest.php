<?php

use App\Models\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The two migrations that add the Credit/Debit Note menu (2026_09_27_110000) and grant it to the
 * global companyadmin role (2026_09_27_120000). Neither is run against the live database yet.
 */
const CDN_MENU_PATHS = [
    '/creditdebitnote', '/creditdebitnote/add', '/creditdebitnote/:id/edit', '/creditdebitnote/:id/view',
    '/creditdebitnote/delete', '/creditdebitnote/:id/approve', '/creditdebitnote/:id/reject', '/creditdebitnote/approval',
];

function cdnMenuMigration(): object
{
    return require database_path('migrations/2026_09_27_110000_add_credit_debit_note_menu.php');
}

function cdnGrantMigration(): object
{
    return require database_path('migrations/2026_09_27_120000_grant_companyadmin_credit_debit_note_menu.php');
}

/**
 * The live shape these migrations rely on: an "Accounts" group with `/acpayment` in it, and an
 * "Approval" group with `/purchase/approval` in it.
 *
 * @return array{accounts_group: int, approval_group: int}
 */
function cdnSeedLiveShape(): array
{
    DB::table('permissions')->delete();
    DB::table('menus')->delete();

    $accountsGroup = DB::table('menus')->insertGetId([
        'parent_id' => null, 'name' => 'Accounts', 'icon' => '', 'route_name' => '', 'route_path' => '',
        'menu_color' => '#000', 'sort_order' => 1, 'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0,
        'is_permission' => 0, 'type' => 2, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('menus')->insert([
        'parent_id' => $accountsGroup, 'name' => 'Payment', 'icon' => '', 'route_name' => '', 'route_path' => '/acpayment',
        'menu_color' => '#000', 'sort_order' => 4, 'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0,
        'is_permission' => 0, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $approvalGroup = DB::table('menus')->insertGetId([
        'parent_id' => null, 'name' => 'Approval', 'icon' => '', 'route_name' => '', 'route_path' => '',
        'menu_color' => '#000', 'sort_order' => 2, 'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0,
        'is_permission' => 0, 'type' => 2, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('menus')->insert([
        'parent_id' => $approvalGroup, 'name' => 'Purchase Approval', 'icon' => '', 'route_name' => '', 'route_path' => '/purchase/approval',
        'menu_color' => '#000', 'sort_order' => 1, 'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0,
        'is_permission' => 0, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return ['accounts_group' => $accountsGroup, 'approval_group' => $approvalGroup];
}

// --- the menu migration ---------------------------------------------------------------------------

test('it adds the list row under Accounts, six hidden action rows, and the approval row under Approval', function () {
    $groups = cdnSeedLiveShape();

    cdnMenuMigration()->up();

    $list = DB::table('menus')->where('route_path', '/creditdebitnote')->first();
    $approval = DB::table('menus')->where('route_path', '/creditdebitnote/approval')->first();
    $hiddenPaths = ['/creditdebitnote/add', '/creditdebitnote/:id/edit', '/creditdebitnote/:id/view', '/creditdebitnote/delete', '/creditdebitnote/:id/approve', '/creditdebitnote/:id/reject'];

    expect($list)->not->toBeNull()
        ->and((int) $list->parent_id)->toBe($groups['accounts_group'])
        ->and((int) $list->is_hidden)->toBe(0)
        ->and($approval)->not->toBeNull()
        ->and((int) $approval->parent_id)->toBe($groups['approval_group'])
        ->and((int) $approval->is_hidden)->toBe(0);

    foreach ($hiddenPaths as $path) {
        $row = DB::table('menus')->where('route_path', $path)->first();
        expect($row)->not->toBeNull()
            ->and((int) $row->parent_id)->toBe((int) $list->id)
            ->and((int) $row->is_hidden)->toBe(1);
    }

    expect(DB::table('menus')->whereIn('route_path', CDN_MENU_PATHS)->count())->toBe(8);
});

test('running the menu migration twice does not duplicate anything', function () {
    cdnSeedLiveShape();

    cdnMenuMigration()->up();
    cdnMenuMigration()->up();

    expect(DB::table('menus')->whereIn('route_path', CDN_MENU_PATHS)->count())->toBe(8);
});

test('rolling back the menu migration removes exactly those rows and their permissions', function () {
    cdnSeedLiveShape();
    cdnMenuMigration()->up();
    $ids = DB::table('menus')->whereIn('route_path', CDN_MENU_PATHS)->pluck('id');
    $roleId = DB::table('roles')->insertGetId(['name' => 'someone', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $ids->first(), 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

    cdnMenuMigration()->down();

    expect(DB::table('menus')->whereIn('route_path', CDN_MENU_PATHS)->count())->toBe(0)
        ->and(DB::table('permissions')->whereIn('menu_id', $ids)->count())->toBe(0);
});

test('without the Approval group, everything but the approval row is still added', function () {
    DB::table('permissions')->delete();
    DB::table('menus')->delete();

    $accountsGroup = DB::table('menus')->insertGetId([
        'parent_id' => null, 'name' => 'Accounts', 'icon' => '', 'route_name' => '', 'route_path' => '',
        'menu_color' => '#000', 'sort_order' => 1, 'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0,
        'is_permission' => 0, 'type' => 2, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('menus')->insert([
        'parent_id' => $accountsGroup, 'name' => 'Payment', 'icon' => '', 'route_name' => '', 'route_path' => '/acpayment',
        'menu_color' => '#000', 'sort_order' => 1, 'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0,
        'is_permission' => 0, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    cdnMenuMigration()->up();

    expect(DB::table('menus')->where('route_path', '/creditdebitnote')->exists())->toBeTrue()
        ->and(DB::table('menus')->where('route_path', '/creditdebitnote/approval')->exists())->toBeFalse();
});

// --- the companyadmin grant migration ---------------------------------------------------------------

test('the global companyadmin role gets exactly the eight credit/debit note rows', function () {
    cdnSeedLiveShape();
    cdnMenuMigration()->up();
    $roleId = DB::table('roles')->insertGetId(['name' => 'companyadmin', 'company_id' => null, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    cdnGrantMigration()->up();

    $granted = DB::table('permissions')->join('menus', 'menus.id', '=', 'permissions.menu_id')->where('permissions.role_id', $roleId)->pluck('menus.route_path')->sort()->values()->all();

    expect($granted)->toBe(collect(CDN_MENU_PATHS)->sort()->values()->all())
        ->and(DB::table('permissions')->where('role_id', $roleId)->pluck('status')->unique()->all())->toBe([1]);
});

test('a company-specific admin role and other roles are left alone', function () {
    cdnSeedLiveShape();
    cdnMenuMigration()->up();
    $companyId = DB::table('companies')->insertGetId(['code' => 'CDNG1', 'name' => 'Grant Co', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('roles')->insert(['name' => 'companyadmin', 'company_id' => $companyId, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('roles')->insert(['name' => 'cashier', 'company_id' => null, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    cdnGrantMigration()->up();

    expect(DB::table('permissions')->count())->toBe(0);
});

test('running the grant migration twice keeps one enabled row each', function () {
    cdnSeedLiveShape();
    cdnMenuMigration()->up();
    $roleId = DB::table('roles')->insertGetId(['name' => 'companyadmin', 'company_id' => null, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    cdnGrantMigration()->up();
    cdnGrantMigration()->up();

    $rows = DB::table('permissions')->where('role_id', $roleId)->get();
    expect($rows)->toHaveCount(8)
        ->and($rows->every(fn ($row): bool => (int) $row->status === 1))->toBeTrue();
});

test('the menu cache is flushed and the granted paths show up for the role', function () {
    cdnSeedLiveShape();
    cdnMenuMigration()->up();
    $roleId = DB::table('roles')->insertGetId(['name' => 'companyadmin', 'company_id' => null, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    Cache::put("user_permission_paths:{$roleId}", ['stale'], 600);

    cdnGrantMigration()->up();

    expect(Cache::has("user_permission_paths:{$roleId}"))->toBeFalse()
        ->and(Menu::permittedRoutePathsForRole($roleId))->toContain('/creditdebitnote', '/creditdebitnote/approval', '/creditdebitnote/:id/approve');
});

test('rolling back the grant migration removes only those rows', function () {
    cdnSeedLiveShape();
    cdnMenuMigration()->up();
    $roleId = DB::table('roles')->insertGetId(['name' => 'companyadmin', 'company_id' => null, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $otherMenuId = DB::table('menus')->where('route_path', '/acpayment')->value('id');
    DB::table('permissions')->insert(['role_id' => $roleId, 'menu_id' => $otherMenuId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);

    cdnGrantMigration()->up();
    cdnGrantMigration()->down();

    expect(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(1)
        ->and(DB::table('permissions')->where('role_id', $roleId)->value('menu_id'))->toBe($otherMenuId);
});
