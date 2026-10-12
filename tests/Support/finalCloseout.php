<?php

use App\Models\PeriodLockEntry;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Shared fixtures of the final closeout tests (journal schedules, bad debt, dunning, projects, multi-currency, inter-branch).
 */

/**
 * A company with a customer and a set of accounts, journal vouchers auto-approved unless told otherwise.
 *
 * @return array<string, mixed>
 */
function fcoScope(bool $autoApprove = true, bool $expenseAutoApprove = true): array
{
    $scope = seedSellScope();

    DB::table('company_settings')->updateOrInsert(
        ['company_id' => $scope['company_id']],
        [
            'company_id' => $scope['company_id'], 'journal_entry' => $autoApprove ? 1 : 0, 'expense_approval' => $expenseAutoApprove ? 1 : 0,
            'created_at' => now(), 'updated_at' => now(),
        ],
    );

    $accounts = [
        'cash' => ['201-00201', 'Cash', 'dr', false],
        'bank' => ['201-00202', 'Bank', 'dr', false],
        'rent' => ['401-00201', 'Rent Expense', 'dr', true],
        'insurance' => ['401-00202', 'Insurance Expense', 'dr', true],
        'prepaid' => ['201-00203', 'Prepaid Expenses', 'dr', false],
        'accrued' => ['301-00201', 'Accrued Liabilities', 'cr', false],
        'deferred' => ['301-00202', 'Deferred Revenue', 'cr', false],
        'revenue' => ['501-00201', 'Service Revenue', 'cr', true],
        'baddebt' => ['401-00203', 'Bad Debt Expense', 'dr', true],
        'allowance' => ['201-00204', 'Allowance for Doubtful Debts', 'cr', false],
        'fx' => ['401-00204', 'Exchange Gain Loss', 'dr', true],
    ];

    foreach ($accounts as $key => [$code, $name, $nature, $pl]) {
        $scope[$key.'_id'] = insertPurchaseChartAccount($scope, $code, $name, $nature, $pl);
        $scope[$key.'_code'] = $code;
    }

    return $scope;
}

/**
 * @return array{company_id: int, branch_id: int}
 */
function fcoOtherCompany(string $suffix = '2'): array
{
    $companyId = DB::table('companies')->insertGetId(['code' => 'FCO'.$suffix, 'name' => 'Other Co '.$suffix, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $branchId = DB::table('branches')->insertGetId(['code' => 'FCOB'.$suffix, 'company_id' => $companyId, 'name' => 'Other Branch '.$suffix, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    return ['company_id' => $companyId, 'branch_id' => $branchId];
}

/**
 * A user of the company whose role holds exactly these menu permissions.
 *
 * @param  array<string, mixed>  $scope
 * @param  list<string>  $paths
 */
function fcoStaff(array $scope, array $paths): User
{
    $role = Role::query()->create(['name' => 'fco_'.uniqid(), 'company_id' => $scope['company_id'], 'is_active' => true]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path);
    }

    return createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'email_verified_at' => now()]);
}

/**
 * Debit and credit totals of every approved voucher of a company: equal when the books balance.
 *
 * @return array{debit: float, credit: float}
 */
function fcoTrialBalance(int $companyId): array
{
    $row = DB::table('t_account_details as d')
        ->join('t_accounts as t', 't.id', '=', 'd.t_account_id')
        ->where('t.company_id', $companyId)
        ->where('t.status', 'approved')
        ->selectRaw('coalesce(sum(d.debit), 0) as debit, coalesce(sum(d.credit), 0) as credit')
        ->first();

    return ['debit' => round((float) $row->debit, 2), 'credit' => round((float) $row->credit, 2)];
}

function fcoLockThrough(int $companyId, string $date): void
{
    PeriodLockEntry::query()->create(['company_id' => $companyId, 'locked_through' => $date, 'action' => 'lock', 'locked_by' => 1]);
}

/**
 * Net balance (debit less credit) of an account over the approved vouchers of its company.
 */
function fcoBalance(int $accountId): float
{
    $row = DB::table('t_account_details as d')
        ->join('t_accounts as t', 't.id', '=', 'd.t_account_id')
        ->where('d.coa_id', $accountId)
        ->where('t.status', 'approved')
        ->selectRaw('coalesce(sum(d.debit), 0) - coalesce(sum(d.credit), 0) as net')
        ->first();

    return round((float) $row->net, 2);
}
