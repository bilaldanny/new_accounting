<?php

use App\Models\AssetEvent;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
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
 * An asset in service since 1 Jan 2026, cost 12,000, 540 depreciated up to March (book value 11,460).
 *
 * @return array{scope: array<string, mixed>, id: int}
 */
function flAsset(): array
{
    $scope = faScope();
    $id = fdAsset($scope, faCategory($scope));
    test()->postJson('/api/depreciation/run', ['company_id' => $scope['company_id'], 'period' => '2026-03'])->assertSuccessful();

    return ['scope' => $scope, 'id' => $id];
}

/**
 * @return array<string, array{debit: float, credit: float}>
 */
function flLines(int $voucherId): array
{
    return DB::table('t_account_details')->where('t_account_id', $voucherId)->get()
        ->mapWithKeys(fn (object $line): array => [$line->account_code => ['debit' => (float) $line->debit, 'credit' => (float) $line->credit]])->all();
}

function flApprove(int $eventId): void
{
    test()->postJson("/api/asset-approvals/{$eventId}/approve")->assertSuccessful();
}

test('a revaluation only asks: nothing is posted and the asset is unchanged until it is approved', function () {
    ['scope' => $scope, 'id' => $id] = flAsset();
    $vouchers = TAccount::query()->count();

    $event = $this->postJson("/api/fixed-assets/{$id}/revalue", ['date' => '2026-04-10', 'new_value' => 13000, 'reason' => 'Market valuation'])->assertSuccessful()
        ->assertJsonPath('data.status', 'pending')->json('data');

    expect(TAccount::query()->count())->toBe($vouchers)
        ->and(FixedAsset::query()->findOrFail($id)->cost)->toBe(12000.0)
        ->and($event['payload']['new_value'])->toEqual(13000);

    $pending = $this->getJson('/api/asset-approvals')->assertSuccessful()->json('data.data');
    expect($pending)->toHaveCount(1)->and($pending[0]['type'])->toBe('Revaluation')->and($pending[0]['asset'])->toBe('FA-00001 Packing line')
        ->and($pending[0]['detail'])->toBe('Book value 11460.00 to 13000.00');
});

test('approving a revaluation raises the cost and credits the revaluation surplus, with an approved voucher whatever the journal setting says', function () {
    ['scope' => $scope, 'id' => $id] = flAsset();
    $eventId = $this->postJson("/api/fixed-assets/{$id}/revalue", ['date' => '2026-04-10', 'new_value' => 13000, 'reason' => 'Market valuation'])->json('data.id');

    flApprove($eventId);

    $event = AssetEvent::query()->findOrFail($eventId);
    $asset = FixedAsset::query()->findOrFail($id);
    $voucher = TAccount::query()->findOrFail($event->t_account_id);

    expect($event->status)->toBe('approved')->and($event->amount)->toBe(1540.0)->and($event->approved_by)->not->toBeNull()
        ->and($asset->cost)->toBe(13540.0)->and($asset->bookValue())->toBe(13000.0)
        ->and($voucher->status)->toBe('approved')
        ->and(flLines($voucher->id))->toBe(['201-00010' => ['debit' => 1540.0, 'credit' => 0.0], '101-00010' => ['debit' => 0.0, 'credit' => 1540.0]]);

    $this->postJson("/api/asset-approvals/{$eventId}/approve")->assertUnprocessable()->assertJsonValidationErrors(['status']);
    expect($this->getJson('/api/asset-approvals')->json('data.data'))->toBeEmpty();
});

