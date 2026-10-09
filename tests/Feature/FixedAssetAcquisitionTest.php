<?php

use App\Models\AssetEvent;
use App\Models\FixedAsset;
use App\Models\Role;
use App\Models\TAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
});

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $overrides
 */
function faAcquirePayload(array $scope, int $categoryId, array $overrides = []): array
{
    return array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'asset_category_id' => $categoryId,
        'mode' => 'asset',
        'name' => 'Packing line',
        'acquired_on' => '2026-01-15',
        'cost' => 12000,
        'offset_coa_id' => $scope['accounts']['bank'],
        'reference' => 'INV-77',
    ], $overrides);
}

/**
 * @return array{debit: float, credit: float}
 */
function faVoucherTotals(int $voucherId): array
{
    $totals = DB::table('t_account_details')->where('t_account_id', $voucherId)->selectRaw('sum(debit) as debit, sum(credit) as credit')->first();

    return ['debit' => (float) $totals->debit, 'credit' => (float) $totals->credit];
}

test('buying an asset registers it and posts a balanced journal voucher, debit the asset account and credit the paying account', function () {
    $scope = faScope();
    $categoryId = faCategory($scope);

    $asset = $this->postJson('/api/fixed-assets/acquire', faAcquirePayload($scope, $categoryId))->assertSuccessful()->json('data');

    expect($asset['status'])->toBe('active')
        ->and($asset['source'])->toBe('purchase')
        ->and($asset['cost'])->toEqual(12000)
        ->and($asset['salvage_value'])->toEqual(1200)
        ->and($asset['in_service_on'])->toBe('2026-01-15')
        ->and($asset['accumulated_depreciation'])->toEqual(0);

    $event = AssetEvent::query()->where('fixed_asset_id', $asset['id'])->firstOrFail();
    $voucher = TAccount::query()->findOrFail($event->t_account_id);
    $lines = DB::table('t_account_details')->where('t_account_id', $voucher->id)->orderBy('id')->get();

    expect($event->type)->toBe('acquisition')
        ->and($event->status)->toBe('posted')
        ->and($voucher->voucher_no)->toStartWith('JV-')
        ->and($voucher->transaction_id)->toBeNull()
        ->and($voucher->ref_no)->toBe('INV-77')
        ->and($lines->pluck('account_code')->all())->toBe(['201-00010', '202-00010'])
        ->and((float) $lines[0]->debit)->toBe(12000.0)
        ->and((float) $lines[1]->credit)->toBe(12000.0)
        ->and(faVoucherTotals($voucher->id))->toBe(['debit' => 12000.0, 'credit' => 12000.0]);
});

test('the voucher follows the company journal approval setting: pending without it, approved with it', function () {
    $scope = faScope();
    $categoryId = faCategory($scope);

    $first = $this->postJson('/api/fixed-assets/acquire', faAcquirePayload($scope, $categoryId))->assertSuccessful()->json('data.id');
    $pending = TAccount::query()->findOrFail(AssetEvent::query()->where('fixed_asset_id', $first)->value('t_account_id'));

    expect($pending->status)->toBe('pending');

    jeaSetting($scope['company_id'], 'journal_entry', true);

    $second = $this->postJson('/api/fixed-assets/acquire', faAcquirePayload($scope, $categoryId, ['name' => 'Forklift']))->assertSuccessful()->json('data.id');
    $approved = TAccount::query()->findOrFail(AssetEvent::query()->where('fixed_asset_id', $second)->value('t_account_id'));

    expect($approved->status)->toBe('approved');

    // The pending voucher is the ordinary journal approval's to approve.
    $this->postJson("/api/journal-entry-approvals/{$pending->id}/approve")->assertSuccessful();
    expect(TAccount::query()->findOrFail($pending->id)->status)->toBe('approved');
});

test('construction in progress collects costs on the CWIP account and is capitalised once', function () {
    $scope = faScope();
    $categoryId = faCategory($scope);
    jeaSetting($scope['company_id'], 'journal_entry', true);

    $asset = $this->postJson('/api/fixed-assets/acquire', faAcquirePayload($scope, $categoryId, ['mode' => 'cwip', 'name' => 'New warehouse', 'cost' => 5000]))->assertSuccessful()->json('data');

    expect($asset['status'])->toBe('cwip')
        ->and($asset['in_service_on'])->toBeNull()
        ->and($asset['salvage_value'])->toEqual(0);

    $this->postJson("/api/fixed-assets/{$asset['id']}/cwip-cost", ['date' => '2026-02-10', 'amount' => 2500, 'offset_coa_id' => $scope['accounts']['bank'], 'description' => 'Roofing'])
        ->assertSuccessful()->assertJsonPath('data.cost', 7500);

    $cwipLines = DB::table('t_account_details')->where('account_code', '201-00012')->get();
    expect($cwipLines)->toHaveCount(2)
        ->and((float) $cwipLines->sum('debit'))->toBe(7500.0)
        ->and((float) DB::table('t_account_details')->where('account_code', '201-00010')->sum('debit'))->toBe(0.0);

    $this->postJson("/api/fixed-assets/{$asset['id']}/capitalise", ['in_service_on' => '2026-03-01'])->assertSuccessful()
        ->assertJsonPath('data.status', 'active')->assertJsonPath('data.in_service_on', '2026-03-01')->assertJsonPath('data.salvage_value', 750);

    expect((float) DB::table('t_account_details')->where('account_code', '201-00012')->sum('credit'))->toBe(7500.0)
        ->and((float) DB::table('t_account_details')->where('account_code', '201-00010')->sum('debit'))->toBe(7500.0);

    $this->postJson("/api/fixed-assets/{$asset['id']}/capitalise", ['in_service_on' => '2026-03-02'])->assertUnprocessable()->assertJsonValidationErrors(['asset']);
    $this->postJson("/api/fixed-assets/{$asset['id']}/cwip-cost", ['date' => '2026-03-05', 'amount' => 10, 'offset_coa_id' => $scope['accounts']['bank'], 'description' => 'Late'])
        ->assertUnprocessable()->assertJsonValidationErrors(['asset']);

    $history = $this->getJson("/api/fixed-assets/{$asset['id']}/history")->assertSuccessful()->json('data.events');
    expect(collect($history)->pluck('type')->all())->toBe(['acquisition', 'cwip_cost', 'capitalisation']);
});

