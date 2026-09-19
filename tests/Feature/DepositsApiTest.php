<?php

use App\Models\Role;
use App\Models\TAccount;
use App\Models\TAccountDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * @return array{company_id: int, branch_id: int, debit_account_id: int, credit_account_id: int, debit_code: string, credit_code: string}
 */
function seedDepositScope(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'DEP001',
        'name' => 'Deposit Test Company',
        'address' => '1 Deposit Street',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $branchId = DB::table('branches')->insertGetId([
        'code' => 'DPB001',
        'company_id' => $companyId,
        'name' => 'Deposit Branch',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $debitAccountId = DB::table('chart_of_accounts')->insertGetId([
        'company_id' => $companyId,
        'branch_id' => $branchId,
        'code' => '101-00003',
        'name' => 'Bank Account',
        'acc_type' => 't',
        'acc_nature' => 'dr',
        'bs' => 1,
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $creditAccountId = DB::table('chart_of_accounts')->insertGetId([
        'company_id' => $companyId,
        'branch_id' => $branchId,
        'code' => '401-00002',
        'name' => 'Capital Introduced',
        'acc_type' => 't',
        'acc_nature' => 'cr',
        'bs' => 1,
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'company_id' => $companyId,
        'branch_id' => $branchId,
        'debit_account_id' => $debitAccountId,
        'credit_account_id' => $creditAccountId,
        'debit_code' => '101-00003',
        'credit_code' => '401-00002',
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validDepositPayload(array $scope, array $overrides = []): array
{
    return array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'voucher_type' => 'BD',
        'voucher_date' => '2026-09-18',
        'comments' => 'Owner capital deposit',
        'taccountdetails' => [
            [
                'account_id' => $scope['debit_account_id'],
                'code' => $scope['debit_code'],
                'account_name' => 'Bank Account',
                'account_nature' => 'dr',
                'description' => 'Deposited to bank',
                'debit' => 5000,
                'credit' => 0,
            ],
            [
                'account_id' => $scope['credit_account_id'],
                'code' => $scope['credit_code'],
                'account_name' => 'Capital Introduced',
                'account_nature' => 'cr',
                'description' => 'Owner capital',
                'debit' => 0,
                'credit' => 5000,
            ],
        ],
    ], $overrides);
}

test('deposits api creates a balanced bank deposit voucher', function () {
    $scope = seedDepositScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/deposits', validDepositPayload($scope))
        ->assertSuccessful();

    $deposit = TAccount::query()->manualDeposits()->first();

    expect($deposit)->not->toBeNull()
        ->and($deposit->company_id)->toBe($scope['company_id'])
        ->and($deposit->branch_id)->toBe($scope['branch_id'])
        ->and($deposit->voucher_no)->toStartWith('BD-')
        ->and($deposit->type)->toBe('bank')
        ->and((float) $deposit->total_amount)->toBe(5000.0)
        ->and($deposit->comments)->toBe('Owner capital deposit');

    expect(TAccountDetail::query()->where('t_account_id', $deposit->id)->count())->toBe(2)
        ->and((float) TAccountDetail::query()->where('t_account_id', $deposit->id)->sum('debit'))
        ->toBe((float) TAccountDetail::query()->where('t_account_id', $deposit->id)->sum('credit'));
});

test('deposits api marks online deposits with an online cheque number', function () {
    $scope = seedDepositScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/deposits', validDepositPayload($scope, [
        'voucher_type' => 'OD',
        'comments' => 'Online transfer in',
    ]))->assertSuccessful();

    $deposit = TAccount::query()->manualDeposits()->first();

    expect($deposit->voucher_no)->toStartWith('OD-')
        ->and($deposit->type)->toBe('online')
        ->and($deposit->cheque_no)->toBe('ONLINE');
});

test('deposits api rejects journal voucher types', function () {
    $scope = seedDepositScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/deposits', validDepositPayload($scope, [
        'voucher_type' => 'JV',
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['voucher_type']);
});

test('deposits api rejects an unbalanced voucher', function () {
    $scope = seedDepositScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/deposits', validDepositPayload($scope, [
        'taccountdetails' => [
            [
                'account_id' => $scope['debit_account_id'],
                'debit' => 5000,
                'credit' => 0,
            ],
            [
                'account_id' => $scope['credit_account_id'],
                'debit' => 0,
                'credit' => 1000,
            ],
        ],
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['taccountdetails']);
});

