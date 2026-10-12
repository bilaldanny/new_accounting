<?php

namespace App\Services\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The payroll register of one month: a row per employee of every approved or posted run of that month with the pay figures the
 * payslip shows. `period` is `YYYY-MM` (this month by default). A draft or cancelled run is not a payroll yet and is left out.
 */
class PayrollRegisterReport
{
    public const SORTABLE = ['employee_no' => 'employee_no', 'name' => 'name', 'gross' => 'gross', 'tax' => 'tax', 'net' => 'net'];

    public const DEFAULT_SORT = 'employee_no';

    public const DEFAULT_DESC = false;

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        $period = preg_match('/^\d{4}-\d{2}$/', (string) ($filters['period'] ?? '')) === 1 ? (string) $filters['period'] : now()->format('Y-m');
        $search = trim((string) ($filters['search'] ?? ''));

        return DB::table('payroll_lines as l')->join('payroll_runs as r', 'r.id', '=', 'l.payroll_run_id')->join('employees as e', 'e.id', '=', 'l.employee_id')
            ->where('r.period', $period)->whereIn('r.status', ['approved', 'posted'])
            ->when($companyId !== null, fn ($q) => $q->where('l.company_id', $companyId))
            ->when($branchId !== null, fn ($q) => $q->where('e.branch_id', $branchId))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('e.employee_no', 'like', "%{$search}%")->orWhere('e.first_name', 'like', "%{$search}%")->orWhere('e.last_name', 'like', "%{$search}%")))
            ->orderBy('e.employee_no')
            ->get(['l.id', 'e.employee_no', 'e.first_name', 'e.last_name', 'e.designation', 'l.basic', 'l.gross', 'l.attendance_deduction', 'l.statutory_employee', 'l.statutory_employer', 'l.tax', 'l.loan_deduction', 'l.net', 'r.status'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id, 'employee_no' => $row->employee_no, 'name' => trim($row->first_name.' '.$row->last_name), 'designation' => (string) $row->designation, 'basic' => round((float) $row->basic, 2),
                'gross' => round((float) $row->gross, 2), 'attendance_deduction' => round((float) $row->attendance_deduction, 2), 'statutory_employee' => round((float) $row->statutory_employee, 2),
                'statutory_employer' => round((float) $row->statutory_employer, 2), 'tax' => round((float) $row->tax, 2), 'loan_deduction' => round((float) $row->loan_deduction, 2), 'net' => round((float) $row->net, 2),
                'status' => $row->status,
            ])->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        return [
            'employees' => $rows->count(), 'gross' => round((float) $rows->sum('gross'), 2), 'attendance_deduction' => round((float) $rows->sum('attendance_deduction'), 2),
            'statutory_employee' => round((float) $rows->sum('statutory_employee'), 2), 'statutory_employer' => round((float) $rows->sum('statutory_employer'), 2),
            'tax' => round((float) $rows->sum('tax'), 2), 'loan_deduction' => round((float) $rows->sum('loan_deduction'), 2), 'net' => round((float) $rows->sum('net'), 2),
        ];
    }
}
