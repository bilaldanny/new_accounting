<?php

use App\Models\Role;
use App\Support\RolePresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $extra
 */
function rpMenu(string $path, ?int $parentId = null, array $extra = []): int
{
    return DB::table('menus')->insertGetId($extra + [
        'parent_id' => $parentId, 'name' => $path, 'icon' => '', 'route_name' => ltrim($path, '/'), 'route_path' => $path, 'menu_color' => '#000',
        'sort_order' => 1, 'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function rpCompanyAdmin(): int
{
    return (int) DB::table('roles')->where('name', 'companyadmin')->whereNull('company_id')->value('id')
        ?: DB::table('roles')->insertGetId(['name' => 'companyadmin', 'company_id' => null, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
}

function rpGranted(int $roleId): array
{
    return DB::table('permissions')->join('menus', 'menus.id', '=', 'permissions.menu_id')
        ->where('permissions.role_id', $roleId)->where('permissions.status', 1)->orderBy('route_path')->pluck('route_path')->all();
}

function rpRepairMigration(): object
{
    return require database_path('migrations/2026_10_27_100000_repair_menus_and_grant_companyadmin_gaps.php');
}

function rpSeedMenus(): void
{
    DB::table('permissions')->delete();
    DB::table('menus')->delete();

    foreach (['/journalentry', '/journalentry/add', '/journalentry/approval', '/journalentry/:id/approve', '/expense', '/expense/approval', '/tax', '/report/trial-balance', '/report/stock',
        '/sell', '/sell/pos', '/sell/price-override', '/sell/approval', '/posshift', '/cashcollection', '/cashcollection/reverse', '/cashcollection/approval',
        '/assetapproval', '/creditlimit/approval', '/creditlimit/reject', '/purchasereturn/approval', '/stockadjustment/approval', '/stocktransfer/approval', '/leads', '/leads/add',
        '/stocktransfer', '/stocktransfer/add', '/stocktransfer/:id/edit', '/stocktransfer/delete', '/stockadjustment', '/stockadjustment/add', '/stockadjustment/:id/edit', '/stockadjustment/delete',
        '/opportunities', '/pipeline', '/activities', '/crmanalytics', '/subscriptionplans', '/tenants'] as $path) {
        rpMenu($path);
    }
}

test('the repair migration adds the missing pages beside a top-level Warehouse row and grants companyadmin CRM and stock actions', function () {
    rpSeedMenus();
    $warehouse = rpMenu('/warehouse');
    $roleId = rpCompanyAdmin();

    rpRepairMigration()->up();

    expect((int) DB::table('menus')->where('route_path', '/stocktracking')->value('parent_id'))->toBe(0)
        ->and(DB::table('menus')->where('route_path', '/warehouselocation/add')->value('is_hidden'))->toBe(1)
        ->and(DB::table('menus')->where('route_path', 'like', '/stocktracking%')->count())->toBe(3)
        ->and(DB::table('menus')->where('route_path', 'like', '/warehouselocation%')->count())->toBe(4)
        ->and(rpGranted($roleId))->toContain('/leads', '/leads/add', '/pipeline', '/crmanalytics', '/stocktransfer/add', '/stockadjustment/delete', '/stocktracking', '/stocktracking/edit', '/warehouselocation/:id/edit')
        ->and(rpGranted($roleId))->not->toContain('/subscriptionplans', '/tenants', '/journalentry', '/stocktransfer', '/stockadjustment')
        ->and($warehouse)->toBeInt();

    // Running it again changes nothing.
    $menus = DB::table('menus')->count();
    $grants = DB::table('permissions')->count();
    rpRepairMigration()->up();
    expect(DB::table('menus')->count())->toBe($menus)->and(DB::table('permissions')->count())->toBe($grants);
});

test('the repair migration can be rolled back', function () {
    rpSeedMenus();
    rpMenu('/warehouse');
    $roleId = rpCompanyAdmin();

    rpRepairMigration()->up();
    rpRepairMigration()->down();

    expect(DB::table('menus')->where('route_path', 'like', '/stocktracking%')->exists())->toBeFalse()
        ->and(DB::table('menus')->where('route_path', 'like', '/warehouselocation%')->exists())->toBeFalse()
        ->and(rpGranted($roleId))->toBe([]);
});

test('the repair migration leaves a schema without the Warehouse row alone', function () {
    rpSeedMenus();
    rpCompanyAdmin();

    rpRepairMigration()->up();

    expect(DB::table('menus')->where('route_path', '/stocktracking')->exists())->toBeFalse();
});

test('an accountant can record documents but never approve them, a manager approves, a cashier cannot reverse', function () {
    rpSeedMenus();
    $paths = fn (string $preset): array => DB::table('menus')->whereIn('id', RolePresets::menuIds($preset))->orderBy('route_path')->pluck('route_path')->all();

    expect($paths('Accountant'))->toContain('/journalentry', '/journalentry/add', '/expense', '/tax', '/report/trial-balance')
        ->and($paths('Accountant'))->not->toContain('/journalentry/approval', '/journalentry/:id/approve', '/expense/approval', '/assetapproval', '/sell');

    expect($paths('Manager'))->toContain('/journalentry/approval', '/expense/approval', '/assetapproval', '/creditlimit/approval', '/creditlimit/reject', '/purchasereturn/approval', '/stockadjustment/approval', '/stocktransfer/approval', '/cashcollection/approval', '/sell/approval')
        ->and($paths('Manager'))->not->toContain('/journalentry/add', '/tax');

    expect($paths('Cashier'))->toContain('/sell/pos', '/posshift', '/cashcollection')
        ->and($paths('Cashier'))->not->toContain('/cashcollection/reverse', '/cashcollection/approval', '/sell');

    expect($paths('Sales Representative'))->toContain('/leads', '/leads/add', '/opportunities', '/pipeline', '/sell', '/crmanalytics')
        ->and($paths('Sales Representative'))->not->toContain('/sell/pos', '/sell/price-override', '/sell/approval');
});

test('a role named like a preset is topped up in its own scope and the migration rolls back', function () {
    $scope = seedSellScope();
    rpSeedMenus();
    $company = (int) $scope['company_id'];
    $branch = (int) $scope['branch_id'];
    $roleId = DB::table('roles')->insertGetId(['name' => 'Sales Representative', 'company_id' => $company, 'branch_id' => $branch, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $other = DB::table('roles')->insertGetId(['name' => 'Night Guard', 'company_id' => $company, 'branch_id' => $branch, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    $migration = require database_path('migrations/2026_10_27_100100_grant_preset_roles_their_menus.php');
    $migration->up();

    expect(rpGranted($roleId))->toContain('/leads', '/sell')
        ->and(DB::table('permissions')->where('role_id', $roleId)->distinct()->pluck('company_id')->all())->toBe([$company])
        ->and(DB::table('permissions')->where('role_id', $roleId)->distinct()->pluck('branch_id')->all())->toBe([$branch])
        ->and(DB::table('permissions')->where('role_id', $other)->exists())->toBeFalse();

    $migration->down();
    expect(DB::table('permissions')->where('role_id', $roleId)->exists())->toBeFalse();
});

test('the sync command creates the starter roles once and rejects a branch of another company', function () {
    $scope = seedSellScope();
    rpSeedMenus();
    $company = (int) $scope['company_id'];
    $branch = (int) $scope['branch_id'];

    $this->artisan('roles:sync-presets', ['company' => $company, 'branch' => $branch, '--only' => ['Cashier', 'Manager']])->assertSuccessful();
    $this->artisan('roles:sync-presets', ['company' => $company, 'branch' => $branch, '--only' => ['Cashier', 'Manager']])->assertSuccessful();

    expect(Role::query()->where('company_id', $company)->whereIn('name', ['Cashier', 'Manager'])->count())->toBe(2)
        ->and(Role::query()->where('name', 'Accountant')->exists())->toBeFalse();

    $cashier = (int) Role::query()->where('name', 'Cashier')->value('id');
    expect(rpGranted($cashier))->toContain('/sell/pos');

    $this->artisan('roles:sync-presets', ['company' => $company, 'branch' => 999999])->assertFailed();
});
