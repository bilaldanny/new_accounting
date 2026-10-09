<?php

use App\Models\FixedAsset;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
});

/**
 * A company with the accounts a fixed-asset category needs.
 *
 * @return array{company_id: int, branch_id: int, accounts: array<string, int>}
 */
function faScope(string $suffix = 'FA'): array
{
    $scope = trpScope($suffix);

    return $scope + ['accounts' => [
        'asset' => ldgAccount($scope, '201-00010', 'Machinery', 't', 'dr'),
        'accumulated' => ldgAccount($scope, '201-00011', 'Accumulated Depreciation - Machinery', 't', 'cr'),
        'expense' => ldgAccount($scope, '401-00010', 'Depreciation Expense', 't', 'dr'),
        'cwip' => ldgAccount($scope, '201-00012', 'Capital Work in Progress', 't', 'dr'),
        'revaluation' => ldgAccount($scope, '101-00010', 'Revaluation Surplus', 't', 'cr'),
        'impairment' => ldgAccount($scope, '401-00011', 'Impairment Loss', 't', 'dr'),
        'disposal' => ldgAccount($scope, '401-00012', 'Gain / Loss on Disposal', 't', 'dr'),
        'bank' => ldgAccount($scope, '202-00010', 'Main Bank', 't', 'dr'),
        'transfer' => ldgAccount($scope, '202-00099', 'Inter-branch Transfer', 't', 'dr'),
    ]];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function faCategory(array $scope, array $overrides = []): int
{
    return test()->postJson('/api/asset-categories', array_merge([
        'company_id' => $scope['company_id'],
        'name' => 'Machinery',
        'method' => 'straight_line',
        'useful_life_months' => 60,
        'salvage_percent' => 10,
        'asset_coa_id' => $scope['accounts']['asset'],
        'accumulated_coa_id' => $scope['accounts']['accumulated'],
        'expense_coa_id' => $scope['accounts']['expense'],
        'cwip_coa_id' => $scope['accounts']['cwip'],
        'revaluation_coa_id' => $scope['accounts']['revaluation'],
        'impairment_coa_id' => $scope['accounts']['impairment'],
        'disposal_coa_id' => $scope['accounts']['disposal'],
        'transfer_coa_id' => $scope['accounts']['transfer'],
    ], $overrides))->assertSuccessful()->json('data.id');
}

/**
 * @param  array<string, mixed>  $overrides
 */
function faAssetPayload(array $scope, int $categoryId, array $overrides = []): array
{
    return array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'asset_category_id' => $categoryId,
        'name' => 'Packing line',
        'acquired_on' => '2025-01-01',
        'cost' => 12000,
        'accumulated_depreciation' => 2000,
        'depreciated_through' => '2025-12-31',
    ], $overrides);
}

test('a category keeps its depreciation defaults and its ledger accounts', function () {
    $scope = faScope();
    $id = faCategory($scope);

    $category = $this->getJson("/api/asset-categories/{$id}")->assertSuccessful()->json('data');

    expect($category['method'])->toBe('straight_line')
        ->and($category['useful_life_months'])->toBe(60)
        ->and($category['salvage_percent'])->toEqual(10)
        ->and($category['accumulated_account'])->toBe('Accumulated Depreciation - Machinery')
        ->and($category['cwip_account'])->toBe('Capital Work in Progress')
        ->and($category['transfer_account'])->toBe('Inter-branch Transfer')
        ->and($category['asset_count'])->toBe(0);

    $rows = $this->getJson('/api/asset-categories')->assertSuccessful()->json('data.data');
    expect($rows)->toHaveCount(1);
});

