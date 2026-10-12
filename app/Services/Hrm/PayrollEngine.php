<?php

namespace App\Services\Hrm;

use App\Services\SalesKpiStatement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payroll of one month. `calculate()` is a pure read: it works out every employee's earnings, attendance deductions, statutory
 * contributions, tax, loan instalment and net pay from the salary structure, the attendance, the bonuses and the rates the
 * company's accountant entered. It never assumes a rate: a company with no statutory rate or tax slab simply has none deducted.
 *
 *   monthly entitlement = basic + earning components (fixed or % of basic), pro-rated for the days employed in the month
 *   daily rate          = monthly entitlement / days of the month (calendar or the fixed count in HR settings)
 *   absent deduction    = (absent + unpaid leave days) x daily rate
 *   late / early        = minutes / 60 x hourly rate x factor (only when switched on)
 *   overtime            = minutes / 60 x hourly rate x multiplier
 *   statutory           = rate % of basic or gross (up to a base cap) + fixed, per effective-dated rate row, employee and employer side
 *   tax                 = the admin-entered annual slabs applied to 12 x taxable earnings, divided by 12
 *   net                 = gross - attendance deductions - employee statutory - tax - loan instalment
 *
 * `create()`, `approve()` and `cancel()` change state: approving applies the loan repayments and takes the month's bonuses,
 * cancelling undoes both. Nothing here touches the ledger (see PayrollPosting).
 */
class PayrollEngine
{
    public function __construct(private readonly HrSettings $settings, private readonly SalesKpiStatement $kpi) {}

