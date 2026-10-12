<?php

namespace App\Services\Hrm;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Leave: counting the days a request covers (the employee's weekly off days are not counted), the balance of a leave type for the
 * calendar year (entitlement less approved days, pending requests shown separately), and the effect of a decision. An approved
 * leave writes `leave` (paid) or `unpaid_leave` attendance rows for its working days, which payroll then reads; rejecting or
 * cancelling an approved leave removes those rows again (only the leave rows, never a present day).
 */
class LeaveService
{
    public function __construct(private readonly AttendanceService $attendance) {}

    /**
     * Working days between two dates inclusive, skipping the weekly off days of the employee's shift (Sunday when it has none).
     *
     * @return list<string> the dates
     */
    public function workingDates(int $companyId, int $employeeId, string $from, string $to): array
    {
        $shift = DB::table('employees as e')->leftJoin('hr_shifts as s', 's.id', '=', 'e.shift_id')->where('e.company_id', $companyId)->where('e.id', $employeeId)->first(['s.weekly_off']);
        $off = array_map('intval', array_filter(explode(',', (string) ($shift->weekly_off ?? '0')), fn (string $d): bool => $d !== ''));
        $dates = [];

        for ($day = Carbon::parse($from); $day->lte(Carbon::parse($to)) && count($dates) < 400; $day->addDay()) {
            if (! in_array($day->dayOfWeek, $off, true)) {
                $dates[] = $day->toDateString();
            }
        }

        return $dates;
    }

    /**
     * @return array{entitlement: float, taken: float, pending: float, balance: float}
     */
    public function balance(int $companyId, int $employeeId, int $leaveTypeId, ?int $year = null): array
    {
        $year ??= (int) now()->format('Y');
        $type = DB::table('leave_types')->where('company_id', $companyId)->where('id', $leaveTypeId)->first();
        abort_if($type === null, 404);

        $sum = fn (string $status): float => (float) DB::table('leave_requests')->where('company_id', $companyId)->where('employee_id', $employeeId)->where('leave_type_id', $leaveTypeId)
            ->where('status', $status)->whereYear('from_date', $year)->sum('days');
        $taken = $sum('approved');
        $pending = $sum('pending');

        return ['entitlement' => (float) $type->days_per_year, 'taken' => $taken, 'pending' => $pending, 'balance' => round((float) $type->days_per_year - $taken - $pending, 2)];
    }

    /**
     * @throws ValidationException
     */
    public function request(int $companyId, ?int $branchId, int $employeeId, int $leaveTypeId, string $from, string $to, ?string $reason, ?int $userId): int
    {
        if ($to < $from) {
            throw ValidationException::withMessages(['to_date' => ['The end date is before the start date.']]);
        }

        $type = DB::table('leave_types')->where('company_id', $companyId)->where('id', $leaveTypeId)->where('is_active', true)->first();

        if ($type === null || ! DB::table('employees')->where('company_id', $companyId)->where('id', $employeeId)->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages(['leave_type_id' => ['Choose an active leave type and an employee of this company.']]);
        }

        $dates = $this->workingDates($companyId, $employeeId, $from, $to);

        if ($dates === []) {
            throw ValidationException::withMessages(['from_date' => ['These dates fall on weekly off days only.']]);
        }

        if (DB::table('leave_requests')->where('company_id', $companyId)->where('employee_id', $employeeId)->whereIn('status', ['pending', 'approved'])->whereDate('from_date', '<=', $to)->whereDate('to_date', '>=', $from)->exists()) {
            throw ValidationException::withMessages(['from_date' => ['This employee already has leave that overlaps these dates.']]);
        }

        $balance = $this->balance($companyId, $employeeId, $leaveTypeId, (int) substr($from, 0, 4));

        if ((float) $type->days_per_year > 0 && count($dates) > $balance['balance']) {
            throw ValidationException::withMessages(['days' => ['Only '.$balance['balance'].' day(s) of '.$type->name.' are left this year.']]);
        }

        return (int) DB::table('leave_requests')->insertGetId([
            'company_id' => $companyId, 'branch_id' => $branchId, 'employee_id' => $employeeId, 'leave_type_id' => $leaveTypeId, 'from_date' => $from, 'to_date' => $to, 'days' => count($dates),
            'reason' => $reason !== null ? mb_substr(strip_tags($reason), 0, 300) : null, 'status' => 'pending', 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Records a decision and, for an approval, the attendance rows.
     */
    public function decide(int $companyId, int $requestId, bool $approve, ?int $userId, ?string $note): void
    {
        $request = DB::table('leave_requests')->where('company_id', $companyId)->where('id', $requestId)->first();
        abort_if($request === null, 404);

        if ($request->status !== 'pending') {
            throw ValidationException::withMessages(['status' => ['This request was already decided.']]);
        }

        DB::transaction(function () use ($request, $approve, $userId, $note, $companyId): void {
            DB::table('leave_requests')->where('id', $request->id)->update([
                'status' => $approve ? 'approved' : 'rejected', 'decided_by' => $userId, 'decided_at' => now(), 'decision_note' => $note !== null ? mb_substr(strip_tags($note), 0, 300) : null, 'updated_at' => now(),
            ]);

            if (! $approve) {
                return;
            }

            $paid = (bool) DB::table('leave_types')->where('id', $request->leave_type_id)->value('is_paid');

            foreach ($this->workingDates($companyId, (int) $request->employee_id, (string) $request->from_date, (string) $request->to_date) as $date) {
                $this->attendance->record($companyId, (int) $request->employee_id, $date, null, null, $paid ? 'leave' : 'unpaid_leave', 'leave', 'Leave request '.$request->id);
            }
        });
    }

    public function cancel(int $companyId, int $requestId): void
    {
        $request = DB::table('leave_requests')->where('company_id', $companyId)->where('id', $requestId)->first();
        abort_if($request === null, 404);

        if (! in_array($request->status, ['pending', 'approved'], true)) {
            throw ValidationException::withMessages(['status' => ['This request cannot be cancelled.']]);
        }

        DB::transaction(function () use ($request): void {
            if ($request->status === 'approved') {
                DB::table('hr_attendance')->where('company_id', $request->company_id)->where('employee_id', $request->employee_id)->where('source', 'leave')
                    ->whereBetween('work_date', [(string) $request->from_date, (string) $request->to_date])->whereIn('status', ['leave', 'unpaid_leave'])->delete();
            }

            DB::table('leave_requests')->where('id', $request->id)->update(['status' => 'cancelled', 'updated_at' => now()]);
        });
    }
}
