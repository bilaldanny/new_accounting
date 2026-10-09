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
 * An asset in service since 1 Jan 2026 with 3 months depreciated (180 a month), in the scope's first branch, plus a
 * second branch of the same company.
 *
 * @return array{scope: array<string, mixed>, id: int, second: int, categoryId: int}
 */
function ftAsset(bool $approved = true): array
{
    $scope = faScope();
    $second = trpBranch($scope['company_id'], 'Second Branch');
    $categoryId = faCategory($scope);

    if ($approved) {
        jeaSetting($scope['company_id'], 'journal_entry', true);
    }

    $id = fdAsset($scope, $categoryId);
    test()->postJson('/api/depreciation/run', ['company_id' => $scope['company_id'], 'period' => '2026-03'])->assertSuccessful();

    return ['scope' => $scope, 'id' => $id, 'second' => $second, 'categoryId' => $categoryId];
}

/**
 * Debit less credit of an account in a branch's vouchers.
 */
function ftNet(int $branchId, string $code): float
{
    return round((float) DB::table('t_account_details as d')->join('t_accounts as a', 'a.id', '=', 'd.t_account_id')
        ->where('a.branch_id', $branchId)->where('d.account_code', $code)->selectRaw('coalesce(sum(d.debit), 0) - coalesce(sum(d.credit), 0) as net')->value('net'), 2);
}

test('a transfer moves the asset to the other branch and posts a balanced entry in each branch through the transfer account', function () {
    ['scope' => $scope, 'id' => $id, 'second' => $second] = ftAsset();

    // Nothing but depreciation has been posted so far: accumulated 540 credited in the first branch.
    $this->postJson("/api/fixed-assets/{$id}/transfer", ['to_branch_id' => $second, 'date' => '2026-04-01', 'note' => 'Moved to the new shop'])->assertSuccessful()
        ->assertJsonPath('data.branch_id', $second);

    $event = AssetEvent::query()->where('type', 'transfer')->firstOrFail();
    $payload = $event->payload;
    $out = TAccount::query()->findOrFail($payload['out_voucher_id']);
    $in = TAccount::query()->findOrFail($payload['in_voucher_id']);

    expect($event->status)->toBe('posted')
        ->and($event->amount)->toBe(11460.0)
        ->and((int) $out->branch_id)->toBe($scope['branch_id'])
        ->and((int) $in->branch_id)->toBe($second)
        ->and($out->status)->toBe('approved')
        ->and(DB::table('t_account_details')->where('t_account_id', $out->id)->sum('debit'))->toEqual(DB::table('t_account_details')->where('t_account_id', $out->id)->sum('credit'))
        ->and(DB::table('t_account_details')->where('t_account_id', $in->id)->sum('debit'))->toEqual(DB::table('t_account_details')->where('t_account_id', $in->id)->sum('credit'));

    // The cost (carried in as an opening balance) leaves the first branch and arrives in the second; accumulated depreciation
    // follows; the transfer account nets to nothing across the company.
    expect(ftNet($second, '201-00010'))->toBe(12000.0)
        ->and(ftNet($second, '201-00011'))->toBe(-540.0)
        ->and(ftNet($scope['branch_id'], '201-00010'))->toBe(-12000.0)
        ->and(ftNet($scope['branch_id'], '201-00011'))->toBe(0.0)
        ->and(round(ftNet($scope['branch_id'], '202-00099') + ftNet($second, '202-00099'), 2))->toBe(0.0);

    $asset = FixedAsset::query()->findOrFail($id);
    expect((int) $asset->branch_id)->toBe($second)->and($asset->code)->toBe('FA-00001')->and($asset->bookValue())->toBe(11460.0);
});

test('depreciation after a transfer is booked in the new branch', function () {
    ['scope' => $scope, 'id' => $id, 'second' => $second] = ftAsset();
    $this->postJson("/api/fixed-assets/{$id}/transfer", ['to_branch_id' => $second, 'date' => '2026-04-01'])->assertSuccessful();

    $this->postJson('/api/depreciation/run', ['company_id' => $scope['company_id'], 'period' => '2026-04'])->assertSuccessful();

    $voucher = TAccount::query()->where('ref_no', 'DEP-2026-04')->firstOrFail();
    expect((int) $voucher->branch_id)->toBe($second);
});

