<?php

use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\Role;
use App\Models\TAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
});

/**
 * An asset put in service on 1 Jan 2026 with nothing depreciated yet: cost 12,000, salvage 1,200 (the category's
 * 10%), 60 months.
 *
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $overrides
 */
function fdAsset(array $scope, int $categoryId, array $overrides = []): int
{
    return test()->postJson('/api/fixed-assets', array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'asset_category_id' => $categoryId,
        'name' => 'Packing line',
        'acquired_on' => '2026-01-01',
        'cost' => 12000,
        'accumulated_depreciation' => 0,
    ], $overrides))->assertSuccessful()->json('data.id');
}

/**
 * @param  array<string, mixed>  $scope
 * @return array<string, mixed>
 */
function fdRun(array $scope, string $period, array $extra = []): array
{
    return test()->postJson('/api/depreciation/run', ['company_id' => $scope['company_id'], 'period' => $period] + $extra)->assertSuccessful()->json('data');
}

test('straight-line depreciation takes cost less salvage over the useful life, one month at a time', function () {
    $scope = faScope();
    $id = fdAsset($scope, faCategory($scope));

    $preview = $this->getJson('/api/depreciation/preview?company_id='.$scope['company_id'].'&period=2026-03')->assertSuccessful()->json('data');

    expect($preview['rows'])->toHaveCount(3)
        ->and(collect($preview['rows'])->pluck('amount')->all())->toEqual([180, 180, 180])
        ->and(collect($preview['rows'])->pluck('closing_book_value')->all())->toEqual([11820, 11640, 11460])
        ->and($preview['total'])->toEqual(540)
        ->and(FixedAssetDepreciation::query()->count())->toBe(0);

    $result = fdRun($scope, '2026-03');
    $asset = FixedAsset::query()->findOrFail($id);

    expect($result['count'])->toBe(3)->and($result['total'])->toEqual(540)->and($result['vouchers'])->toBe(3)
        ->and($asset->accumulated_depreciation)->toBe(540.0)
        ->and($asset->bookValue())->toBe(11460.0)
        ->and($asset->depreciated_through->toDateString())->toBe('2026-03-31');
});

test('declining balance takes a factor of the straight-line rate off the book value each month', function () {
    $scope = faScope();
    $id = fdAsset($scope, faCategory($scope, ['method' => 'declining_balance', 'useful_life_months' => 60, 'rate' => 2]));

    fdRun($scope, '2026-02');

    $rows = FixedAssetDepreciation::query()->where('fixed_asset_id', $id)->orderBy('period')->get();

    // 12,000 x (2 / 5 years) / 12 = 400, then 11,600 x 0.4 / 12 = 386.67.
    expect($rows->pluck('amount')->all())->toBe([400.0, 386.67])
        ->and($rows->pluck('closing_book_value')->all())->toBe([11600.0, 11213.33]);
});

test('WDV takes the yearly rate off the written-down value each month', function () {
    $scope = faScope();
    $id = fdAsset($scope, faCategory($scope, ['method' => 'wdv', 'useful_life_months' => null, 'rate' => 24]));

    fdRun($scope, '2026-02');

    expect(FixedAssetDepreciation::query()->where('fixed_asset_id', $id)->orderBy('period')->pluck('amount')->all())->toBe([240.0, 235.2]);
});

test('running again never doubles up and a later run only adds the new months', function () {
    $scope = faScope();
    $id = fdAsset($scope, faCategory($scope));

    fdRun($scope, '2026-02');
    $again = fdRun($scope, '2026-02');
    $later = fdRun($scope, '2026-04');

    expect($again['count'])->toBe(0)->and($again['vouchers'])->toBe(0)
        ->and($later['months'])->toBe(['2026-03', '2026-04'])
        ->and(FixedAssetDepreciation::query()->where('fixed_asset_id', $id)->count())->toBe(4)
        ->and(FixedAsset::query()->findOrFail($id)->accumulated_depreciation)->toBe(720.0);
});

test('depreciation stops at the salvage value and never goes below it', function () {
    $scope = faScope();
    $id = fdAsset($scope, faCategory($scope, ['useful_life_months' => 5, 'salvage_percent' => 10]), ['cost' => 1000, 'acquired_on' => '2026-01-01']);

    fdRun($scope, '2026-09');

    $asset = FixedAsset::query()->findOrFail($id);

    // 900 over 5 months = 180 a month: five months, then nothing.
    expect(FixedAssetDepreciation::query()->where('fixed_asset_id', $id)->count())->toBe(5)
        ->and($asset->bookValue())->toBe(100.0)
        ->and(fdRun($scope, '2026-10')['count'])->toBe(0);

    $carried = fdAsset($scope, faCategory($scope, ['name' => 'Tools', 'useful_life_months' => 5]), ['name' => 'Old tool', 'cost' => 1000, 'salvage_value' => 100, 'accumulated_depreciation' => 800, 'depreciated_through' => '2026-05-31', 'acquired_on' => '2025-01-01']);

    fdRun($scope, '2026-09');

    // Only 100 is left above the salvage value although a month is 180.
    expect(FixedAssetDepreciation::query()->where('fixed_asset_id', $carried)->pluck('amount')->all())->toBe([100.0]);
});