test('deposits index lists only deposit vouchers', function () {
    $scope = seedDepositScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/deposits', validDepositPayload($scope))->assertSuccessful();

    TAccount::factory()->create([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'voucher_no' => 'JV-00001',
        'voucher_date' => now(),
    ]);

    $response = $this->getJson('/api/deposits');

    $response->assertSuccessful();
    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.voucher_no'))->toStartWith('BD-')
        ->and($response->json('data.data.0.kind_label'))->toBe('Bank Deposit');
});

test('deposits api updates lines and can delete a voucher', function () {
    $scope = seedDepositScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/deposits', validDepositPayload($scope))->assertSuccessful();

    $deposit = TAccount::query()->manualDeposits()->firstOrFail();

    $this->putJson('/api/deposits/'.$deposit->id, validDepositPayload($scope, [
        'comments' => 'Updated capital',
        'taccountdetails' => [
            [
                'account_id' => $scope['debit_account_id'],
                'code' => $scope['debit_code'],
                'debit' => 6000,
                'credit' => 0,
            ],
            [
                'account_id' => $scope['credit_account_id'],
                'code' => $scope['credit_code'],
                'debit' => 0,
                'credit' => 6000,
            ],
        ],
    ]))->assertSuccessful();

    $deposit->refresh();

    expect($deposit->comments)->toBe('Updated capital')
        ->and((float) $deposit->total_amount)->toBe(6000.0);

    $this->getJson('/api/deposits/'.$deposit->id)
        ->assertSuccessful()
        ->assertJsonPath('taccountdetails.0.debit', 6000);

    $this->postJson('/api/deposits/bulk_delete', [$deposit->id])->assertSuccessful();

    expect(TAccount::query()->find($deposit->id))->toBeNull();
});

test('deposits voucher number endpoint returns the next bd number', function () {
    $scope = seedDepositScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->getJson('/api/deposits/voucher-no?company_id='.$scope['company_id'].'&branch_id='.$scope['branch_id'].'&type=BD')
        ->assertSuccessful()
        ->assertSee('BD-');
});

test('deposits duplicate creates a fresh pending voucher with copied lines', function () {
    $scope = seedDepositScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/deposits', validDepositPayload($scope))->assertSuccessful();

    $original = TAccount::query()->manualDeposits()->firstOrFail();
    $original->update(['status' => 'approved']);

    $this->postJson('/api/deposits/duplicate', ['id' => $original->id])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Duplicated');

    $deposits = TAccount::query()->manualDeposits()->orderBy('id')->get();

    expect($deposits)->toHaveCount(2);

    $duplicate = $deposits->last();

    expect($duplicate->id)->not->toBe($original->id)
        ->and($duplicate->voucher_no)->not->toBe($original->voucher_no)
        ->and($duplicate->voucher_no)->toStartWith('BD-')
        ->and($duplicate->status)->toBe('pending');
});

test('deposits duplicate is forbidden without menu permission', function () {
    $scope = seedDepositScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/deposits', validDepositPayload($scope))->assertSuccessful();
    $original = TAccount::query()->manualDeposits()->firstOrFail();

    $role = Role::query()->create([
        'name' => 'companyadmin',
        'company_id' => $scope['company_id'],
        'is_active' => true,
    ]);

    $user = createStaffUserForRole($role, [
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
    ]);

    Sanctum::actingAs($user);

    $this->postJson('/api/deposits/duplicate', ['id' => $original->id])
        ->assertForbidden();

    expect(TAccount::query()->manualDeposits()->count())->toBe(1);
});
