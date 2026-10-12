<?php

namespace App\Services\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Attendance of the range per employee: days present, absent, on paid and unpaid leave, minutes late and early and overtime minutes.
 * Defaults to the last 30 days. Only employees of the company (and branch) in scope.
 */
class AttendanceSummaryReport
{
    public const SORTABLE = ['employee_no' => 'employee_no', 'name' => 'name', 'present' => 'present', 'absent' => 'absent', 'late_minutes' => 'late_minutes', 'overtime_minutes' => 'overtime_minutes'];

    public const DEFAULT_SORT = 'employee_no';

    public const DEFAULT_DESC = false;

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        [$from, $to] = ReportDates::range($filters, 30);
        $search = trim((string) ($filters['search'] ?? ''));

        return DB::table('hr_attendance as a')->join('employees as e', 'e.id', '=', 'a.employee_id')->whereNull('e.deleted_at')->whereBetween('a.work_date', [$from, $to])
            ->when($companyId !== null, fn ($q) => $q->where('a.company_id', $companyId))
            ->when($branchId !== null, fn ($q) => $q->where('e.branch_id', $branchId))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('e.employee_no', 'like', "%{$search}%")->orWhere('e.first_name', 'like', "%{$search}%")->orWhere('e.last_name', 'like', "%{$search}%")))
            ->groupBy('e.id', 'e.employee_no', 'e.first_name', 'e.last_name')->orderBy('e.employee_no')
            ->selectRaw("e.id, e.employee_no, e.first_name, e.last_name, sum(case when a.status = 'present' then 1 else 0 end) as present, sum(case when a.status = 'absent' then 1 else 0 end) as absent,
                sum(case when a.status = 'leave' then 1 else 0 end) as leave_days, sum(case when a.status = 'unpaid_leave' then 1 else 0 end) as unpaid_leave, coalesce(sum(a.late_minutes), 0) as late_minutes,
                coalesce(sum(a.early_minutes), 0) as early_minutes, coalesce(sum(a.overtime_minutes), 0) as overtime_minutes")
            ->get()->map(fn (object $row): array => [
                'id' => (int) $row->id, 'employee_no' => $row->employee_no, 'name' => trim($row->first_name.' '.$row->last_name), 'present' => (int) $row->present, 'absent' => (int) $row->absent,
                'leave_days' => (int) $row->leave_days, 'unpaid_leave' => (int) $row->unpaid_leave, 'late_minutes' => (int) $row->late_minutes, 'early_minutes' => (int) $row->early_minutes, 'overtime_minutes' => (int) $row->overtime_minutes,
            ])->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    public function summary(Collection $rows): array
    {
        return [
            'employees' => $rows->count(), 'present' => (int) $rows->sum('present'), 'absent' => (int) $rows->sum('absent'), 'leave_days' => (int) $rows->sum('leave_days'),
            'unpaid_leave' => (int) $rows->sum('unpaid_leave'), 'late_minutes' => (int) $rows->sum('late_minutes'), 'overtime_minutes' => (int) $rows->sum('overtime_minutes'),
        ];
    }
}