test('a revaluation cannot lower the value, and an asset has one request waiting at a time', function () {
    ['id' => $id] = flAsset();

    $this->postJson("/api/fixed-assets/{$id}/revalue", ['date' => '2026-04-10', 'new_value' => 11460, 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['new_value']);
    $this->postJson("/api/fixed-assets/{$id}/revalue", ['date' => '2026-04-10', 'new_value' => 9000, 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['new_value']);
    $this->postJson("/api/fixed-assets/{$id}/revalue", ['date' => '2025-01-01', 'new_value' => 15000, 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['date']);

    $this->postJson("/api/fixed-assets/{$id}/revalue", ['date' => '2026-04-10', 'new_value' => 13000, 'reason' => 'x'])->assertSuccessful();
    $this->postJson("/api/fixed-assets/{$id}/impair", ['date' => '2026-04-10', 'amount' => 100, 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['asset']);
});

test('an approved impairment writes the asset down through accumulated depreciation', function () {
    ['id' => $id] = flAsset();
    $eventId = $this->postJson("/api/fixed-assets/{$id}/impair", ['date' => '2026-04-10', 'amount' => 1000, 'reason' => 'Water damage'])->assertSuccessful()->json('data.id');

    expect(FixedAsset::query()->findOrFail($id)->accumulated_depreciation)->toBe(540.0);

    flApprove($eventId);

    $asset = FixedAsset::query()->findOrFail($id);
    $voucher = TAccount::query()->findOrFail(AssetEvent::query()->findOrFail($eventId)->t_account_id);

    expect($asset->accumulated_depreciation)->toBe(1540.0)->and($asset->bookValue())->toBe(10460.0)
        ->and(flLines($voucher->id))->toBe(['401-00011' => ['debit' => 1000.0, 'credit' => 0.0], '201-00011' => ['debit' => 0.0, 'credit' => 1000.0]]);
});

test('an impairment cannot exceed the book value and can take the salvage value down with it', function () {
    ['scope' => $scope, 'id' => $id] = flAsset();

    $this->postJson("/api/fixed-assets/{$id}/impair", ['date' => '2026-04-10', 'amount' => 11461, 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['amount']);

    $eventId = $this->postJson("/api/fixed-assets/{$id}/impair", ['date' => '2026-04-10', 'amount' => 10400, 'reason' => 'Fire'])->json('data.id');
    flApprove($eventId);

    $asset = FixedAsset::query()->findOrFail($id);
    expect($asset->bookValue())->toBe(1060.0)->and($asset->salvage_value)->toBe(1060.0);

    // At salvage value nothing more depreciates.
    $this->postJson('/api/depreciation/run', ['company_id' => $scope['company_id'], 'period' => '2026-06'])->assertSuccessful();
    expect(FixedAssetDepreciation::query()->where('fixed_asset_id', $id)->count())->toBe(3);
});

test('a disposal at a gain, at a loss and as scrap each post a balanced voucher and retire the asset', function () {
    ['scope' => $scope, 'id' => $id] = flAsset();
    $category = faCategory($scope, ['name' => 'Other']);

    $cases = [
        'gain' => [12000, ['201-00010' => ['debit' => 0.0, 'credit' => 12000.0], '202-00010' => ['debit' => 12000.0, 'credit' => 0.0], '201-00011' => ['debit' => 540.0, 'credit' => 0.0], '401-00012' => ['debit' => 0.0, 'credit' => 540.0]]],
        'loss' => [10000, ['201-00010' => ['debit' => 0.0, 'credit' => 12000.0], '202-00010' => ['debit' => 10000.0, 'credit' => 0.0], '201-00011' => ['debit' => 540.0, 'credit' => 0.0], '401-00012' => ['debit' => 1460.0, 'credit' => 0.0]]],
        'scrap' => [0, ['201-00010' => ['debit' => 0.0, 'credit' => 12000.0], '201-00011' => ['debit' => 540.0, 'credit' => 0.0], '401-00012' => ['debit' => 11460.0, 'credit' => 0.0]]],
    ];

    foreach ($cases as $name => [$proceeds, $expected]) {
        $assetId = $name === 'gain' ? $id : fdAsset($scope, $category, ['name' => "Copy {$name}"]);

        if ($name !== 'gain') {
            test()->postJson('/api/depreciation/run', ['company_id' => $scope['company_id'], 'period' => '2026-03'])->assertSuccessful();
        }

        $eventId = $this->postJson("/api/fixed-assets/{$assetId}/dispose", ['date' => '2026-04-20', 'proceeds' => $proceeds, 'proceeds_coa_id' => $proceeds > 0 ? $scope['accounts']['bank'] : null, 'reason' => "Sold ({$name})"])
            ->assertSuccessful()->assertJsonPath('data.status', 'pending')->json('data.id');

        expect(FixedAsset::query()->findOrFail($assetId)->status)->toBe('active');

        flApprove($eventId);

        $voucher = TAccount::query()->findOrFail(AssetEvent::query()->findOrFail($eventId)->t_account_id);
        $asset = FixedAsset::query()->findOrFail($assetId);

        expect($asset->status)->toBe('disposed')
            ->and($asset->disposed_on->toDateString())->toBe('2026-04-20')
            ->and($voucher->status)->toBe('approved')
            ->and(flLines($voucher->id))->toBe($expected)
            ->and(DB::table('t_account_details')->where('t_account_id', $voucher->id)->sum('debit'))->toEqual(DB::table('t_account_details')->where('t_account_id', $voucher->id)->sum('credit'));
    }

    // A disposed asset is no longer depreciated and cannot be disposed of again.
    $this->postJson('/api/depreciation/run', ['company_id' => $scope['company_id'], 'period' => '2026-09'])->assertSuccessful();
    expect(FixedAssetDepreciation::query()->where('fixed_asset_id', $id)->count())->toBe(3);
    $this->postJson("/api/fixed-assets/{$id}/dispose", ['date' => '2026-05-01', 'proceeds' => 0, 'reason' => 'again'])->assertUnprocessable()->assertJsonValidationErrors(['asset']);
});

test('a disposal needs depreciation booked up to the month before and its proceeds account', function () {
    ['id' => $id] = flAsset();

    $this->postJson("/api/fixed-assets/{$id}/dispose", ['date' => '2026-07-15', 'proceeds' => 100, 'proceeds_coa_id' => null, 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['date']);
    $this->postJson("/api/fixed-assets/{$id}/dispose", ['date' => '2026-04-15', 'proceeds' => 100, 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['proceeds_coa_id']);
});

test('rejecting a request changes nothing, keeps the reason, and a new request can follow', function () {
    ['id' => $id] = flAsset();
    $vouchers = TAccount::query()->count();
    $eventId = $this->postJson("/api/fixed-assets/{$id}/impair", ['date' => '2026-04-10', 'amount' => 500, 'reason' => 'Dent'])->json('data.id');

    $this->postJson("/api/asset-approvals/{$eventId}/reject", ['reason' => 'Not material'])->assertSuccessful();

    $event = AssetEvent::query()->findOrFail($eventId);
    expect($event->status)->toBe('rejected')->and($event->rejected_reason)->toBe('Not material')->and($event->t_account_id)->toBeNull()
        ->and(TAccount::query()->count())->toBe($vouchers)
        ->and(FixedAsset::query()->findOrFail($id)->accumulated_depreciation)->toBe(540.0);

    $this->postJson("/api/asset-approvals/{$eventId}/approve")->assertUnprocessable()->assertJsonValidationErrors(['status']);
    $this->postJson("/api/fixed-assets/{$id}/impair", ['date' => '2026-04-10', 'amount' => 500, 'reason' => 'Dent again'])->assertSuccessful();
});

test('approving re-checks the asset: a request that no longer fits is refused', function () {
    ['id' => $id] = flAsset();
    $eventId = $this->postJson("/api/fixed-assets/{$id}/impair", ['date' => '2026-04-10', 'amount' => 11000, 'reason' => 'Fire'])->json('data.id');

    FixedAsset::query()->whereKey($id)->update(['accumulated_depreciation' => 3000]);

    $this->postJson("/api/asset-approvals/{$eventId}/approve")->assertUnprocessable()->assertJsonValidationErrors(['amount']);
    expect(AssetEvent::query()->findOrFail($eventId)->status)->toBe('pending');
});

test('nothing is auto-approved even when the company auto-approves journals', function () {
    ['scope' => $scope, 'id' => $id] = flAsset();
    jeaSetting($scope['company_id'], 'journal_entry', true);

    $this->postJson("/api/fixed-assets/{$id}/revalue", ['date' => '2026-04-10', 'new_value' => 13000, 'reason' => 'x'])->assertSuccessful()->assertJsonPath('data.status', 'pending');
    expect(FixedAsset::query()->findOrFail($id)->cost)->toBe(12000.0);
});

test('requesting, approving and rejecting are three separate permissions and another company cannot approve', function () {
    ['scope' => $scope, 'id' => $id] = flAsset();
    $other = faScope('FB');

    $role = Role::query()->create(['name' => 'assetclerk', 'company_id' => $scope['company_id'], 'is_active' => true]);
    Sanctum::actingAs(createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']]));
    grantMenuPermission($role->id, '/fixedasset');

    $this->postJson("/api/fixed-assets/{$id}/impair", ['date' => '2026-04-10', 'amount' => 100, 'reason' => 'x'])->assertForbidden();
    $this->postJson("/api/fixed-assets/{$id}/revalue", ['date' => '2026-04-10', 'new_value' => 13000, 'reason' => 'x'])->assertForbidden();
    $this->postJson("/api/fixed-assets/{$id}/dispose", ['date' => '2026-04-10', 'proceeds' => 0, 'reason' => 'x'])->assertForbidden();

    grantMenuPermission($role->id, '/fixedasset/impair');
    $eventId = $this->postJson("/api/fixed-assets/{$id}/impair", ['date' => '2026-04-10', 'amount' => 100, 'reason' => 'x'])->assertSuccessful()->json('data.id');

    $this->getJson('/api/asset-approvals')->assertForbidden();
    $this->postJson("/api/asset-approvals/{$eventId}/approve")->assertForbidden();

    grantMenuPermission($role->id, '/assetapproval');
    $this->postJson("/api/asset-approvals/{$eventId}/reject")->assertForbidden();
    $this->getJson('/api/asset-approvals')->assertSuccessful()->assertJsonCount(1, 'data.data');

    $outsider = Role::query()->create(['name' => 'outsider', 'company_id' => $other['company_id'], 'is_active' => true]);
    Sanctum::actingAs(createStaffUserForRole($outsider, ['company_id' => $other['company_id'], 'branch_id' => $other['branch_id']]));
    grantMenuPermission($outsider->id, '/assetapproval');

    $this->getJson('/api/asset-approvals')->assertSuccessful()->assertJsonCount(0, 'data.data');
    $this->postJson("/api/asset-approvals/{$eventId}/approve")->assertNotFound();

    Sanctum::actingAs(User::query()->findOrFail(1));
    expect(AssetEvent::query()->findOrFail($eventId)->status)->toBe('pending');
});

test('the lifecycle menu migration adds the hidden request rows and the Approval Center page and grants them to companyadmin', function () {
    DB::table('permissions')->delete();
    DB::table('menus')->delete();
    $accounts = DB::table('menus')->insertGetId([
        'parent_id' => null, 'name' => 'Accounts', 'icon' => '', 'route_name' => 'accgroup', 'route_path' => '', 'menu_color' => '#000', 'sort_order' => 1,
        'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 0, 'type' => 2, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $approval = DB::table('menus')->insertGetId([
        'parent_id' => null, 'name' => 'Approval', 'icon' => '', 'route_name' => 'approvalgroup', 'route_path' => '', 'menu_color' => '#000', 'sort_order' => 2,
        'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 0, 'type' => 2, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $page = DB::table('menus')->insertGetId([
        'parent_id' => $accounts, 'name' => 'Fixed Assets', 'icon' => '', 'route_name' => 'fixedasset', 'route_path' => '/fixedasset', 'menu_color' => '#000', 'sort_order' => 1,
        'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $roleId = DB::table('roles')->insertGetId(['name' => 'companyadmin', 'company_id' => null, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    (require database_path('migrations/2026_10_15_100100_add_asset_lifecycle_menus.php'))->up();

    expect(DB::table('menus')->where('parent_id', $page)->orderBy('route_path')->pluck('route_path')->all())->toBe(['/fixedasset/dispose', '/fixedasset/impair', '/fixedasset/revalue'])
        ->and((int) DB::table('menus')->where('route_path', '/assetapproval')->value('parent_id'))->toBe($approval)
        ->and(DB::table('menus')->where('route_path', '/assetapproval/reject')->exists())->toBeTrue()
        ->and(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(5);
});
