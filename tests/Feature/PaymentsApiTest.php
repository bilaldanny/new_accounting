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
function seedPaymentScope(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'PAY001',
        'name' => 'Payment Test Company',
        'address' => '1 Payment Street',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $branchId = DB::table('branches')->insertGetId([
        'code' => 'PYB001',
        'company_id' => $companyId,
        'name' => 'Payment Branch',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $debitAccountId = DB::table('chart_of_accounts')->insertGetId([
        'company_id' => $companyId,
        'branch_id' => $branchId,
        'code' => '501-00001',
        'name' => 'Office Expense',
        'acc_type' => 't',
        'acc_nature' => 'dr',
        'bs' => 0,
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $creditAccountId = DB::table('chart_of_accounts')->insertGetId([
        'company_id' => $companyId,
        'branch_id' => $branchId,
        'code' => '101-00002',
        'name' => 'Bank Account',
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
        'debit_code' => '501-00001',
        'credit_code' => '101-00002',
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validPaymentPayload(array $scope, array $overrides = []): array
{
    return array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'voucher_type' => 'BP',
        'voucher_date' => '2026-09-04',
        'comments' => 'Rent payment',
        'taccountdetails' => [
            [
                'account_id' => $scope['debit_account_id'],
                'code' => $scope['debit_code'],
                'account_name' => 'Office Expense',
                'account_nature' => 'dr',
                'description' => 'September rent',
                'debit' => 2500,
                'credit' => 0,
            ],
            [
                'account_id' => $scope['credit_account_id'],
                'code' => $scope['credit_code'],
                'account_name' => 'Bank Account',
                'account_nature' => 'cr',
                'description' => 'Bank transfer',
                'debit' => 0,
                'credit' => 2500,
            ],
        ],
    ], $overrides);
}

test('payments api creates a balanced bank payment voucher', function () {
    $scope = seedPaymentScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/payments', validPaymentPayload($scope))
        ->assertSuccessful();

    $payment = TAccount::query()->manualPayments()->first();

    expect($payment)->not->toBeNull()
        ->and($payment->company_id)->toBe($scope['company_id'])
        ->and($payment->branch_id)->toBe($scope['branch_id'])
        ->and($payment->voucher_no)->toStartWith('BP-')
        ->and($payment->type)->toBe('bank')
        ->and((float) $payment->total_amount)->toBe(2500.0)
        ->and($payment->comments)->toBe('Rent payment');

    expect(TAccountDetail::query()->where('t_account_id', $payment->id)->count())->toBe(2)
        ->and((float) TAccountDetail::query()->where('t_account_id', $payment->id)->sum('debit'))
        ->toBe((float) TAccountDetail::query()->where('t_account_id', $payment->id)->sum('credit'));
});

test('payments api marks online payments with an online cheque number', function () {
    $scope = seedPaymentScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/payments', validPaymentPayload($scope, [
        'voucher_type' => 'OP',
        'comments' => 'Online transfer',
    ]))->assertSuccessful();

    $payment = TAccount::query()->manualPayments()->first();

    expect($payment->voucher_no)->toStartWith('OP-')
        ->and($payment->type)->toBe('online')
        ->and($payment->cheque_no)->toBe('ONLINE');
});

test('payments api rejects journal voucher types', function () {
    $scope = seedPaymentScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/payments', validPaymentPayload($scope, [
        'voucher_type' => 'JV',
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['voucher_type']);
});

test('payments api rejects an unbalanced voucher', function () {
    $scope = seedPaymentScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/payments', validPaymentPayload($scope, [
        'taccountdetails' => [
            [
                'account_id' => $scope['debit_account_id'],
                'debit' => 2500,
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

test('payments index lists only payment vouchers', function () {
    $scope = seedPaymentScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/payments', validPaymentPayload($scope))->assertSuccessful();

    TAccount::factory()->create([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'voucher_no' => 'JV-00001',
        'voucher_date' => now(),
    ]);

    $response = $this->getJson('/api/payments');

    $response->assertSuccessful();
    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.voucher_no'))->toStartWith('BP-')
        ->and($response->json('data.data.0.kind_label'))->toBe('Bank Payment');
});

test('payments api updates lines and can delete a voucher', function () {
    $scope = seedPaymentScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/payments', validPaymentPayload($scope))->assertSuccessful();

    $payment = TAccount::query()->manualPayments()->firstOrFail();

    $this->putJson('/api/payments/'.$payment->id, validPaymentPayload($scope, [
        'comments' => 'Updated rent',
        'taccountdetails' => [
            [
                'account_id' => $scope['debit_account_id'],
                'code' => $scope['debit_code'],
                'debit' => 3000,
                'credit' => 0,
            ],
            [
                'account_id' => $scope['credit_account_id'],
                'code' => $scope['credit_code'],
                'debit' => 0,
                'credit' => 3000,
            ],
        ],
    ]))->assertSuccessful();

    $payment->refresh();

    expect($payment->comments)->toBe('Updated rent')
        ->and((float) $payment->total_amount)->toBe(3000.0);

    $this->getJson('/api/payments/'.$payment->id)
        ->assertSuccessful()
        ->assertJsonPath('taccountdetails.0.debit', 3000);

    $this->postJson('/api/payments/bulk_delete', [$payment->id])->assertSuccessful();

    expect(TAccount::query()->find($payment->id))->toBeNull();
});

test('payments voucher number endpoint returns the next bp number', function () {
    $scope = seedPaymentScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->getJson('/api/payments/voucher-no?company_id='.$scope['company_id'].'&branch_id='.$scope['branch_id'].'&type=BP')
        ->assertSuccessful()
        ->assertSee('BP-');
});

test('payments duplicate creates a fresh pending voucher with copied lines', function () {
    $scope = seedPaymentScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/payments', validPaymentPayload($scope))->assertSuccessful();

    $original = TAccount::query()->manualPayments()->firstOrFail();
    $original->update(['status' => 'approved']);

    $this->postJson('/api/payments/duplicate', ['id' => $original->id])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Duplicated');

    $payments = TAccount::query()->manualPayments()->orderBy('id')->get();

    expect($payments)->toHaveCount(2);

    $duplicate = $payments->last();

    expect($duplicate->id)->not->toBe($original->id)
        ->and($duplicate->voucher_no)->not->toBe($original->voucher_no)
        ->and($duplicate->voucher_no)->toStartWith('BP-')
        ->and($duplicate->status)->toBe('pending');
});

test('payments duplicate is forbidden without menu permission', function () {
    $scope = seedPaymentScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/payments', validPaymentPayload($scope))->assertSuccessful();
    $original = TAccount::query()->manualPayments()->firstOrFail();

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

    $this->postJson('/api/payments/duplicate', ['id' => $original->id])
        ->assertForbidden();

    expect(TAccount::query()->manualPayments()->count())->toBe(1);
});