    /**
     * @return array{lines: list<array<string, mixed>>, skipped: list<array{employee_id: int, name: string, reason: string}>, totals: array<string, float>}
     */
    public function calculate(int $companyId, string $period, ?int $branchId = null): array
    {
        $start = Carbon::parse($period.'-01')->startOfDay();
        $end = $start->copy()->endOfMonth();
        $settings = $this->settings->all($companyId);
        $basisDays = $settings['days_basis'] === 'fixed' ? (int) $settings['fixed_days'] : $end->day;

        $employees = DB::table('employees')->where('company_id', $companyId)->whereNull('deleted_at')
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->where(fn ($q) => $q->whereNull('join_date')->orWhereDate('join_date', '<=', $end->toDateString()))
            ->where(fn ($q) => $q->whereNull('exit_date')->orWhereDate('exit_date', '>=', $start->toDateString()))
            ->whereIn('status', ['active', 'on_leave', 'resigned', 'terminated'])->orderBy('employee_no')->get();

        $components = DB::table('salary_components')->where('company_id', $companyId)->where('is_active', true)->get()->keyBy('id');
        $rates = $this->effectiveRates($companyId, $end->toDateString());
        $slabs = $this->effectiveSlabs($companyId, $end->toDateString());
        $commissions = $this->kpi->build($companyId, $period, 'agent')['rows']->pluck('commission', 'subject_id');
        $lines = [];
        $skipped = [];

        foreach ($employees as $employee) {
            $name = trim($employee->first_name.' '.$employee->last_name);
            $structure = DB::table('employee_salaries')->where('company_id', $companyId)->where('employee_id', $employee->id)->whereDate('effective_from', '<=', $end->toDateString())->orderByDesc('effective_from')->first();

            if ($structure === null) {
                $skipped[] = ['employee_id' => (int) $employee->id, 'name' => $name, 'reason' => 'no salary structure'];

                continue;
            }

            $from = $employee->join_date !== null && Carbon::parse($employee->join_date)->gt($start) ? Carbon::parse($employee->join_date) : $start;
            $to = $employee->exit_date !== null && Carbon::parse($employee->exit_date)->lt($end) ? Carbon::parse($employee->exit_date) : $end;
            $ratio = min(1.0, max(0.0, ($from->diffInDays($to) + 1) / $end->day));
            $basic = (float) $structure->basic;
            $earnings = [['name' => 'Basic salary', 'amount' => round($basic * $ratio, 2), 'taxable' => true]];
            $monthlyEntitlement = $basic;

            foreach ((array) json_decode((string) $structure->components, true) as $row) {
                $component = $components[(int) ($row['component_id'] ?? 0)] ?? null;

                if ($component === null || $component->type !== 'earning') {
                    continue;
                }

                $monthly = $component->calc === 'percent_basic' ? $basic * (float) ($row['value'] ?? 0) / 100 : (float) ($row['value'] ?? 0);
                $monthlyEntitlement += $monthly;
                $earnings[] = ['name' => $component->name, 'amount' => round($monthly * $ratio, 2), 'taxable' => (bool) $component->is_taxable];
            }

            $deductionsFromStructure = [];

            foreach ((array) json_decode((string) $structure->components, true) as $row) {
                $component = $components[(int) ($row['component_id'] ?? 0)] ?? null;

                if ($component !== null && $component->type === 'deduction') {
                    $value = $component->calc === 'percent_basic' ? $basic * (float) ($row['value'] ?? 0) / 100 : (float) ($row['value'] ?? 0);
                    $deductionsFromStructure[] = ['name' => $component->name, 'amount' => round($value * $ratio, 2), 'kind' => 'component'];
                }
            }

            $perDay = $monthlyEntitlement / $basisDays;
            $hourly = $perDay / max(1.0, (float) $settings['standard_hours_per_day']);
            $attendance = DB::table('hr_attendance')->where('company_id', $companyId)->where('employee_id', $employee->id)->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
                ->selectRaw("count(*) as records, sum(case when status = 'absent' then 1 else 0 end) as absent, sum(case when status = 'unpaid_leave' then 1 else 0 end) as unpaid, sum(case when status = 'leave' then 1 else 0 end) as paid_leave, coalesce(sum(late_minutes), 0) as late, coalesce(sum(early_minutes), 0) as early, coalesce(sum(overtime_minutes), 0) as overtime")->first();
            $hasAttendance = (int) $attendance->records > 0;
            $absentDays = $hasAttendance || ! $settings['assume_present_without_attendance'] ? (int) $attendance->absent + (int) $attendance->unpaid : 0;
            $attendanceDeductions = [];

            if ($absentDays > 0) {
                $attendanceDeductions[] = ['name' => "Absent / unpaid leave ({$absentDays} days)", 'amount' => round($absentDays * $perDay, 2), 'kind' => 'absent'];
            }

            if ($settings['deduct_late'] && (int) $attendance->late > 0) {
                $attendanceDeductions[] = ['name' => 'Late arrival ('.(int) $attendance->late.' min)', 'amount' => round((int) $attendance->late / 60 * $hourly * (float) $settings['late_deduction_factor'], 2), 'kind' => 'late'];
            }

            if ($settings['deduct_early'] && (int) $attendance->early > 0) {
                $attendanceDeductions[] = ['name' => 'Early leaving ('.(int) $attendance->early.' min)', 'amount' => round((int) $attendance->early / 60 * $hourly * (float) $settings['late_deduction_factor'], 2), 'kind' => 'early'];
            }

            if ($settings['overtime_enabled'] && (int) $attendance->overtime > 0) {
                $earnings[] = ['name' => 'Overtime ('.(int) $attendance->overtime.' min)', 'amount' => round((int) $attendance->overtime / 60 * $hourly * (float) $settings['overtime_multiplier'], 2), 'taxable' => true];
            }

            foreach (DB::table('employee_bonuses')->where('company_id', $companyId)->where('employee_id', $employee->id)->where('period', $period)->where(fn ($q) => $q->whereNull('payroll_run_id'))->get() as $bonus) {
                $earnings[] = ['name' => 'Bonus: '.$bonus->title, 'amount' => round((float) $bonus->amount, 2), 'taxable' => (bool) $bonus->is_taxable, 'bonus_id' => (int) $bonus->id];
            }

            $commission = $employee->commission_agent_id === null ? 0.0 : round((float) ($commissions[(int) $employee->commission_agent_id] ?? 0), 2);

            if ($commission > 0) {
                $earnings[] = ['name' => 'Sales commission', 'amount' => $commission, 'taxable' => true];
            }

            $gross = round(array_sum(array_column($earnings, 'amount')), 2);
            $taxable = round(array_sum(array_map(fn (array $e): float => $e['taxable'] ? $e['amount'] : 0.0, $earnings)), 2);
            $attendanceTotal = round(array_sum(array_column($attendanceDeductions, 'amount')), 2);
            $componentDeductions = round(array_sum(array_column($deductionsFromStructure, 'amount')), 2);

            $employeeSide = [];
            $employerSide = [];

            foreach ($rates as $rate) {
                $base = $rate->basis === 'gross' ? $gross : round($basic * $ratio, 2);
                $base = $rate->base_cap !== null ? min($base, (float) $rate->base_cap) : $base;
                $amount = round($base * (float) $rate->rate_percent / 100 + (float) $rate->fixed_amount, 2);

                if ($amount <= 0) {
                    continue;
                }

                $row = ['name' => $rate->name, 'code' => $rate->code, 'amount' => $amount, 'kind' => 'statutory'];
                $rate->side === 'employer' ? $employerSide[] = $row : $employeeSide[] = $row;
            }

            $employeeStatutory = round(array_sum(array_column($employeeSide, 'amount')), 2);
            $tax = $this->tax($slabs, $taxable * 12) / 12;
            $tax = round($tax, 2);

            $loan = 0.0;
            $loanRows = [];

            foreach (DB::table('employee_loans')->where('company_id', $companyId)->where('employee_id', $employee->id)->where('status', 'active')->where('balance', '>', 0)->where('start_period', '<=', $period)->orderBy('id')->get() as $row) {
                $available = max(0.0, $gross - $attendanceTotal - $componentDeductions - $employeeStatutory - $tax - $loan);
                $take = round(min((float) $row->installment, (float) $row->balance, $available), 2);

                if ($take > 0) {
                    $loan = round($loan + $take, 2);
                    $loanRows[] = ['loan_id' => (int) $row->id, 'amount' => $take];
                }
            }

            $net = round($gross - $attendanceTotal - $componentDeductions - $employeeStatutory - $tax - $loan, 2);
            $accrual = $settings['gratuity_days_per_year'] > 0 ? round($basic / 30 * (float) $settings['gratuity_days_per_year'] / 12 * $ratio, 2) : 0.0;
            $deductions = array_merge($attendanceDeductions, $deductionsFromStructure, $employeeSide);

            if ($tax > 0) {
                $deductions[] = ['name' => 'Income tax', 'amount' => $tax, 'kind' => 'tax'];
            }

            if ($loan > 0) {
                $deductions[] = ['name' => 'Loan / advance instalment', 'amount' => $loan, 'kind' => 'loan'];
            }

            $lines[] = [
                'employee_id' => (int) $employee->id, 'employee_no' => $employee->employee_no, 'name' => $name, 'basic' => round($basic * $ratio, 2), 'gross' => $gross,
                'attendance_deduction' => $attendanceTotal, 'statutory_employee' => round($employeeStatutory + $componentDeductions, 2), 'statutory_employer' => round(array_sum(array_column($employerSide, 'amount')), 2),
                'tax' => $tax, 'loan_deduction' => $loan, 'net' => $net, 'accrual' => $accrual,
                'detail' => [
                    'earnings' => array_map(fn (array $e): array => ['name' => $e['name'], 'amount' => $e['amount']], $earnings), 'deductions' => $deductions, 'employer' => $employerSide,
                    'attendance' => ['records' => (int) $attendance->records, 'absent' => $absentDays, 'paid_leave' => (int) $attendance->paid_leave, 'late_minutes' => (int) $attendance->late, 'early_minutes' => (int) $attendance->early, 'overtime_minutes' => (int) $attendance->overtime],
                    'days_basis' => $basisDays, 'proration' => round($ratio, 4), 'bonus_ids' => array_values(array_filter(array_column($earnings, 'bonus_id'))), 'loans' => $loanRows,
                    'warnings' => $net < 0 ? ['Net pay is negative.'] : [],
                ],
            ];
        }

        return ['lines' => $lines, 'skipped' => $skipped, 'totals' => $this->totals($lines)];
    }

