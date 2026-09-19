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
function seedExpenseScope(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'EXP001',
        'name' => 'Expense Test Company',
        'address' => '1 Ledger Street',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $branchId = DB::table('branches')->insertGetId([
        'code' => 'EXB001',
        'company_id' => $companyId,
        'name' => 'Expense Branch',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $debitAccountId = DB::table('chart_of_accounts')->insertGetId([
        'company_id' => $companyId,
        'branch_id' => $branchId,
        'code' => '501-00001',
        'name' => 'Office Rent',
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
        'code' => '101-00001',
        'name' => 'Cash in Hand',
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
        'debit_code' => '501-00001',
        'credit_code' => '101-00001',
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validExpensePayload(array $scope, array $overrides = []): array
{
    return array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'voucher_type' => 'EXP',
        'voucher_date' => '2026-09-18',
        'ref_no' => 'BILL-1001',
        'comments' => 'September office rent',
        'taccountdetails' => [
            [
                'account_id' => $scope['debit_account_id'],
                'code' => $scope['debit_code'],
                'account_name' => 'Office Rent',
                'account_nature' => 'dr',
                'description' => 'Rent for September',
                'debit' => 1200,
                'credit' => 0,
            ],
            [
                'account_id' => $scope['credit_account_id'],
                'code' => $scope['credit_code'],
                'account_name' => 'Cash in Hand',
                'account_nature' => 'dr',
                'description' => 'Paid via cash',
                'debit' => 0,
                'credit' => 1200,
            ],
        ],
    ], $overrides);
}

test('expenses api creates a balanced voucher with line items', function () {
    $scope = seedExpenseScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/expenses', validExpensePayload($scope))
        ->assertSuccessful();

    $expense = TAccount::query()->manualExpenses()->first();

    expect($expense)->not->toBeNull()
        ->and($expense->company_id)->toBe($scope['company_id'])
        ->and($expense->branch_id)->toBe($scope['branch_id'])
        ->and($expense->voucher_no)->toStartWith('EXP-')
        ->and($expense->ref_no)->toBe('BILL-1001')
        ->and((float) $expense->total_amount)->toBe(1200.0)
        ->and($expense->status)->toBe('pending')
        ->and($expense->comments)->toBe('September office rent');

    expect(TAccountDetail::query()->where('t_account_id', $expense->id)->count())->toBe(2)
        ->and((float) TAccountDetail::query()->where('t_account_id', $expense->id)->sum('debit'))
        ->toBe((float) TAccountDetail::query()->where('t_account_id', $expense->id)->sum('credit'));
});

test('expenses api rejects an unbalanced voucher', function () {
    $scope = seedExpenseScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/expenses', validExpensePayload($scope, [
        'taccountdetails' => [
            [
                'account_id' => $scope['debit_account_id'],
                'debit' => 1200,
                'credit' => 0,
            ],
            [
                'account_id' => $scope['credit_account_id'],
                'debit' => 0,
                'credit' => 500,
            ],
        ],
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['taccountdetails']);
});

test('expenses index lists only manual expense vouchers', function () {
    $scope = seedExpenseScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/expenses', validExpensePayload($scope))->assertSuccessful();

    TAccount::factory()->create([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'voucher_no' => 'JV-00001',
        'voucher_date' => now(),
    ]);

    $response = $this->getJson('/api/expenses');

    $response->assertSuccessful();
    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.company_name'))->toBe('Expense Test Company')
        ->and($response->json('data.data.0.branch_name'))->toBe('Expense Branch')
        ->and($response->json('data.data.0.voucher_no'))->toStartWith('EXP-');
});

test('expenses api updates lines and can delete a voucher', function () {
    $scope = seedExpenseScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/expenses', validExpensePayload($scope))->assertSuccessful();

    $expense = TAccount::query()->manualExpenses()->firstOrFail();

    $this->putJson('/api/expenses/'.$expense->id, validExpensePayload($scope, [
        'comments' => 'Updated rent amount',
        'taccountdetails' => [
            [
                'account_id' => $scope['debit_account_id'],
                'code' => $scope['debit_code'],
                'debit' => 1500,
                'credit' => 0,
            ],
            [
                'account_id' => $scope['credit_account_id'],
                'code' => $scope['credit_code'],
                'debit' => 0,
                'credit' => 1500,
            ],
        ],
    ]))->assertSuccessful();

    $expense->refresh();

    expect($expense->comments)->toBe('Updated rent amount')
        ->and((float) $expense->total_amount)->toBe(1500.0);

    $this->getJson('/api/expenses/'.$expense->id)
        ->assertSuccessful()
        ->assertJsonPath('taccountdetails.0.debit', 1500);

    $this->postJson('/api/expenses/bulk_delete', [$expense->id])->assertSuccessful();

    expect(TAccount::query()->find($expense->id))->toBeNull();
});

test('expenses voucher number endpoint returns the next exp number', function () {
    $scope = seedExpenseScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->getJson('/api/expenses/voucher-no?company_id='.$scope['company_id'].'&branch_id='.$scope['branch_id'].'&type=EXP')
        ->assertSuccessful()
        ->assertSee('EXP-');
});

test('expenses duplicate creates a fresh pending voucher with copied lines', function () {
    $scope = seedExpenseScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/expenses', validExpensePayload($scope))->assertSuccessful();

    $original = TAccount::query()->manualExpenses()->firstOrFail();
    $original->update(['status' => 'approved']);

    $this->postJson('/api/expenses/duplicate', ['id' => $original->id])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Duplicated');

    $expenses = TAccount::query()->manualExpenses()->orderBy('id')->get();

    expect($expenses)->toHaveCount(2);

    $duplicate = $expenses->last();

    expect($duplicate->id)->not->toBe($original->id)
        ->and($duplicate->voucher_no)->not->toBe($original->voucher_no)
        ->and($duplicate->voucher_no)->toStartWith('EXP-')
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

test('expenses duplicate returns not found for a nonexistent voucher', function () {
    seedExpenseScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/expenses/duplicate', ['id' => 999999])
        ->assertNotFound();
});

test('expenses duplicate is forbidden without menu permission', function () {
    $scope = seedExpenseScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/expenses', validExpensePayload($scope))->assertSuccessful();
    $original = TAccount::query()->manualExpenses()->firstOrFail();

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

    $this->postJson('/api/expenses/duplicate', ['id' => $original->id])
        ->assertForbidden();

    expect(TAccount::query()->manualExpenses()->count())->toBe(1);
});