test('category input is validated: accounts must be the company\'s own posting accounts and the method needs its inputs', function () {
    $scope = faScope();
    $other = faScope('FB');

    $this->postJson('/api/asset-categories', ['company_id' => $scope['company_id'], 'name' => 'X', 'method' => 'straight_line', 'useful_life_months' => 12,
        'asset_coa_id' => $other['accounts']['asset'], 'accumulated_coa_id' => $scope['accounts']['accumulated'], 'expense_coa_id' => $scope['accounts']['expense']])
        ->assertUnprocessable()->assertJsonValidationErrors(['asset_coa_id']);

    $group = ldgAccount($scope, '201-00000', 'Fixed Assets', 'c', 'dr');
    $this->postJson('/api/asset-categories', ['company_id' => $scope['company_id'], 'name' => 'X', 'method' => 'straight_line', 'useful_life_months' => 12,
        'asset_coa_id' => $group, 'accumulated_coa_id' => $scope['accounts']['accumulated'], 'expense_coa_id' => $scope['accounts']['expense']])
        ->assertUnprocessable()->assertJsonValidationErrors(['asset_coa_id']);

    $base = ['company_id' => $scope['company_id'], 'name' => 'Y', 'asset_coa_id' => $scope['accounts']['asset'], 'accumulated_coa_id' => $scope['accounts']['accumulated'], 'expense_coa_id' => $scope['accounts']['expense']];

    $this->postJson('/api/asset-categories', $base + ['method' => 'straight_line'])->assertUnprocessable()->assertJsonValidationErrors(['useful_life_months']);
    $this->postJson('/api/asset-categories', $base + ['method' => 'wdv'])->assertUnprocessable()->assertJsonValidationErrors(['rate']);
    $this->postJson('/api/asset-categories', $base + ['method' => 'declining_balance', 'useful_life_months' => 60, 'rate' => 9])->assertUnprocessable()->assertJsonValidationErrors(['rate']);
    $this->postJson('/api/asset-categories', $base + ['method' => 'wdv', 'rate' => 20])->assertSuccessful();
    $this->postJson('/api/asset-categories', $base + ['method' => 'wdv', 'rate' => 20])->assertUnprocessable()->assertJsonValidationErrors(['name']);
});

test('an asset carried over from before the register takes the category defaults and posts nothing', function () {
    $scope = faScope();
    $categoryId = faCategory($scope);
    $vouchers = DB::table('t_accounts')->count();

    $asset = $this->postJson('/api/fixed-assets', faAssetPayload($scope, $categoryId))->assertSuccessful()->json('data');

    expect($asset['code'])->toBe('FA-00001')
        ->and($asset['status'])->toBe('active')
        ->and($asset['source'])->toBe('opening')
        ->and($asset['method'])->toBe('straight_line')
        ->and($asset['useful_life_months'])->toBe(60)
        ->and($asset['salvage_value'])->toEqual(1200)
        ->and($asset['in_service_on'])->toBe('2025-01-01')
        ->and($asset['book_value'])->toEqual(10000)
        ->and(DB::table('t_accounts')->count())->toBe($vouchers);

    $second = $this->postJson('/api/fixed-assets', faAssetPayload($scope, $categoryId, ['name' => 'Forklift', 'method' => 'wdv', 'rate' => 15, 'salvage_value' => 500]))->assertSuccessful()->json('data');

    expect($second['code'])->toBe('FA-00002')
        ->and($second['method'])->toBe('wdv')
        ->and($second['salvage_value'])->toEqual(500);
});

test('asset input is validated', function () {
    $scope = faScope();
    $other = faScope('FB');
    $categoryId = faCategory($scope);
    $otherCategory = faCategory($other);

    $this->postJson('/api/fixed-assets', faAssetPayload($scope, $categoryId, ['salvage_value' => 12000]))->assertUnprocessable()->assertJsonValidationErrors(['salvage_value']);
    $this->postJson('/api/fixed-assets', faAssetPayload($scope, $categoryId, ['accumulated_depreciation' => 11500]))->assertUnprocessable()->assertJsonValidationErrors(['accumulated_depreciation']);
    $this->postJson('/api/fixed-assets', faAssetPayload($scope, $otherCategory))->assertUnprocessable()->assertJsonValidationErrors(['asset_category_id']);
    $this->postJson('/api/fixed-assets', faAssetPayload($scope, $categoryId, ['branch_id' => $other['branch_id']]))->assertUnprocessable()->assertJsonValidationErrors(['branch_id']);
    $this->postJson('/api/fixed-assets', faAssetPayload($scope, $categoryId, ['method' => 'wdv']))->assertUnprocessable()->assertJsonValidationErrors(['rate']);
    $this->postJson('/api/fixed-assets', faAssetPayload($scope, $categoryId, ['cost' => 0]))->assertUnprocessable()->assertJsonValidationErrors(['cost']);
    $this->postJson('/api/fixed-assets', faAssetPayload($scope, $categoryId, ['depreciated_through' => null]))->assertUnprocessable()->assertJsonValidationErrors(['depreciated_through']);

    expect(FixedAsset::query()->count())->toBe(0);
});

