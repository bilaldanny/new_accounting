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
function seedFundTransferScope(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'FTX001',
        'name' => 'Fund Transfer Test Company',
        'address' => '1 Ledger Street',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $branchId = DB::table('branches')->insertGetId([
        'code' => 'FTB001',
        'company_id' => $companyId,
        'name' => 'Fund Transfer Branch',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $debitAccountId = DB::table('chart_of_accounts')->insertGetId([
        'company_id' => $companyId,
        'branch_id' => $branchId,
        'code' => '101-00004',
        'name' => 'Petty Cash',
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
        'code' => '101-00005',
        'name' => 'Main Bank Account',
        'acc_type' => 't',
        'acc_nature' => 'dr',
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
        'debit_code' => '101-00004',
        'credit_code' => '101-00005',
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validFundTransferPayload(array $scope, array $overrides = []): array
{
    return array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'voucher_type' => 'FT',
        'voucher_date' => '2026-09-18',
        'ref_no' => 'UTR-556677',
        'comments' => 'Cash withdrawal for petty cash float',
        'taccountdetails' => [
            [
                'account_id' => $scope['debit_account_id'],
                'code' => $scope['debit_code'],
                'account_name' => 'Petty Cash',
                'account_nature' => 'dr',
                'description' => 'Cash withdrawn',
                'debit' => 800,
                'credit' => 0,
            ],
            [
                'account_id' => $scope['credit_account_id'],
                'code' => $scope['credit_code'],
                'account_name' => 'Main Bank Account',
                'account_nature' => 'dr',
                'description' => 'Withdrawn from bank',
                'debit' => 0,
                'credit' => 800,
            ],
        ],
    ], $overrides);
}

test('fund transfers api creates a balanced voucher with line items', function () {
    $scope = seedFundTransferScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/fundtransfers', validFundTransferPayload($scope))
        ->assertSuccessful();

    $fundTransfer = TAccount::query()->manualFundTransfers()->first();

    expect($fundTransfer)->not->toBeNull()
        ->and($fundTransfer->company_id)->toBe($scope['company_id'])
        ->and($fundTransfer->branch_id)->toBe($scope['branch_id'])
        ->and($fundTransfer->voucher_no)->toStartWith('FT-')
        ->and($fundTransfer->ref_no)->toBe('UTR-556677')
        ->and((float) $fundTransfer->total_amount)->toBe(800.0)
        ->and($fundTransfer->status)->toBe('pending')
        ->and($fundTransfer->comments)->toBe('Cash withdrawal for petty cash float');

    expect(TAccountDetail::query()->where('t_account_id', $fundTransfer->id)->count())->toBe(2)
        ->and((float) TAccountDetail::query()->where('t_account_id', $fundTransfer->id)->sum('debit'))
        ->toBe((float) TAccountDetail::query()->where('t_account_id', $fundTransfer->id)->sum('credit'));
});

test('fund transfers api rejects an unbalanced voucher', function () {
    $scope = seedFundTransferScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/fundtransfers', validFundTransferPayload($scope, [
        'taccountdetails' => [
            [
                'account_id' => $scope['debit_account_id'],
                'debit' => 800,
                'credit' => 0,
            ],
            [
                'account_id' => $scope['credit_account_id'],
                'debit' => 0,
                'credit' => 300,
            ],
        ],
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['taccountdetails']);
});

test('fund transfers index lists only manual fund transfer vouchers', function () {
    $scope = seedFundTransferScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/fundtransfers', validFundTransferPayload($scope))->assertSuccessful();

    TAccount::factory()->create([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'voucher_no' => 'JV-00001',
        'voucher_date' => now(),
    ]);

    $response = $this->getJson('/api/fundtransfers');

    $response->assertSuccessful();
    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.company_name'))->toBe('Fund Transfer Test Company')
        ->and($response->json('data.data.0.branch_name'))->toBe('Fund Transfer Branch')
        ->and($response->json('data.data.0.voucher_no'))->toStartWith('FT-')
        ->and($response->json('data.data.0.kind_label'))->toBe('Fund Transfer');
});

test('fund transfers api updates lines and can delete a voucher', function () {
    $scope = seedFundTransferScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/fundtransfers', validFundTransferPayload($scope))->assertSuccessful();

    $fundTransfer = TAccount::query()->manualFundTransfers()->firstOrFail();

    $this->putJson('/api/fundtransfers/'.$fundTransfer->id, validFundTransferPayload($scope, [
        'comments' => 'Updated transfer amount',
        'taccountdetails' => [
            [
                'account_id' => $scope['debit_account_id'],
                'code' => $scope['debit_code'],
                'debit' => 1200,
                'credit' => 0,
            ],
            [
                'account_id' => $scope['credit_account_id'],
                'code' => $scope['credit_code'],
                'debit' => 0,
                'credit' => 1200,
            ],
        ],
    ]))->assertSuccessful();

    $fundTransfer->refresh();

    expect($fundTransfer->comments)->toBe('Updated transfer amount')
        ->and((float) $fundTransfer->total_amount)->toBe(1200.0);

    $this->getJson('/api/fundtransfers/'.$fundTransfer->id)
        ->assertSuccessful()
        ->assertJsonPath('taccountdetails.0.debit', 1200);

    $this->postJson('/api/fundtransfers/bulk_delete', [$fundTransfer->id])->assertSuccessful();

    expect(TAccount::query()->find($fundTransfer->id))->toBeNull();
});

test('fund transfers voucher number endpoint returns the next ft number', function () {
    $scope = seedFundTransferScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->getJson('/api/fundtransfers/voucher-no?company_id='.$scope['company_id'].'&branch_id='.$scope['branch_id'].'&type=FT')
        ->assertSuccessful()
        ->assertSee('FT-');
});

test('fund transfers duplicate creates a fresh pending voucher with copied lines', function () {
    $scope = seedFundTransferScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/fundtransfers', validFundTransferPayload($scope))->assertSuccessful();

    $original = TAccount::query()->manualFundTransfers()->firstOrFail();
    $original->update(['status' => 'approved']);

    $this->postJson('/api/fundtransfers/duplicate', ['id' => $original->id])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Duplicated');

    $fundTransfers = TAccount::query()->manualFundTransfers()->orderBy('id')->get();

    expect($fundTransfers)->toHaveCount(2);

    $duplicate = $fundTransfers->last();

    expect($duplicate->id)->not->toBe($original->id)
        ->and($duplicate->voucher_no)->not->toBe($original->voucher_no)
        ->and($duplicate->voucher_no)->toStartWith('FT-')
        ->and($duplicate->status)->toBe('pending')
        ->and($duplicate->approved_by)->toBeNull()
        ->and($duplicate->approved_at)->toBeNull()
        ->and($duplicate->transaction_id)->toBeNull()
        ->and((float) $duplicate->total_amount)->toBe((float) $original->total_amount)
        ->and($duplicate->comments)->toBe($original->comments);

    $originalLines = TAccountDetail::query()->where('t_account_id', $original->id)->orderBy('id')->get();
    $duplicateLines = TAccountDetail::query()->where('t_account_id', $duplicate->id)->orderBy('id')->get();

    expect($duplicateLines)->toHaveCount($originalLines->count());

    foreach ($originalLines->values() as $index => $line) {
        expect((float) $duplicateLines[$index]->debit)->toBe((float) $line->debit)
            ->and((float) $duplicateLines[$index]->credit)->toBe((float) $line->credit)
            ->and($duplicateLines[$index]->account_code)->toBe($line->account_code);
    }
});

test('fund transfers duplicate returns not found for a nonexistent voucher', function () {
    seedFundTransferScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/fundtransfers/duplicate', ['id' => 999999])
        ->assertNotFound();
});

test('fund transfers duplicate is forbidden without menu permission', function () {
    $scope = seedFundTransferScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/fundtransfers', validFundTransferPayload($scope))->assertSuccessful();
    $original = TAccount::query()->manualFundTransfers()->firstOrFail();

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

    $this->postJson('/api/fundtransfers/duplicate', ['id' => $original->id])
        ->assertForbidden();

    expect(TAccount::query()->manualFundTransfers()->count())->toBe(1);
});
