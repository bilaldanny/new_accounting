<?php

use Illuminate\Support\Facades\DB;

/**
 * Helpers for the accounts report tests (general ledger, trial balance, receipts & payments vouchers).
 * Accounts and vouchers are inserted straight into the tables so every amount, date and status is
 * known. They build on trpScope / prpFinancialYear / prpOpeningBalance.
 */

/**
 * A chart-of-accounts row.
 *
 * @param  array{company_id: int, branch_id: int}  $scope
 * @param  array<string, mixed>  $attributes
 */
function ldgAccount(array $scope, string $code, string $name, string $type = 't', string $nature = 'dr', ?int $parentId = null, array $attributes = []): int
{
    return DB::table('chart_of_accounts')->insertGetId(array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'parent_id' => $parentId,
        'code' => $code,
        'name' => $name,
        'acc_type' => $type,
        'acc_nature' => $nature,
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));
}

/**
 * A voucher with its lines. Each line is [account code, debit, credit] plus an optional contact id and
 * account id; the header account is the first line's unless given.
 *
 * @param  array{company_id: int, branch_id: int}  $scope
 * @param  list<array{0: string, 1: float, 2: float, 3?: int|null, 4?: int|null}>  $lines
 * @param  array<string, mixed>  $attributes
 */
function ldgVoucher(array $scope, string $voucherNo, string $date, array $lines, array $attributes = []): int
{
    $voucherId = DB::table('t_accounts')->insertGetId(array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'coa_id' => null,
        'transaction_id' => null,
        'account_code' => $lines[0][0],
        'voucher_no' => $voucherNo,
        'ref_no' => '',
        'cheque_no' => '',
        'comments' => '',
        'voucher_date' => $date,
        'total_amount' => 0,
        'net_total' => 0,
        'status' => 'approved',
        'type' => 'cash',
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));

    foreach ($lines as $line) {
        DB::table('t_account_details')->insert([
            't_account_id' => $voucherId,
            'branch_id' => $attributes['branch_id'] ?? $scope['branch_id'],
            'coa_id' => $line[4] ?? null,
            'contact_id' => $line[3] ?? null,
            'account_code' => $line[0],
            'description' => 'Line of '.$voucherNo,
            'acc_nature' => $line[1] > 0 ? 'dr' : 'cr',
            'debit' => $line[1],
            'credit' => $line[2],
            'highlight' => 0,
            'amount' => max($line[1], $line[2]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $voucherId;
}

/**
 * A small company: cash 1000 dr and capital 1000 cr on the first day of the year, then three approved
 * vouchers. Totals over the whole year: cash +800 -200, sales cr 800, expense dr 200.
 *
 * @return array{scope: array<string, int>, cash: int, capital: int, sales: int, expense: int}
 */
function ldgBooks(): array
{
    $scope = trpScope();
    prpFinancialYear($scope['company_id']);

    $cash = ldgAccount($scope, '212-00001', 'Cash in Hand');
    $capital = ldgAccount($scope, '100-00001', 'Owner Capital', 't', 'cr');
    // Sales is flagged as a debit account here on purpose: the digit says revenue, so it is credit-normal.
    $sales = ldgAccount($scope, '511-00001', 'Local Sales', 't', 'dr');
    $expense = ldgAccount($scope, '441-00001', 'General Expense');

    prpOpeningBalance($scope, $cash, 1000, 'dr');
    prpOpeningBalance($scope, $capital, 1000, 'cr');

    ldgVoucher($scope, 'JV-00001', '2026-08-01', [['212-00001', 500, 0], ['511-00001', 0, 500]]);
    ldgVoucher($scope, 'JV-00002', '2026-08-10', [['441-00001', 200, 0], ['212-00001', 0, 200]]);
    ldgVoucher($scope, 'JV-00003', '2026-09-05', [['212-00001', 300, 0], ['511-00001', 0, 300]]);

    return ['scope' => $scope, 'cash' => $cash, 'capital' => $capital, 'sales' => $sales, 'expense' => $expense];
}