test('every voucher the engine posts balances and the ledger stays balanced', function () {
    $scope = faScope();
    $categoryId = faCategory($scope);
    jeaSetting($scope['company_id'], 'journal_entry', true);

    $this->postJson('/api/fixed-assets/acquire', faAcquirePayload($scope, $categoryId))->assertSuccessful();
    $cwip = $this->postJson('/api/fixed-assets/acquire', faAcquirePayload($scope, $categoryId, ['mode' => 'cwip', 'cost' => 900]))->assertSuccessful()->json('data.id');
    $this->postJson("/api/fixed-assets/{$cwip}/cwip-cost", ['date' => '2026-02-01', 'amount' => 100, 'offset_coa_id' => $scope['accounts']['bank'], 'description' => 'x'])->assertSuccessful();
    $this->postJson("/api/fixed-assets/{$cwip}/capitalise", ['in_service_on' => '2026-02-15'])->assertSuccessful();

    $totals = DB::table('t_account_details')->selectRaw('sum(debit) as debit, sum(credit) as credit')->first();

    expect(round((float) $totals->debit, 2))->toBe(round((float) $totals->credit, 2))
        ->and((float) $totals->debit)->toBeGreaterThan(0);
});

test('acquisition input is validated', function () {
    $scope = faScope();
    $other = faScope('FB');
    $categoryId = faCategory($scope);
    $noCwip = faCategory($scope, ['name' => 'Vehicles', 'cwip_coa_id' => null]);

    $this->postJson('/api/fixed-assets/acquire', faAcquirePayload($scope, $categoryId, ['offset_coa_id' => $other['accounts']['bank']]))->assertUnprocessable()->assertJsonValidationErrors(['offset_coa_id']);
    $this->postJson('/api/fixed-assets/acquire', faAcquirePayload($scope, $categoryId, ['mode' => 'barter']))->assertUnprocessable()->assertJsonValidationErrors(['mode']);
    $this->postJson('/api/fixed-assets/acquire', faAcquirePayload($scope, $noCwip, ['mode' => 'cwip']))->assertUnprocessable()->assertJsonValidationErrors(['asset_category_id']);
    $this->postJson('/api/fixed-assets/acquire', faAcquirePayload($scope, $categoryId, ['salvage_value' => 99999]))->assertUnprocessable()->assertJsonValidationErrors(['salvage_value']);

    expect(FixedAsset::query()->count())->toBe(0)->and(TAccount::query()->count())->toBe(0);
});

test('a user without the acquire permission is turned away and costs and capitalising are permissioned too', function () {
    $scope = faScope();
    $categoryId = faCategory($scope);
    $cwip = $this->postJson('/api/fixed-assets/acquire', faAcquirePayload($scope, $categoryId, ['mode' => 'cwip']))->assertSuccessful()->json('data.id');

    $role = Role::query()->create(['name' => 'assetclerk', 'company_id' => $scope['company_id'], 'is_active' => true]);
    Sanctum::actingAs(createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']]));

    $this->postJson('/api/fixed-assets/acquire', faAcquirePayload($scope, $categoryId))->assertForbidden();
    $this->postJson("/api/fixed-assets/{$cwip}/cwip-cost", ['date' => '2026-02-01', 'amount' => 1, 'offset_coa_id' => $scope['accounts']['bank'], 'description' => 'x'])->assertForbidden();
    $this->postJson("/api/fixed-assets/{$cwip}/capitalise", ['in_service_on' => '2026-02-01'])->assertForbidden();
});

test('the acquisition menu migration adds the three hidden permission rows under Fixed Assets and grants them to companyadmin', function () {
    DB::table('permissions')->delete();
    DB::table('menus')->delete();
    $page = DB::table('menus')->insertGetId([
        'parent_id' => null, 'name' => 'Fixed Assets', 'icon' => '', 'route_name' => 'fixedasset', 'route_path' => '/fixedasset', 'menu_color' => '#000', 'sort_order' => 1,
        'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $roleId = DB::table('roles')->insertGetId(['name' => 'companyadmin', 'company_id' => null, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    (require database_path('migrations/2026_10_12_100100_add_fixed_asset_acquisition_menus.php'))->up();

    expect(DB::table('menus')->where('parent_id', $page)->orderBy('route_path')->pluck('route_path')->all())->toBe(['/fixedasset/acquire', '/fixedasset/capitalise', '/fixedasset/cwip'])
        ->and(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(3);
});