test('the vouchers follow the journal approval setting', function () {
    ['id' => $id, 'second' => $second] = ftAsset(approved: false);

    $this->postJson("/api/fixed-assets/{$id}/transfer", ['to_branch_id' => $second, 'date' => '2026-04-01'])->assertSuccessful();

    $payload = AssetEvent::query()->where('type', 'transfer')->firstOrFail()->payload;
    expect(TAccount::query()->findOrFail($payload['out_voucher_id'])->status)->toBe('pending')
        ->and(TAccount::query()->findOrFail($payload['in_voucher_id'])->status)->toBe('pending');
});

test('a transfer is validated', function () {
    ['scope' => $scope, 'id' => $id, 'second' => $second, 'categoryId' => $categoryId] = ftAsset();
    $other = faScope('FB');
    $noTransfer = faCategory($scope, ['name' => 'Vehicles', 'transfer_coa_id' => null]);
    $plain = fdAsset($scope, $noTransfer, ['name' => 'Van']);
    $cwip = $this->postJson('/api/fixed-assets/acquire', faAcquirePayload($scope, $categoryId, ['mode' => 'cwip']))->assertSuccessful()->json('data.id');

    $this->postJson("/api/fixed-assets/{$id}/transfer", ['to_branch_id' => $scope['branch_id'], 'date' => '2026-04-01'])->assertUnprocessable()->assertJsonValidationErrors(['to_branch_id']);
    $this->postJson("/api/fixed-assets/{$id}/transfer", ['to_branch_id' => $other['branch_id'], 'date' => '2026-04-01'])->assertUnprocessable()->assertJsonValidationErrors(['to_branch_id']);
    $this->postJson("/api/fixed-assets/{$id}/transfer", ['to_branch_id' => $second, 'date' => '2025-01-01'])->assertUnprocessable()->assertJsonValidationErrors(['date']);
    $this->postJson("/api/fixed-assets/{$plain}/transfer", ['to_branch_id' => $second, 'date' => '2026-04-01'])->assertUnprocessable()->assertJsonValidationErrors(['asset']);
    $this->postJson("/api/fixed-assets/{$cwip}/transfer", ['to_branch_id' => $second, 'date' => '2026-04-01'])->assertUnprocessable()->assertJsonValidationErrors(['asset']);

    expect(AssetEvent::query()->where('type', 'transfer')->count())->toBe(0);
});

test('an asset with nothing depreciated can be transferred and the history lists the transfer', function () {
    $scope = faScope();
    $second = trpBranch($scope['company_id'], 'Second Branch');
    $id = fdAsset($scope, faCategory($scope));

    $this->postJson("/api/fixed-assets/{$id}/transfer", ['to_branch_id' => $second, 'date' => '2026-02-01'])->assertSuccessful();

    $voucher = TAccount::query()->where('ref_no', 'FA-00001')->orderBy('id')->get();
    $events = $this->getJson("/api/fixed-assets/{$id}/history")->assertSuccessful()->json('data.events');

    expect($voucher)->toHaveCount(2)
        ->and(DB::table('t_account_details')->where('t_account_id', $voucher[0]->id)->count())->toBe(2)
        ->and(collect($events)->pluck('type')->all())->toBe(['transfer']);
});

test('a user needs the transfer permission', function () {
    ['scope' => $scope, 'id' => $id, 'second' => $second] = ftAsset();

    $role = Role::query()->create(['name' => 'assetclerk', 'company_id' => $scope['company_id'], 'is_active' => true]);
    Sanctum::actingAs(createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']]));
    grantMenuPermission($role->id, '/fixedasset');

    $this->postJson("/api/fixed-assets/{$id}/transfer", ['to_branch_id' => $second, 'date' => '2026-04-01'])->assertForbidden();
});

test('the transfer menu migration adds the hidden permission row under Fixed Assets and grants it to companyadmin', function () {
    DB::table('permissions')->delete();
    DB::table('menus')->delete();
    $page = DB::table('menus')->insertGetId([
        'parent_id' => null, 'name' => 'Fixed Assets', 'icon' => '', 'route_name' => 'fixedasset', 'route_path' => '/fixedasset', 'menu_color' => '#000', 'sort_order' => 1,
        'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $roleId = DB::table('roles')->insertGetId(['name' => 'companyadmin', 'company_id' => null, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    (require database_path('migrations/2026_10_14_100100_add_fixed_asset_transfer_menu.php'))->up();

    expect(DB::table('menus')->where('parent_id', $page)->pluck('route_path')->all())->toBe(['/fixedasset/transfer'])
        ->and(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(1);
});
