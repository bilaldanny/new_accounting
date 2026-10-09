<?php

use App\Models\Menu;
use App\Models\Role;
use App\Models\User;
use App\Support\RolePresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * What each starter role (Accountant, Cashier, Sales Representative, Manager, Warehouse Keeper) can see and open, checked
 * against a copy of the live menu tree (tests/Fixtures/live-menus.json) because a fresh test database has only part of it.
 *
 * tests/Fixtures/role-preset-menus.json is the exact list of menu paths each preset grants. If a preset or a menu migration
 * changes the access of a role this fails on purpose: look at the difference, and when it is intended regenerate that file.
 */
function rpaLoadLiveMenus(): void
{
    DB::table('permissions')->delete();
    DB::table('menus')->delete();
    $rows = json_decode((string) file_get_contents(base_path('tests/Fixtures/live-menus.json')), true);

    foreach ($rows as $row) {
        DB::table('menus')->insert(array_merge($row, [
            'route_path' => $row['route_path'] ?? '', 'route_name' => $row['route_name'] ?? '', 'parent_id' => null,
            'menu_color' => '#000', 'icon' => '', 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    foreach ($rows as $row) {
        DB::table('menus')->where('id', $row['id'])->update(['parent_id' => $row['parent_id']]);
    }
}

function rpaStaffFor(string $preset): User
{
    rpaLoadLiveMenus();
    $scope = seedSellScope();

    test()->artisan('roles:sync-presets', ['company' => $scope['company_id'], 'branch' => $scope['branch_id'], '--only' => [$preset]])->assertSuccessful();

    $role = Role::query()->where('company_id', $scope['company_id'])->where('name', $preset)->firstOrFail();

    return createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'email_verified_at' => now()]);
}

/**
 * @param  array<int, array<string, mixed>>  $nodes
 * @return list<string>
 */
function rpaSidebarPaths(array $nodes): array
{
    $paths = [];

    foreach ($nodes as $node) {
        if (($node['route_path'] ?? '') !== '') {
            $paths[] = $node['route_path'];
        }

        $paths = array_merge($paths, rpaSidebarPaths($node['children'] ?? []));
    }

    return $paths;
}

/**
 * @return array<string, array{see: list<string>, hidden: list<string>, pagesOpen: list<string>, pagesDenied: list<string>, apiOpen: list<string>, apiDenied: list<string>}>
 */
function rpaExpectations(): array
{
    $everyoneNot = ['/subscriptionplans', '/tenants', '/subscriptioninvoices', '/webhooks', '/apikeys', '/backup', '/company/setting', '/role', '/user', '/menu'];
    $approvals = ['/journalentry/approval', '/assetapproval', '/expense/approval', '/acpayment/approval', '/sell/approval', '/purchase/approval', '/cashcollection/approval', '/creditlimit/approval'];
    $books = ['/journalentry', '/fixedasset', '/costcenter', '/budget', '/taxexemption', '/bankreconciliation', '/chart-of-account'];
    $commonDeniedPages = ['subscriptionplans', 'tenants', 'subscriptioninvoices', 'webhooks', 'apikeys', 'backup', 'company/setting', 'role', 'user'];
    $commonDeniedApi = ['subscriptionplans', 'tenants', 'subscriptioninvoices', 'webhooks', 'api-keys', 'backups', 'roles', 'users'];

    return [
        'Accountant' => [
            'see' => ['/fixedasset', '/costcenter', '/budget', '/taxexemption', '/bankreconciliation', '/journalentry', '/chart-of-account', '/tax', '/financialyear', '/report/trial-balance'],
            'hidden' => array_merge($approvals, $everyoneNot, ['/sell/pos', '/posshift', '/leads', '/stocktracking']),
            'pagesOpen' => ['fixedasset', 'costcenter', 'budget', 'taxexemption', 'bankreconciliation', 'journalentry', 'tax', 'financialyear'],
            'pagesDenied' => array_merge($commonDeniedPages, ['journalentry/approval', 'assetapproval', 'expense/approval', 'posshift', 'leads', 'pipeline', 'stocktracking']),
            'apiOpen' => ['fixed-assets', 'cost-centers', 'budgets', 'tax-exemptions', 'bank-reconciliations', 'journal-entries', 'chart-of-accounts', 'taxes', 'financialyears', 'reports/trial-balance'],
            'apiDenied' => array_merge($commonDeniedApi, ['journal-entry-approvals', 'asset-approvals', 'payment-approvals', 'expense-approvals', 'sell-approvals', 'purchase-approvals', 'pos-shifts', 'leads']),
        ],
        'Cashier' => [
            'see' => ['/sell/pos', '/posshift', '/posshift/open', '/posshift/movement', '/cashcollection', '/customer', '/product'],
            'hidden' => array_merge($approvals, $everyoneNot, $books, ['/leads', '/purchase', '/stocktransfer']),
            'pagesOpen' => ['posshift', 'cashcollection', 'customer', 'product'],
            'pagesDenied' => array_merge($commonDeniedPages, ['fixedasset', 'costcenter', 'budget', 'taxexemption', 'bankreconciliation', 'journalentry', 'journalentry/approval', 'assetapproval', 'cashcollection/approval', 'leads', 'purchase']),
            'apiOpen' => ['pos-shifts', 'pos-shifts/current', 'cash-collections', 'customers', 'products', 'reports/register'],
            'apiDenied' => array_merge($commonDeniedApi, ['fixed-assets', 'cost-centers', 'budgets', 'journal-entries', 'journal-entry-approvals', 'cash-collection-approvals', 'leads', 'purchases', 'taxes']),
        ],
        'Sales Representative' => [
            'see' => ['/leads', '/opportunities', '/pipeline', '/activities', '/crmanalytics', '/sell', '/customer'],
            'hidden' => array_merge($approvals, $everyoneNot, $books, ['/sell/pos', '/posshift', '/purchase']),
            'pagesOpen' => ['leads', 'opportunities', 'pipeline', 'sell', 'customer'],
            'pagesDenied' => array_merge($commonDeniedPages, ['fixedasset', 'costcenter', 'budget', 'journalentry', 'journalentry/approval', 'sell/approval', 'posshift', 'purchase']),
            'apiOpen' => ['leads', 'opportunities', 'crmanalytics/pipeline', 'sells', 'customers', 'reports/sell'],
            'apiDenied' => array_merge($commonDeniedApi, ['fixed-assets', 'journal-entries', 'sell-approvals', 'pos-shifts', 'purchases', 'taxes']),
        ],
        'Manager' => [
            'see' => ['/journalentry/approval', '/expense/approval', '/assetapproval', '/sell/approval', '/purchase/approval', '/creditlimit/approval', '/cashcollection/approval', '/stocktransfer/approval', '/stockadjustment/approval'],
            'hidden' => array_merge($everyoneNot, ['/journalentry/add', '/costcenter', '/budget', '/sell/pos', '/posshift', '/leads']),
            'pagesOpen' => ['journalentry/approval', 'assetapproval', 'expense/approval', 'sell/approval', 'purchase/approval', 'creditlimit/approval', 'cashcollection/approval', 'purchase', 'sell', 'journalentry', 'expense', 'purchaserequisition'],
            'pagesDenied' => array_merge($commonDeniedPages, ['journalentry/add', 'costcenter', 'budget', 'taxexemption', 'posshift', 'leads', 'stocktracking']),
            'apiOpen' => ['journal-entry-approvals', 'asset-approvals', 'payment-approvals', 'expense-approvals', 'sell-approvals', 'purchase-approvals', 'stock-transfer-approvals', 'cash-collection-approvals', 'sells', 'purchases', 'journal-entries', 'expenses', 'purchase-requisitions'],
            'apiDenied' => array_merge($commonDeniedApi, ['cost-centers', 'budgets', 'pos-shifts', 'leads', 'taxes']),
        ],
        'Warehouse Keeper' => [
            'see' => ['/warehouse', '/warehouselocation', '/stocktracking', '/stocktransfer', '/stockadjustment', '/stocktake', '/report/warehouse-stock', '/report/stock'],
            'hidden' => array_merge($approvals, $everyoneNot, $books, ['/sell/pos', '/leads', '/stocktransfer/approval']),
            'pagesOpen' => ['warehouse', 'warehouselocation', 'stocktracking', 'stocktransfer', 'stockadjustment', 'stocktake'],
            'pagesDenied' => array_merge($commonDeniedPages, ['fixedasset', 'costcenter', 'budget', 'journalentry', 'journalentry/approval', 'stocktransfer/approval', 'leads', 'posshift']),
            'apiOpen' => ['warehouses', 'stocktransfers', 'stock-takes', 'stock-tracking/serials', 'reports/warehouse-stock', 'products'],
            'apiDenied' => array_merge($commonDeniedApi, ['fixed-assets', 'cost-centers', 'journal-entries', 'stock-transfer-approvals', 'leads', 'pos-shifts', 'taxes']),
        ],
    ];
}

dataset('rpa-presets', array_keys(RolePresets::PRESETS));

test('each preset grants exactly the menu paths recorded for it', function (string $preset) {
    rpaLoadLiveMenus();

    $granted = DB::table('menus')->whereIn('id', RolePresets::menuIds($preset))->pluck('route_path')->unique()->sort()->values()->all();
    $recorded = json_decode((string) file_get_contents(base_path('tests/Fixtures/role-preset-menus.json')), true)[$preset];

    expect(array_values(array_diff($granted, $recorded)))->toBe([], 'granted but not recorded')
        ->and(array_values(array_diff($recorded, $granted)))->toBe([], 'recorded but no longer granted');
})->with('rpa-presets');

test('the sidebar of each preset shows its pages and hides the rest', function (string $preset) {
    $user = rpaStaffFor($preset);
    $expected = rpaExpectations()[$preset];

    $sidebar = rpaSidebarPaths(json_decode((string) json_encode(Menu::sidebarMenusForRole((int) $user->role_id)), true));
    $permitted = Menu::permittedRoutePathsForRole((int) $user->role_id);

    expect($sidebar)->toContain(...$expected['see'])
        ->and($sidebar)->not->toContain(...$expected['hidden'])
        ->and($permitted)->toContain(...$expected['see'])
        ->and($permitted)->not->toContain(...$expected['hidden']);
})->with('rpa-presets');

test('opening a page by its URL gives each preset a 403 for what it was not given', function (string $preset) {
    $user = rpaStaffFor($preset);
    $expected = rpaExpectations()[$preset];
    $statuses = [];

    foreach (array_merge($expected['pagesOpen'], $expected['pagesDenied']) as $page) {
        $statuses[$page] = test()->actingAs($user)->get('/'.$page)->status();
    }

    foreach ($expected['pagesOpen'] as $page) {
        expect($statuses[$page])->toBe(200, "{$preset} should open /{$page}");
    }

    foreach ($expected['pagesDenied'] as $page) {
        expect($statuses[$page])->toBe(403, "{$preset} should get 403 on /{$page}");
    }
})->with('rpa-presets');

test('the data behind those pages answers each preset the same way', function (string $preset) {
    $user = rpaStaffFor($preset);
    $expected = rpaExpectations()[$preset];
    Sanctum::actingAs($user);

    foreach ($expected['apiOpen'] as $uri) {
        expect(test()->getJson('/api/'.$uri)->status())->toBe(200, "{$preset} should read /api/{$uri}");
    }

    foreach ($expected['apiDenied'] as $uri) {
        expect(test()->getJson('/api/'.$uri)->status())->toBe(403, "{$preset} should get 403 on /api/{$uri}");
    }
})->with('rpa-presets');

test('only the Manager preset is given approval and reject menus, and no preset gets billing, tenant, integration or admin menus', function () {
    rpaLoadLiveMenus();
    $forbidden = '#^/(subscriptionplans|tenants|subscriptioninvoices|webhooks|apikeys|backup|portalusers|menu|role|user|company|software/setting|setting)(/|$)#';

    foreach (array_keys(RolePresets::PRESETS) as $preset) {
        $paths = DB::table('menus')->whereIn('id', RolePresets::menuIds($preset))->pluck('route_path')->all();
        $approvals = array_values(array_filter($paths, fn (string $path): bool => preg_match(RolePresets::APPROVAL_PATTERN, $path) === 1));
        $admin = array_values(array_filter($paths, fn (string $path): bool => preg_match($forbidden, $path) === 1));

        expect($admin)->toBe([], "{$preset} must not get admin, billing or integration menus");

        if ($preset === 'Manager') {
            expect($approvals)->not->toBe([]);
        } else {
            expect($approvals)->toBe([], "{$preset} must not get approval menus");
        }
    }
});

test('the menus the migrations create are all in the recorded live menu tree', function () {
    $recorded = collect(json_decode((string) file_get_contents(base_path('tests/Fixtures/live-menus.json')), true))->pluck('route_path')->filter()->all();
    $missing = array_values(array_diff(DB::table('menus')->where('route_path', '!=', '')->pluck('route_path')->all(), $recorded));

    expect($missing)->toBe([], 'a migration added menus that tests/Fixtures/live-menus.json and role-preset-menus.json do not know yet: refresh them');
});

test('a cashier can ring up a POS sale with the POS menu alone and cannot add an ordinary sale', function () {
    $user = rpaStaffFor('Cashier');
    $scope = ['company_id' => $user->company_id, 'branch_id' => $user->branch_id];
    Sanctum::actingAs($user);

    // The sale itself is refused for lack of data in this fixture, not for lack of permission.
    $pos = test()->postJson('/api/sells', ['is_pos' => true] + $scope);
    $ordinary = test()->postJson('/api/sells', ['is_pos' => false] + $scope);

    expect($pos->status())->not->toBe(403)
        ->and($ordinary->status())->toBe(403)
        ->and(test()->postJson('/api/sell-payments', $scope)->status())->not->toBe(403);
});

test('a company admin and the superadmin are not stopped at page URLs by unticked menus', function () {
    rpaLoadLiveMenus();
    $scope = seedSellScope();
    $role = Role::query()->firstOrCreate(['name' => 'companyadmin', 'company_id' => null], ['is_active' => true]);
    $admin = createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'email_verified_at' => now()]);

    foreach (['costcenter', 'company/setting', 'journalentry/approval'] as $page) {
        expect(test()->actingAs($admin)->get('/'.$page)->status())->toBe(200);
    }

    expect(test()->actingAs(User::query()->findOrFail(1))->get('/tenants')->status())->toBe(200);
});
