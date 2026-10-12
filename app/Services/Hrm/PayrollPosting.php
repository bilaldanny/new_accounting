<?php

namespace App\Services\Hrm;

use App\Services\AccountsVoucher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Posts an approved payroll to the ledger as one journal voucher, only when the company switched payroll posting on and mapped the
 * accounts. It goes through AccountsVoucher, the same path as a hand-entered journal, so the journal approval setting, the period
 * lock and voucher numbering apply: a locked month refuses the posting and the run stays approved.
 *
 *   Dr Salary expense              gross pay - attendance deductions + employer contributions
 *   Cr Salary payable              net pay
 *   Cr Tax payable                 income tax withheld
 *   Cr Statutory payable           employee deductions + employer contributions
 *   Cr Loan receivable             loan / advance instalments recovered
 *   Dr Accrual expense / Cr Accrual liability   gratuity accrual (when configured)
 *
 * The voucher balances by construction (net = gross - attendance - employee deductions - tax - loan); the service checks it anyway.
 * A run is posted once.
 */
class PayrollPosting
{
    public function __construct(private readonly HrSettings $settings, private readonly AccountsVoucher $vouchers) {}

    /**
     * @throws ValidationException
     */
    public function post(int $runId, int $companyId): int
    {
        $run = DB::table('payroll_runs')->where('company_id', $companyId)->where('id', $runId)->first();
        abort_if($run === null, 404);

        if ($run->status === 'posted' || $run->voucher_id !== null) {
            throw ValidationException::withMessages(['status' => ['This payroll is already posted.']]);
        }

        if ($run->status !== 'approved') {
            throw ValidationException::withMessages(['status' => ['Approve the payroll before posting it.']]);
        }

        $settings = $this->settings->all($companyId);

        if (! $settings['posting_enabled']) {
            throw ValidationException::withMessages(['posting' => ['Payroll posting to the ledger is switched off in the HR settings.']]);
        }

        $totals = DB::table('payroll_lines')->where('payroll_run_id', $run->id)->selectRaw('coalesce(sum(gross), 0) as gross, coalesce(sum(attendance_deduction), 0) as attendance, coalesce(sum(statutory_employee), 0) as employee, coalesce(sum(statutory_employer), 0) as employer, coalesce(sum(tax), 0) as tax, coalesce(sum(loan_deduction), 0) as loan, coalesce(sum(net), 0) as net, coalesce(sum(accrual), 0) as accrual')->first();
        $accounts = $settings['accounts'];
        $need = fn (string $slot, float $amount) => $amount > 0 && $accounts[$slot] === null
            ? throw ValidationException::withMessages(['accounts' => ['Map the '.str_replace('_', ' ', $slot).' account in the HR settings before posting.']])
            : null;

        $expense = round((float) $totals->gross - (float) $totals->attendance + (float) $totals->employer, 2);
        $statutory = round((float) $totals->employee + (float) $totals->employer, 2);
        $need('salary_expense', $expense);
        $need('salary_payable', (float) $totals->net);
        $need('tax_payable', (float) $totals->tax);
        $need('statutory_payable', $statutory);
        $need('loan_receivable', (float) $totals->loan);
        $need('accrual_expense', (float) $totals->accrual);
        $need('accrual_liability', (float) $totals->accrual);

        $lines = [];
        $add = function (?int $account, float $debit, float $credit, string $text) use (&$lines): void {
            if ($account !== null && ($debit > 0 || $credit > 0)) {
                $lines[] = ['account_id' => $account, 'debit' => round($debit, 2), 'credit' => round($credit, 2), 'description' => $text];
            }
        };
        $label = 'Payroll '.$run->period;
        $add($accounts['salary_expense'], $expense, 0, $label.': salaries and employer contributions');
        $add($accounts['salary_payable'], 0, (float) $totals->net, $label.': net pay');
        $add($accounts['tax_payable'], 0, (float) $totals->tax, $label.': income tax withheld');
        $add($accounts['statutory_payable'], 0, $statutory, $label.': statutory contributions');
        $add($accounts['loan_receivable'], 0, (float) $totals->loan, $label.': loan and advance recovery');
        $add($accounts['accrual_expense'], (float) $totals->accrual, 0, $label.': gratuity accrual');
        $add($accounts['accrual_liability'], 0, (float) $totals->accrual, $label.': gratuity accrual');

        $debit = round(array_sum(array_column($lines, 'debit')), 2);
        $credit = round(array_sum(array_column($lines, 'credit')), 2);

        if ($lines === [] || abs($debit - $credit) > 0.009) {
            throw ValidationException::withMessages(['posting' => ["The payroll does not balance (debit {$debit}, credit {$credit}); recalculate it."]]);
        }

        $date = Carbon::parse($run->period.'-01')->endOfMonth()->toDateString();

        return (int) DB::transaction(function () use ($run, $companyId, $date, $lines, $label): int {
            $voucher = $this->vouchers->post($companyId, $run->branch_id === null ? null : (int) $run->branch_id, $date, $lines, $label, 'PAYROLL-'.$run->period);
            DB::table('payroll_runs')->where('id', $run->id)->update(['status' => 'posted', 'voucher_id' => $voucher->id, 'posted_at' => now(), 'updated_at' => now()]);

            return (int) $voucher->id;
        });
    }
}
