<?php

namespace App\Services\Hrm;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writing attendance: one row per employee per day, minutes late / early / overtime worked out from the shift of the day (the
 * roster entry, else the employee's own shift) and the company's HR settings. A manual entry is never overwritten by a device or
 * file import; imports and device punches fill days that have no manual row. Leave approval writes `leave` / `unpaid_leave` rows.
 */
class AttendanceService
{
    public const STATUSES = ['present', 'absent', 'leave', 'unpaid_leave', 'holiday', 'weekly_off'];

    public function __construct(private readonly HrSettings $settings) {}

    /**
     * @return array{id: int, status: string, late_minutes: int, early_minutes: int, overtime_minutes: int}
     */
    public function record(int $companyId, int $employeeId, string $date, ?string $checkIn, ?string $checkOut, string $status = 'present', string $source = 'manual', ?string $note = null): array
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => ['Unknown attendance status.']]);
        }

        $employee = DB::table('employees')->where('company_id', $companyId)->where('id', $employeeId)->whereNull('deleted_at')->first();
        abort_if($employee === null, 404);

        $calc = ['worked' => 0, 'late' => 0, 'early' => 0, 'overtime' => 0];

        if ($status === 'present' && $checkIn !== null) {
            $calc = AttendanceCalculator::compute($this->shiftFor($companyId, $employee, $date), $checkIn, $checkOut, $this->settings->all($companyId));
        }

        $existing = DB::table('hr_attendance')->where('employee_id', $employeeId)->where('work_date', $date)->first();

        if ($existing !== null && $existing->source === 'manual' && $source !== 'manual') {
            return ['id' => (int) $existing->id, 'status' => (string) $existing->status, 'late_minutes' => (int) $existing->late_minutes, 'early_minutes' => (int) $existing->early_minutes, 'overtime_minutes' => (int) $existing->overtime_minutes];
        }

        $row = [
            'company_id' => $companyId, 'check_in' => $checkIn, 'check_out' => $checkOut, 'status' => $status, 'worked_minutes' => $calc['worked'], 'late_minutes' => $calc['late'],
            'early_minutes' => $calc['early'], 'overtime_minutes' => $calc['overtime'], 'source' => $source, 'note' => $note !== null ? mb_substr($note, 0, 200) : null, 'updated_at' => now(),
        ];

        DB::table('hr_attendance')->updateOrInsert(['employee_id' => $employeeId, 'work_date' => $date], $row + ['created_at' => now()]);

        return ['id' => (int) DB::table('hr_attendance')->where('employee_id', $employeeId)->where('work_date', $date)->value('id'), 'status' => $status, 'late_minutes' => $calc['late'], 'early_minutes' => $calc['early'], 'overtime_minutes' => $calc['overtime']];
    }

    /**
     * Imports `employee_no,date,check_in,check_out` rows (CSV text). A row with an unknown employee or a bad date or time is
     * reported and skipped; the rest are saved.
     *
     * @return array{saved: int, errors: list<string>}
     */
    public function importCsv(int $companyId, string $csv): array
    {
        $saved = 0;
        $errors = [];
        $employees = DB::table('employees')->where('company_id', $companyId)->whereNull('deleted_at')->pluck('id', 'employee_no');
        $lines = preg_split('/\r\n|\n|\r/', trim(preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv)) ?: [];

        foreach ($lines as $index => $line) {
            $cells = array_map('trim', str_getcsv($line, ',', '"', ''));

            if ($index === 0 && strtolower($cells[0] ?? '') === 'employee_no') {
                continue;
            }

            if (count($lines) > 2000 && $index > 2000) {
                $errors[] = 'Only the first 2000 rows are read.';

                break;
            }

            [$no, $date, $in, $out] = array_pad($cells, 4, '');
            $row = $index + 1;

            if (! isset($employees[$no])) {
                $errors[] = "Row {$row}: employee {$no} was not found.";

                continue;
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || strtotime($date) === false) {
                $errors[] = "Row {$row}: the date must be YYYY-MM-DD.";

                continue;
            }

            foreach ([$in, $out] as $time) {
                if ($time !== '' && AttendanceCalculator::minutes($time) === null) {
                    $errors[] = "Row {$row}: times must be HH:MM.";

                    continue 2;
                }
            }

            $this->record($companyId, (int) $employees[$no], $date, $in !== '' ? $in : null, $out !== '' ? $out : null, $in !== '' ? 'present' : 'absent', 'file');
            $saved++;
        }

        return ['saved' => $saved, 'errors' => $errors];
    }

    /**
     * Turns the raw device punches of a company that are not matched yet into attendance (first punch of the day is the check-in,
     * the last the check-out; a single punch is a check-in).
     *
     * @return array{days: int, unmatched: int}
     */
    public function processPunches(int $companyId): array
    {
        $employees = DB::table('employees')->where('company_id', $companyId)->whereNull('deleted_at')->pluck('id', 'employee_no');
        $punches = DB::table('hr_device_punches')->where('company_id', $companyId)->where('matched', false)->orderBy('punched_at')->get();
        $days = 0;
        $unmatched = 0;

        foreach ($punches->groupBy(fn (object $p): string => $p->employee_no.'|'.substr((string) $p->punched_at, 0, 10)) as $key => $group) {
            [$no, $date] = explode('|', $key);

            if (! isset($employees[$no])) {
                $unmatched += $group->count();

                continue;
            }

            $times = $group->pluck('punched_at')->map(fn ($t): string => Carbon::parse($t)->format('H:i'))->sort()->values();
            $this->record($companyId, (int) $employees[$no], $date, $times->first(), $times->count() > 1 ? $times->last() : null, 'present', 'device');
            DB::table('hr_device_punches')->whereIn('id', $group->pluck('id')->all())->update(['matched' => true, 'updated_at' => now()]);
            $days++;
        }

        return ['days' => $days, 'unmatched' => $unmatched];
    }

    /**
     * @return array{start_time: string, end_time: string, grace_minutes: int}|null
     */
    private function shiftFor(int $companyId, object $employee, string $date): ?array
    {
        $shiftId = DB::table('hr_rosters')->where('company_id', $companyId)->where('employee_id', $employee->id)->where('work_date', $date)->value('shift_id') ?: $employee->shift_id;

        if ($shiftId === null) {
            return null;
        }

        $shift = DB::table('hr_shifts')->where('company_id', $companyId)->where('id', $shiftId)->first(['start_time', 'end_time', 'grace_minutes']);

        return $shift === null ? null : (array) $shift;
    }
}
