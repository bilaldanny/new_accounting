<?php

use App\Models\Role;
use App\Models\TAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Task C, item 9: transfers between the branches of one company, with the elimination on consolidation. Separate companies are
 * separate tenants and cannot be connected.
 */
const IB_PERMS = ['/interbranch', '/interbranch/add', '/interbranch/reverse', '/interbranch/settings'];

function ibScope(bool $autoApprove = true): array
{
    $scope = fcoScope($autoApprove);
    $scope['branch2_id'] = DB::table('branches')->insertGetId(['code' => 'IBB2', 'company_id' => $scope['company_id'], 'name' => 'Second Branch', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $scope['due_from_id'] = insertPurchaseChartAccount($scope, '201-00290', 'Due From Branches', 'dr', false);
    $scope['due_to_id'] = insertPurchaseChartAccount($scope, '301-00290', 'Due To Branches', 'cr', false);

    return $scope;
}

function ibTransfer(array $scope, array $extra = [])
{
    return test()->postJson('/api/interbranch', $extra + [
        'from_branch_id' => $scope['branch_id'], 'to_branch_id' => $scope['branch2_id'], 'amount' => 1000, 'transaction_date' => '2026-10-05',
        'from_account_id' => $scope['cash_id'], 'to_account_id' => $scope['rent_id'], 'description' => 'Rent paid for branch 2',
    ]);
}

test('a transfer is two vouchers, one in each branch, and the clearing accounts eliminate on consolidation', function () {
    $scope = ibScope();
    Sanctum::actingAs(fcoStaff($scope, IB_PERMS, branchBound: false));
    $this->putJson('/api/interbranch/settings', ['receivable_account_id' => $scope['due_from_id'], 'payable_account_id' => $scope['due_to_id']])->assertOk();

    $row = ibTransfer($scope)->assertCreated()->json('data');

    $from = DB::table('t_accounts')->where('id', $row['from_voucher_id'])->first();
    $to = DB::table('t_accounts')->where('id', $row['to_voucher_id'])->first();
    expect((int) $from->branch_id)->toBe((int) $scope['branch_id'])->and((int) $to->branch_id)->toBe((int) $scope['branch2_id'])
        ->and(fcoTrialBalance($scope['company_id']))->toBe(['debit' => 2000.0, 'credit' => 2000.0]);

    $report = $this->getJson('/api/interbranch/consolidation?as_of=2026-10-31')->assertOk();
    $branches = collect($report->json('branches'))->keyBy('branch_id');
    expect($branches[$scope['branch_id']]['due_from_branches'])->toEqual(1000)->and($branches[$scope['branch2_id']]['due_to_branches'])->toEqual(1000)
        ->and($report->json('consolidated.eliminated'))->toEqual(1000)->and($report->json('consolidated.unmatched'))->toEqual(0)
        ->and($report->json('consolidated.consolidated_receivable'))->toEqual(0)->and($report->json('consolidated.consolidated_payable'))->toEqual(0);

    // reversing removes both vouchers and the elimination with them
    $this->postJson("/api/interbranch/{$row['id']}/reverse")->assertOk();
    expect(TAccount::query()->count())->toBe(0)
        ->and($this->getJson('/api/interbranch/consolidation?as_of=2026-10-31')->json('consolidated.eliminated'))->toEqual(0);
    $this->postJson("/api/interbranch/{$row['id']}/reverse")->assertUnprocessable();
});

test('vouchers waiting for approval do not count, so the mismatch shows as unmatched', function () {
    $scope = ibScope(autoApprove: false);
    Sanctum::actingAs(fcoStaff($scope, IB_PERMS, branchBound: false));
    $this->putJson('/api/interbranch/settings', ['receivable_account_id' => $scope['due_from_id'], 'payable_account_id' => $scope['due_to_id']])->assertOk();

    $row = ibTransfer($scope)->assertCreated()->json('data');
    expect($this->getJson('/api/interbranch/consolidation?as_of=2026-10-31')->json('consolidated.due_from_branches'))->toEqual(0);

    // only the giving branch's voucher is approved: it is shown as unmatched
    TAccount::query()->whereKey($row['from_voucher_id'])->update(['status' => 'approved']);
    $consolidated = $this->getJson('/api/interbranch/consolidation?as_of=2026-10-31')->json('consolidated');
    expect($consolidated['due_from_branches'])->toEqual(1000)->and($consolidated['unmatched'])->toEqual(1000)->and($consolidated['eliminated'])->toEqual(0);
});

test('transfers stay inside one company, between two different branches, on the branch own accounts, and respect the period lock', function () {
    $scope = ibScope();
    $other = fcoOtherCompany();
    Sanctum::actingAs(fcoStaff($scope, IB_PERMS, branchBound: false));

    // not set up yet
    ibTransfer($scope)->assertUnprocessable();
    $this->putJson('/api/interbranch/settings', ['receivable_account_id' => $scope['due_from_id'], 'payable_account_id' => $scope['due_to_id']])->assertOk();
    $this->putJson('/api/interbranch/settings', ['receivable_account_id' => $scope['due_from_id'], 'payable_account_id' => $scope['due_from_id']])->assertUnprocessable();

    ibTransfer($scope, ['to_branch_id' => $scope['branch_id']])->assertUnprocessable();
    ibTransfer($scope, ['to_branch_id' => $other['branch_id']])->assertUnprocessable();
    ibTransfer($scope, ['from_account_id' => $scope['due_from_id']])->assertUnprocessable();

    fcoLockThrough($scope['company_id'], '2026-10-31');
    ibTransfer($scope)->assertStatus(422);

    expect(DB::table('interbranch_transactions')->count())->toBe(0)->and(TAccount::query()->count())->toBe(0);
});

test('a branch user gives only from their own branch and sees only the transfers that touch it', function () {
    $scope = ibScope();
    $third = DB::table('branches')->insertGetId(['code' => 'IBB3', 'company_id' => $scope['company_id'], 'name' => 'Third Branch', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    Sanctum::actingAs(fcoStaff($scope, IB_PERMS, branchBound: false));
    $this->putJson('/api/interbranch/settings', ['receivable_account_id' => $scope['due_from_id'], 'payable_account_id' => $scope['due_to_id']])->assertOk();
    ibTransfer($scope)->assertCreated();
    ibTransfer($scope, ['to_branch_id' => $third, 'amount' => 50])->assertCreated();

    // a user of the second branch sees one of the two and cannot give from the first
    $role = Role::query()->create(['name' => 'ib_'.uniqid(), 'company_id' => $scope['company_id'], 'is_active' => true]);

    foreach (IB_PERMS as $path) {
        grantMenuPermission($role->id, $path);
    }

    $user = createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch2_id'], 'email_verified_at' => now()]);
    Sanctum::actingAs($user);

    $this->getJson('/api/interbranch')->assertOk()->assertJsonCount(1, 'data');
    ibTransfer($scope)->assertUnprocessable();
});
