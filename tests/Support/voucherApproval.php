<?php

use App\Models\CompanySetting;
use App\Models\Role;
use App\Models\TAccount;
use App\Models\User;
use App\Services\AccountCurrentBalance;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Helpers shared by the journal entry approval tests and the payment, expense, deposit and fund
 * transfer approval tests. All five voucher families live in t_accounts and take the same
 * two-line balanced payload; only the voucher prefix and the endpoints differ.
 */

/**
 * Per family: the create endpoint, a voucher prefix, the auto-approval column and the menu path
 * prefix its permission keys use.
 *
 * @return array<string, array{create: string, type: string, column: string, menu: string, approvals: string}>
 */
function voucherFamilies(): array
{
    return [
        'journal' => ['create' => '/api/journal-entries', 'type' => 'JV', 'column' => 'journal_entry', 'menu' => '/journalentry', 'approvals' => '/api/journal-entry-approvals'],
        'payment' => ['create' => '/api/payments', 'type' => 'BP', 'column' => 'payment_voucher_approval', 'menu' => '/acpayment', 'approvals' => '/api/payment-approvals'],
        'expense' => ['create' => '/api/expenses', 'type' => 'EXP', 'column' => 'expense_approval', 'menu' => '/expense', 'approvals' => '/api/expense-approvals'],
        'deposit' => ['create' => '/api/deposits', 'type' => 'BD', 'column' => 'deposit_approval', 'menu' => '/deposit', 'approvals' => '/api/deposit-approvals'],
        'fundtransfer' => ['create' => '/api/fundtransfers', 'type' => 'FT', 'column' => 'fund_transfer_approval', 'menu' => '/fundtransfer', 'approvals' => '/api/fund-transfer-approvals'],
    ];
}

/**
 * @return array{company_id: int, branch_id: int, debit_account_id: int, credit_account_id: int, debit_code: string, credit_code: string}
 */
function jeaScope(string $suffix = '1'): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'JEA'.$suffix,
        'name' => 'Approval Company '.$suffix,
        'address' => '1 Ledger Street',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $branchId = DB::table('branches')->insertGetId([
        'code' => 'JEB'.$suffix,
        'company_id' => $companyId,
        'name' => 'Approval Branch '.$suffix,
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $debitCode = '101-0000'.$suffix;
    $creditCode = '401-0000'.$suffix;

    $debitId = DB::table('chart_of_accounts')->insertGetId([
        'company_id' => $companyId,
        'branch_id' => $branchId,
        'code' => $debitCode,
        'name' => 'Cash in Hand',
        'acc_type' => 't',
        'acc_nature' => 'dr',
        'bs' => 1,
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $creditId = DB::table('chart_of_accounts')->insertGetId([
        'company_id' => $companyId,
        'branch_id' => $branchId,
        'code' => $creditCode,
        'name' => 'Capital',
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
        'debit_account_id' => $debitId,
        'credit_account_id' => $creditId,
        'debit_code' => $debitCode,
        'credit_code' => $creditCode,
    ];
}

/**
 * @param  array{company_id: int, branch_id: int, debit_account_id: int, credit_account_id: int, debit_code: string, credit_code: string}  $scope
 * @return array<string, mixed>
 */
function jeaPayload(array $scope, float $amount = 1500, string $voucherType = 'JV'): array
{
    return [
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'voucher_type' => $voucherType,
        'voucher_date' => '2026-09-04',
        'comments' => 'Opening capital',
        'taccountdetails' => [
            ['account_id' => $scope['debit_account_id'], 'code' => $scope['debit_code'], 'account_nature' => 'dr', 'description' => 'Cash received', 'debit' => $amount, 'credit' => 0],
            ['account_id' => $scope['credit_account_id'], 'code' => $scope['credit_code'], 'account_nature' => 'cr', 'description' => 'Owner capital', 'debit' => 0, 'credit' => $amount],
        ],
    ];
}

function jeaSetting(int $companyId, string $column, bool $on): void
{
    $setting = CompanySetting::query()->where('company_id', $companyId)->first()
        ?? CompanySetting::createCompanySettings($companyId, 'Approval Company');

    $setting->forceFill([$column => $on])->save();
}

/**
 * Creates a voucher of the given family through its API as the superadmin and returns it.
 *
 * @param  array{company_id: int, branch_id: int, debit_account_id: int, credit_account_id: int, debit_code: string, credit_code: string}  $scope
 */
function jeaVoucher(array $scope, string $family = 'journal', string $status = 'pending', float $amount = 1500): TAccount
{
    $config = voucherFamilies()[$family];

    Sanctum::actingAs(User::query()->findOrFail(1));

    test()->postJson($config['create'], jeaPayload($scope, $amount, $config['type']))->assertSuccessful();

    $voucher = TAccount::query()->manualFamily($family)->where('company_id', $scope['company_id'])->latest('id')->firstOrFail();

    if ($voucher->status !== $status) {
        $voucher->forceFill(['status' => $status])->save();
    }

    return $voucher->fresh();
}

/**
 * Creates a journal entry through the API as the superadmin and returns it.
 *
 * @param  array{company_id: int, branch_id: int, debit_account_id: int, credit_account_id: int, debit_code: string, credit_code: string}  $scope
 */
function jeaJournal(array $scope, string $status = 'pending'): TAccount
{
    return jeaVoucher($scope, 'journal', $status);
}

/**
 * A company user whose role holds exactly the given menu permission paths.
 *
 * @param  array{company_id: int, branch_id: int, debit_account_id: int, credit_account_id: int, debit_code: string, credit_code: string}  $scope
 * @param  list<string>  $paths
 */
function jeaUserWith(array $scope, array $paths): User
{
    $role = Role::query()->create([
        'name' => 'accountant'.uniqid(),
        'company_id' => $scope['company_id'],
        'is_active' => true,
    ]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path, ltrim(str_replace('/', '', $path), '/').uniqid());
    }

    return createStaffUserForRole($role, [
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
    ]);
}

/**
 * Activity net per account code over 2026, as the ledger and chart of accounts count it
 * (approved vouchers only).
 *
 * @param  array{company_id: int, branch_id: int, debit_account_id: int, credit_account_id: int, debit_code: string, credit_code: string}  $scope
 * @return array<string, float>
 */
function jeaNets(array $scope): array
{
    return app(AccountCurrentBalance::class)->activityNets(
        $scope['company_id'],
        $scope['branch_id'],
        [$scope['debit_code'], $scope['credit_code']],
        '2026-01-01',
        '2026-12-31',
    );
}