test('an asset carried over with depreciation to a date carries on from the next month', function () {
    $scope = faScope();
    $id = fdAsset($scope, faCategory($scope), ['acquired_on' => '2025-01-01', 'accumulated_depreciation' => 2000, 'depreciated_through' => '2025-12-31']);

    $result = fdRun($scope, '2026-02');

    expect($result['months'])->toBe(['2026-01', '2026-02'])
        ->and(FixedAsset::query()->findOrFail($id)->accumulated_depreciation)->toBe(2360.0)
        ->and(FixedAsset::query()->findOrFail($id)->bookValue())->toBe(9640.0);
});

test('assets still in progress, disposed, not yet in service, or outside the filter are left alone', function () {
    $scope = faScope();
    $categoryId = faCategory($scope);
    $other = faCategory($scope, ['name' => 'Vehicles']);
    $kept = fdAsset($scope, $categoryId);
    $disposed = fdAsset($scope, $categoryId, ['name' => 'Gone']);
    $future = fdAsset($scope, $categoryId, ['name' => 'Later', 'acquired_on' => '2026-06-01']);
    $vehicle = fdAsset($scope, $other, ['name' => 'Van']);
    FixedAsset::query()->whereKey($disposed)->update(['status' => 'disposed']);
    FixedAsset::query()->create(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'asset_category_id' => $categoryId, 'code' => 'FA-99999', 'name' => 'Shed', 'status' => 'cwip', 'source' => 'cwip', 'acquired_on' => '2026-01-01', 'cost' => 500, 'method' => 'straight_line', 'useful_life_months' => 12]);

    $result = fdRun($scope, '2026-03', ['category_id' => $categoryId]);
    $touched = FixedAssetDepreciation::query()->pluck('fixed_asset_id')->unique()->sort()->values()->all();

    expect($touched)->toBe([$kept])
        ->and($result['count'])->toBe(3)
        ->and(FixedAssetDepreciation::query()->where('fixed_asset_id', $future)->exists())->toBeFalse()
        ->and(FixedAssetDepreciation::query()->where('fixed_asset_id', $vehicle)->exists())->toBeFalse();

    fdRun($scope, '2026-07');
    expect(FixedAssetDepreciation::query()->where('fixed_asset_id', $future)->pluck('period')->all())->toBe(['2026-06', '2026-07']);
});

test('a month is one voucher per branch and category: debit the expense, credit the accumulated depreciation, balanced', function () {
    $scope = faScope();
    $categoryId = faCategory($scope);
    fdAsset($scope, $categoryId);
    fdAsset($scope, $categoryId, ['name' => 'Second line', 'cost' => 6000]);

    $result = fdRun($scope, '2026-01');

    expect($result['vouchers'])->toBe(1)->and($result['count'])->toBe(2)->and($result['total'])->toEqual(270);

    $voucher = TAccount::query()->latest('id')->firstOrFail();
    $lines = DB::table('t_account_details')->where('t_account_id', $voucher->id)->orderBy('id')->get();

    expect($voucher->voucher_no)->toStartWith('JV-')
        ->and($voucher->voucher_date->toDateString())->toBe('2026-01-31')
        ->and($lines->pluck('account_code')->all())->toBe(['401-00010', '201-00011'])
        ->and((float) $lines[0]->debit)->toBe(270.0)
        ->and((float) $lines[1]->credit)->toBe(270.0);
});

test('the voucher follows the journal approval setting and the books stay balanced once approved', function () {
    $scope = faScope();
    $categoryId = faCategory($scope);
    fdAsset($scope, $categoryId);
    fdRun($scope, '2026-01');

    expect(TAccount::query()->latest('id')->firstOrFail()->status)->toBe('pending');

    jeaSetting($scope['company_id'], 'journal_entry', true);
    fdRun($scope, '2026-03');

    expect(TAccount::query()->latest('id')->firstOrFail()->status)->toBe('approved');

    // The asset was bought on the 1st: the balance sheet must still balance with the contra account in it.
    $this->postJson('/api/fixed-assets/acquire', ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'asset_category_id' => $categoryId, 'mode' => 'asset', 'name' => 'Bought', 'acquired_on' => '2026-04-01', 'cost' => 1000, 'offset_coa_id' => $scope['accounts']['bank']])->assertSuccessful();
    fdRun($scope, '2026-04');

    $sheet = prpGet('balance-sheet', ['show_record' => 100, 'company_id' => $scope['company_id'], 'end_date' => '2026-04-30']);
    $profit = prpGet('profit-loss', ['show_record' => 100, 'company_id' => $scope['company_id'], 'start_date' => '2026-01-01', 'end_date' => '2026-04-30']);

    expect($sheet->json('summary.is_balanced'))->toBeTrue()
        ->and($sheet->json('summary.difference'))->toEqual(0)
        ->and($profit->json('summary.net_profit'))->toBeLessThan(0);
});