test('an untouched asset can be edited and deleted, a depreciated one only has its details changed', function () {
    $scope = faScope();
    $categoryId = faCategory($scope);
    $id = $this->postJson('/api/fixed-assets', faAssetPayload($scope, $categoryId))->assertSuccessful()->json('data.id');

    $this->putJson("/api/fixed-assets/{$id}", faAssetPayload($scope, $categoryId, ['cost' => 20000, 'name' => 'Packing line 2']))->assertSuccessful()
        ->assertJsonPath('data.cost', 20000)->assertJsonPath('data.name', 'Packing line 2');

    DB::table('fixed_asset_depreciations')->insert([
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'fixed_asset_id' => $id, 'period' => '2026-01', 'period_end' => '2026-01-31',
        'amount' => 100, 'opening_book_value' => 10000, 'closing_book_value' => 9900, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->putJson("/api/fixed-assets/{$id}", faAssetPayload($scope, $categoryId, ['cost' => 99999, 'name' => 'Renamed', 'serial_no' => 'SN-1']))->assertSuccessful()
        ->assertJsonPath('data.cost', 20000)->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.serial_no', 'SN-1');

    $this->deleteJson("/api/fixed-assets/{$id}")->assertUnprocessable()->assertJsonValidationErrors(['asset']);

    $untouched = $this->postJson('/api/fixed-assets', faAssetPayload($scope, $categoryId, ['name' => 'Spare']))->assertSuccessful()->json('data.id');
    $this->deleteJson("/api/fixed-assets/{$untouched}")->assertSuccessful();
    expect(FixedAsset::query()->whereKey($untouched)->exists())->toBeFalse();

    $this->deleteJson("/api/asset-categories/{$categoryId}")->assertUnprocessable()->assertJsonValidationErrors(['category']);
});

test('the register is filtered by status and search and totals are by company', function () {
    $scope = faScope();
    $categoryId = faCategory($scope);
    $this->postJson('/api/fixed-assets', faAssetPayload($scope, $categoryId))->assertSuccessful();
    $this->postJson('/api/fixed-assets', faAssetPayload($scope, $categoryId, ['name' => 'Truck', 'serial_no' => 'TRK-9']))->assertSuccessful();
    FixedAsset::query()->where('name', 'Truck')->update(['status' => 'disposed']);

    expect($this->getJson('/api/fixed-assets')->json('data.data'))->toHaveCount(2)
        ->and($this->getJson('/api/fixed-assets?status=disposed')->json('data.data'))->toHaveCount(1)
        ->and($this->getJson('/api/fixed-assets?search=TRK')->json('data.data.0.name'))->toBe('Truck');
});

test('another company\'s assets are invisible and a user without the permission is turned away', function () {
    $scope = faScope();
    $other = faScope('FB');
    $categoryId = faCategory($scope);
    $otherId = $this->postJson('/api/fixed-assets', faAssetPayload($other, faCategory($other)))->assertSuccessful()->json('data.id');
    $this->postJson('/api/fixed-assets', faAssetPayload($scope, $categoryId))->assertSuccessful();

    $role = Role::query()->create(['name' => 'assetclerk', 'company_id' => $scope['company_id'], 'is_active' => true]);
    Sanctum::actingAs(createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']]));

    $this->getJson('/api/fixed-assets')->assertForbidden();
    $this->postJson('/api/fixed-assets', faAssetPayload($scope, $categoryId))->assertForbidden();

    grantMenuPermission($role->id, '/fixedasset');
    $this->getJson('/api/fixed-assets')->assertSuccessful()->assertJsonCount(1, 'data.data');
    $this->getJson("/api/fixed-assets/{$otherId}")->assertNotFound();
});

test('the menu migration adds both pages next to Journal Entries and grants them to companyadmin', function () {
    DB::table('permissions')->delete();
    DB::table('menus')->delete();
    $group = DB::table('menus')->insertGetId([
        'parent_id' => null, 'name' => 'Accounts', 'icon' => '', 'route_name' => 'accgroup', 'route_path' => '', 'menu_color' => '#000', 'sort_order' => 1,
        'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 0, 'type' => 2, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('menus')->insert([
        'parent_id' => $group, 'name' => 'Journal Entries', 'icon' => '', 'route_name' => 'journalentry', 'route_path' => '/journalentry', 'menu_color' => '#000', 'sort_order' => 1,
        'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 0, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $roleId = DB::table('roles')->insertGetId(['name' => 'companyadmin', 'company_id' => null, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    (require database_path('migrations/2026_10_11_100100_add_fixed_asset_menus.php'))->up();

    $paths = DB::table('menus')->where(fn ($q) => $q->where('route_path', 'like', '/fixedasset%')->orWhere('route_path', 'like', '/assetcategory%'))->orderBy('route_path')->pluck('route_path')->all();

    expect($paths)->toBe(['/assetcategory', '/assetcategory/:id/edit', '/assetcategory/add', '/assetcategory/delete', '/fixedasset', '/fixedasset/:id/edit', '/fixedasset/add', '/fixedasset/delete'])
        ->and((int) DB::table('menus')->where('route_path', '/fixedasset')->value('parent_id'))->toBe($group)
        ->and(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(8);
});