    /**
     * Creates a draft run for a month from the current figures. A month can have one live run (draft, approved or posted).
     *
     * @throws ValidationException
     */
    public function create(int $companyId, string $period, ?int $branchId, ?int $userId): int
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw ValidationException::withMessages(['period' => ['Use a month as YYYY-MM.']]);
        }

        if (DB::table('payroll_runs')->where('company_id', $companyId)->where('period', $period)->where('status', '!=', 'cancelled')->exists()) {
            throw ValidationException::withMessages(['period' => ['A payroll run for this month already exists. Cancel it first to make a new one.']]);
        }

        return (int) DB::transaction(function () use ($companyId, $period, $branchId, $userId): int {
            $id = (int) DB::table('payroll_runs')->insertGetId(['company_id' => $companyId, 'branch_id' => $branchId, 'period' => $period, 'status' => 'draft', 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
            $this->fill($id, $companyId, $period, $branchId);

            return $id;
        });
    }

    /**
     * Rebuilds the lines of a draft run (after attendance, bonuses or rates changed).
     */
    public function recalculate(int $runId, int $companyId): void
    {
        $run = $this->run($runId, $companyId);

        if ($run->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ['Only a draft payroll can be recalculated.']]);
        }

        DB::transaction(function () use ($run, $companyId): void {
            DB::table('payroll_lines')->where('payroll_run_id', $run->id)->delete();
            $this->fill((int) $run->id, $companyId, (string) $run->period, $run->branch_id === null ? null : (int) $run->branch_id);
        });
    }

    public function approve(int $runId, int $companyId, ?int $userId): void
    {
        $run = $this->run($runId, $companyId);

        if ($run->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ['Only a draft payroll can be approved.']]);
        }

        DB::transaction(function () use ($run, $companyId, $userId): void {
            foreach (DB::table('payroll_lines')->where('payroll_run_id', $run->id)->get() as $line) {
                $detail = (array) json_decode((string) $line->detail, true);

                foreach ((array) ($detail['bonus_ids'] ?? []) as $bonusId) {
                    DB::table('employee_bonuses')->where('company_id', $companyId)->where('id', $bonusId)->whereNull('payroll_run_id')->update(['payroll_run_id' => $run->id, 'updated_at' => now()]);
                }

                foreach ((array) ($detail['loans'] ?? []) as $repayment) {
                    $loan = DB::table('employee_loans')->where('company_id', $companyId)->where('id', $repayment['loan_id'])->lockForUpdate()->first();

                    if ($loan === null) {
                        continue;
                    }

                    $balance = round(max(0.0, (float) $loan->balance - (float) $repayment['amount']), 2);
                    DB::table('employee_loan_repayments')->insert(['company_id' => $companyId, 'employee_loan_id' => $loan->id, 'payroll_run_id' => $run->id, 'period' => $run->period, 'amount' => $repayment['amount'], 'created_at' => now(), 'updated_at' => now()]);
                    DB::table('employee_loans')->where('id', $loan->id)->update(['balance' => $balance, 'status' => $balance <= 0 ? 'closed' : 'active', 'updated_at' => now()]);
                }
            }

            DB::table('payroll_runs')->where('id', $run->id)->update(['status' => 'approved', 'approved_by' => $userId, 'approved_at' => now(), 'updated_at' => now()]);
        });
    }

    /**
     * Cancels a draft or approved run, giving back the loan repayments and the bonuses it took. A posted run cannot be cancelled here.
     */
    public function cancel(int $runId, int $companyId): void
    {
        $run = $this->run($runId, $companyId);

        if (! in_array($run->status, ['draft', 'approved'], true)) {
            throw ValidationException::withMessages(['status' => ['A posted or cancelled payroll cannot be cancelled here.']]);
        }

        DB::transaction(function () use ($run, $companyId): void {
            foreach (DB::table('employee_loan_repayments')->where('company_id', $companyId)->where('payroll_run_id', $run->id)->get() as $repayment) {
                $loan = DB::table('employee_loans')->where('id', $repayment->employee_loan_id)->first();

                if ($loan !== null) {
                    DB::table('employee_loans')->where('id', $loan->id)->update(['balance' => round((float) $loan->balance + (float) $repayment->amount, 2), 'status' => 'active', 'updated_at' => now()]);
                }
            }

            DB::table('employee_loan_repayments')->where('company_id', $companyId)->where('payroll_run_id', $run->id)->delete();
            DB::table('employee_bonuses')->where('company_id', $companyId)->where('payroll_run_id', $run->id)->update(['payroll_run_id' => null, 'updated_at' => now()]);
            DB::table('payroll_runs')->where('id', $run->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
        });
    }

    public function run(int $runId, int $companyId): object
    {
        $run = DB::table('payroll_runs')->where('company_id', $companyId)->where('id', $runId)->first();
        abort_if($run === null, 404);

        return $run;
    }

    private function fill(int $runId, int $companyId, string $period, ?int $branchId): void
    {
        $result = $this->calculate($companyId, $period, $branchId);

        foreach ($result['lines'] as $line) {
            DB::table('payroll_lines')->insert([
                'company_id' => $companyId, 'payroll_run_id' => $runId, 'employee_id' => $line['employee_id'], 'basic' => $line['basic'], 'gross' => $line['gross'],
                'attendance_deduction' => $line['attendance_deduction'], 'statutory_employee' => $line['statutory_employee'], 'statutory_employer' => $line['statutory_employer'],
                'tax' => $line['tax'], 'loan_deduction' => $line['loan_deduction'], 'net' => $line['net'], 'accrual' => $line['accrual'],
                'detail' => json_encode($line['detail'] + ['name' => $line['name'], 'employee_no' => $line['employee_no']]), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('payroll_runs')->where('id', $runId)->update(['totals' => json_encode($result['totals'] + ['skipped' => count($result['skipped'])]), 'updated_at' => now()]);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, float>
     */
    private function totals(array $lines): array
    {
        $sum = fn (string $key): float => round(array_sum(array_column($lines, $key)), 2);

        return [
            'employees' => (float) count($lines), 'gross' => $sum('gross'), 'attendance_deduction' => $sum('attendance_deduction'), 'statutory_employee' => $sum('statutory_employee'),
            'statutory_employer' => $sum('statutory_employer'), 'tax' => $sum('tax'), 'loan_deduction' => $sum('loan_deduction'), 'net' => $sum('net'), 'accrual' => $sum('accrual'),
        ];
    }

    /**
     * The latest rate row per code and side that is effective on a day.
     *
     * @return list<object>
     */
    private function effectiveRates(int $companyId, string $day): array
    {
        return DB::table('statutory_rates')->where('company_id', $companyId)->where('is_active', true)->whereDate('effective_from', '<=', $day)->orderBy('effective_from')->orderBy('id')->get()
            ->keyBy(fn (object $row): string => $row->code.'|'.$row->side)->values()->all();
    }

    /**
     * The slab set with the latest effective date on or before the day.
     *
     * @return list<object>
     */
    private function effectiveSlabs(int $companyId, string $day): array
    {
        $effective = DB::table('tax_slabs')->where('company_id', $companyId)->whereDate('effective_from', '<=', $day)->max('effective_from');

        return $effective === null ? [] : DB::table('tax_slabs')->where('company_id', $companyId)->whereDate('effective_from', substr((string) $effective, 0, 10))->orderBy('annual_from')->get()->all();
    }

    /**
     * Annual tax for an annual taxable income from admin-entered slabs: the slab that holds the income, its fixed amount plus its rate
     * on what is above the slab's lower bound.
     *
     * @param  list<object>  $slabs
     */
    private function tax(array $slabs, float $annual): float
    {
        $chosen = null;

        foreach ($slabs as $slab) {
            if ($annual >= (float) $slab->annual_from && ($slab->annual_to === null || $annual <= (float) $slab->annual_to)) {
                $chosen = $slab;
            }
        }

        return $chosen === null ? 0.0 : (float) $chosen->fixed_amount + max(0.0, $annual - (float) $chosen->annual_from) * (float) $chosen->rate_percent / 100;
    }
}