test('the history of an asset lists its depreciation and the asset can no longer be deleted', function () {
    $scope = faScope();
    $id = fdAsset($scope, faCategory($scope));

    expect(FixedAsset::query()->findOrFail($id)->isDeletable())->toBeTrue();

    fdRun($scope, '2026-02');

    $events = $this->getJson("/api/fixed-assets/{$id}/history")->assertSuccessful()->json('data.events');

    expect(collect($events)->pluck('type')->all())->toBe(['depreciation', 'depreciation'])
        ->and(FixedAsset::query()->findOrFail($id)->isDeletable())->toBeFalse();

    $this->deleteJson("/api/fixed-assets/{$id}")->assertUnprocessable();

    $list = $this->getJson('/api/depreciation?company_id='.$scope['company_id'].'&period=2026-02')->assertSuccessful()->json('data.data');
    expect($list)->toHaveCount(1)->and($list[0]['amount'])->toEqual(180)->and($list[0]['code'])->toBe('FA-00001');
});

test('the period is validated and another company\'s branch is refused', function () {
    $scope = faScope();
    $other = faScope('FB');
    fdAsset($scope, faCategory($scope));

    $this->postJson('/api/depreciation/run', ['company_id' => $scope['company_id'], 'period' => '2026-13'])->assertUnprocessable()->assertJsonValidationErrors(['period']);
    $this->postJson('/api/depreciation/run', ['company_id' => $scope['company_id'], 'period' => '2099-01'])->assertUnprocessable()->assertJsonValidationErrors(['period']);
    $this->postJson('/api/depreciation/run', ['company_id' => $scope['company_id'], 'period' => '2026-02', 'branch_id' => $other['branch_id']])->assertUnprocessable()->assertJsonValidationErrors(['branch_id']);

    expect(FixedAssetDepreciation::query()->count())->toBe(0);
});

test('a user needs the page permission to look and the run permission to book', function () {
    $scope = faScope();
    fdAsset($scope, faCategory($scope));

    $role = Role::query()->create(['name' => 'assetclerk', 'company_id' => $scope['company_id'], 'is_active' => true]);
    Sanctum::actingAs(createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']]));

    $this->getJson('/api/depreciation/preview?period=2026-02')->assertForbidden();

    grantMenuPermission($role->id, '/depreciation');
    $this->getJson('/api/depreciation/preview?period=2026-02')->assertSuccessful()->assertJsonCount(2, 'data.rows');
    $this->postJson('/api/depreciation/run', ['period' => '2026-02'])->assertForbidden();

    grantMenuPermission($role->id, '/depreciation/run');
    $this->postJson('/api/depreciation/run', ['period' => '2026-02'])->assertSuccessful()->assertJsonPath('data.count', 2);
});

test('the artisan command books every company as its company admin and the monthly schedule is registered', function () {
    $scope = faScope();
    $id = fdAsset($scope, faCategory($scope));

    $role = Role::query()->where('name', 'companyadmin')->whereNull('company_id')->first()
        ?? Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true]);
    $admin = createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']]);

    $this->artisan('assets:run-depreciation', ['--period' => '2026-02'])->assertSuccessful();

    expect(FixedAssetDepreciation::query()->where('fixed_asset_id', $id)->count())->toBe(2)
        ->and((int) FixedAssetDepreciation::query()->first()->created_by)->toBe($admin->id);

    $this->artisan('assets:run-depreciation', ['--period' => 'soon'])->assertFailed();

    Artisan::call('schedule:list');
    expect(Artisan::output())->toContain('assets:run-depreciation');
});

test('the depreciation menu migration adds the page and its run permission next to Fixed Assets and grants them to companyadmin', function () {
    DB::table('permissions')->delete();
    DB::table('menus')->delete();
    $group = DB::table('menus')->insertGetId([
        'parent_id' => null, 'name' => 'Accounts', 'icon' => '', 'route_name' => 'accgroup', 'route_path' => '', 'menu_color' => '#000', 'sort_order' => 1,
        'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 0, 'type' => 2, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('menus')->insert([
        'parent_id' => $group, 'name' => 'Fixed Assets', 'icon' => '', 'route_name' => 'fixedasset', 'route_path' => '/fixedasset', 'menu_color' => '#000', 'sort_order' => 1,
        'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $roleId = DB::table('roles')->insertGetId(['name' => 'companyadmin', 'company_id' => null, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    (require database_path('migrations/2026_10_13_100100_add_depreciation_menu.php'))->up();

    expect(DB::table('menus')->where('route_path', 'like', '/depreciation%')->orderBy('route_path')->pluck('route_path')->all())->toBe(['/depreciation', '/depreciation/run'])
        ->and((int) DB::table('menus')->where('route_path', '/depreciation')->value('parent_id'))->toBe($group)
        ->and(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(2);
});
