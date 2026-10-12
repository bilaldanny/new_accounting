<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Services\AppNotifier;
use App\Services\Hrm\AttendanceService;
use App\Services\Hrm\LeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Attendance, leave and the time clock devices. Manual entry, CSV import and device punches all end in the same `hr_attendance` row
 * (a manual row is never overwritten by a device or a file). A device authenticates with its own token (stored only as a hash) and
 * can do nothing but add punches to the company the token belongs to. Leave is requested with `/hrm/attendance`, decided with
 * `/hrm/leave/approve` (never by the employee's own login), and an approved leave writes its attendance rows.
 */
class HrmAttendanceController extends Controller
{
    use ScopesToCompany;

    public function __construct(private readonly AttendanceService $attendance, private readonly LeaveService $leave) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/attendance');
        $companyId = $this->scopeCompanyId($request);
        $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d', 'employee_id' => 'nullable|integer']);
        $from = (string) $request->query('from', now()->startOfMonth()->toDateString());
        $to = (string) $request->query('to', now()->toDateString());

        $rows = DB::table('hr_attendance as a')->join('employees as e', 'e.id', '=', 'a.employee_id')->where('a.company_id', $companyId)->whereBetween('a.work_date', [$from, $to])
            ->when($request->filled('employee_id'), fn ($q) => $q->where('a.employee_id', (int) $request->query('employee_id')))
            ->when($this->visibleBranch() !== null, fn ($q) => $q->where('e.branch_id', $this->visibleBranch()))
            ->orderByDesc('a.work_date')->orderBy('e.employee_no')->limit(2000)
            ->get(['a.id', 'a.employee_id', 'e.employee_no', 'e.first_name', 'e.last_name', 'a.work_date', 'a.check_in', 'a.check_out', 'a.status', 'a.worked_minutes', 'a.late_minutes', 'a.early_minutes', 'a.overtime_minutes', 'a.source', 'a.note']);

        return response()->json(['data' => $rows]);
    }

    public function record(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/attendance');
        $companyId = $this->scopeCompanyId($request);
        $time = ['nullable', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'];
        $data = $request->validate([
            'employee_id' => 'required|integer', 'work_date' => 'required|date_format:Y-m-d', 'check_in' => $time, 'check_out' => $time,
            'status' => ['nullable', Rule::in(AttendanceService::STATUSES)], 'note' => 'nullable|string|max:200', 'company_id' => 'nullable|integer',
        ]);
        $this->employee($companyId, (int) $data['employee_id']);

        $result = $this->attendance->record($companyId, (int) $data['employee_id'], $data['work_date'], $data['check_in'] ?? null, $data['check_out'] ?? null, $data['status'] ?? 'present', 'manual', $data['note'] ?? null);

        return response()->json(['message' => 'Successfully Saved', 'data' => $result], 201);
    }

    public function import(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/attendance');
        $companyId = $this->scopeCompanyId($request);
        $request->validate(['file' => 'nullable|file|max:2048', 'csv' => 'nullable|string|max:400000', 'company_id' => 'nullable|integer']);
        $csv = $request->hasFile('file') ? (string) file_get_contents($request->file('file')->getRealPath()) : (string) $request->input('csv', '');

        if (trim($csv) === '') {
            throw ValidationException::withMessages(['file' => ['Send a CSV file or its text: employee_no,date,check_in,check_out.']]);
        }

        return response()->json(['data' => $this->attendance->importCsv($companyId, $csv)]);
    }

    public function processPunches(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/attendance');

        return response()->json(['data' => $this->attendance->processPunches($this->scopeCompanyId($request))]);
    }

    // ---------------------------------------------------------------- device tokens and the punch endpoint

    public function devices(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/manage');

        return response()->json(['data' => DB::table('hr_device_tokens')->where('company_id', $this->scopeCompanyId($request))->orderBy('name')->get(['id', 'name', 'is_active', 'last_used_at', 'created_at'])]);
    }

    /**
     * Creates a device token and returns it once; only its hash is stored.
     */
    public function createDevice(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/manage');
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate(['name' => 'required|string|max:80', 'company_id' => 'nullable|integer']);
        $token = Str::random(48);
        $id = DB::table('hr_device_tokens')->insertGetId(['company_id' => $companyId, 'name' => $data['name'], 'token_hash' => hash('sha256', $token), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Copy the token now: it is not shown again.', 'data' => ['id' => $id, 'token' => $token]], 201);
    }

    public function deleteDevice(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/manage');
        $affected = DB::table('hr_device_tokens')->where('company_id', $this->scopeCompanyId($request))->where('id', $id)->delete();
        abort_if($affected === 0, 404);

        return response()->json(['message' => 'Successfully Deleted']);
    }

    /**
     * The attendance device endpoint (no login): `X-Device-Token` header, a list of `{employee_no, punched_at}`. The company is the
     * token's, never the body's; a replayed punch is ignored.
     */
    public function devicePunch(Request $request): JsonResponse
    {
        $token = (string) ($request->header('X-Device-Token') ?: $request->input('token', ''));
        $device = $token === '' ? null : DB::table('hr_device_tokens')->where('token_hash', hash('sha256', $token))->where('is_active', true)->first();

        if ($device === null) {
            return response()->json(['message' => 'Unknown or switched-off device token.'], 401);
        }

        $data = $request->validate(['punches' => 'required|array|min:1|max:500', 'punches.*.employee_no' => 'required|string|max:30', 'punches.*.punched_at' => 'required|date']);
        $saved = 0;

        foreach ($data['punches'] as $punch) {
            $at = Carbon::parse($punch['punched_at'])->format('Y-m-d H:i:00');
            $inserted = DB::table('hr_device_punches')->insertOrIgnore([
                'company_id' => $device->company_id, 'device_token_id' => $device->id, 'employee_no' => $punch['employee_no'], 'punched_at' => $at, 'matched' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $saved += $inserted;
        }

        DB::table('hr_device_tokens')->where('id', $device->id)->update(['last_used_at' => now()]);
        $processed = $this->attendance->processPunches((int) $device->company_id);

        return response()->json(['data' => ['received' => count($data['punches']), 'new' => $saved] + $processed]);
    }

    // ---------------------------------------------------------------- leave

    public function leaveRequests(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/attendance');
        $companyId = $this->scopeCompanyId($request);
        $status = (string) $request->query('status', '');

        $rows = DB::table('leave_requests as l')->join('employees as e', 'e.id', '=', 'l.employee_id')->join('leave_types as t', 't.id', '=', 'l.leave_type_id')
            ->where('l.company_id', $companyId)
            ->when($status !== '', fn ($q) => $q->where('l.status', $status))
            ->when($this->visibleBranch() !== null, fn ($q) => $q->where('e.branch_id', $this->visibleBranch()))
            ->orderByDesc('l.id')->limit(500)
            ->get(['l.id', 'l.employee_id', 'e.employee_no', 'e.first_name', 'e.last_name', 't.name as leave_type', 'l.from_date', 'l.to_date', 'l.days', 'l.reason', 'l.status', 'l.decision_note', 'l.created_at']);

        return response()->json(['data' => $rows]);
    }

    public function requestLeave(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/attendance');
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate([
            'employee_id' => 'required|integer', 'leave_type_id' => 'required|integer', 'from_date' => 'required|date_format:Y-m-d', 'to_date' => 'required|date_format:Y-m-d',
            'reason' => 'nullable|string|max:300', 'company_id' => 'nullable|integer',
        ]);
        $employee = $this->employee($companyId, (int) $data['employee_id']);
        $id = $this->leave->request($companyId, $employee->branch_id === null ? null : (int) $employee->branch_id, (int) $employee->id, (int) $data['leave_type_id'], $data['from_date'], $data['to_date'], $data['reason'] ?? null, Auth::id());

        return response()->json(['message' => 'Leave requested', 'data' => ['id' => $id]], 201);
    }

    public function balance(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/attendance');
        $companyId = $this->scopeCompanyId($request);
        $request->validate(['employee_id' => 'required|integer', 'year' => 'nullable|integer|min:2000|max:2100']);
        $this->employee($companyId, (int) $request->query('employee_id'));
        $year = $request->filled('year') ? (int) $request->query('year') : null;

        $types = DB::table('leave_types')->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get();

        return response()->json(['data' => $types->map(fn (object $type): array => ['leave_type_id' => (int) $type->id, 'name' => $type->name, 'is_paid' => (bool) $type->is_paid]
            + $this->leave->balance($companyId, (int) $request->query('employee_id'), (int) $type->id, $year))->all()]);
    }

    public function cancelLeave(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/attendance');
        $companyId = $this->scopeCompanyId($request);
        $row = DB::table('leave_requests')->where('company_id', $companyId)->where('id', $id)->first();
        abort_if($row === null, 404);
        $this->employee($companyId, (int) $row->employee_id);
        $this->leave->cancel($companyId, $id);

        return response()->json(['message' => 'Leave cancelled']);
    }

    public function approveLeave(Request $request, int $id): JsonResponse
    {
        return $this->decide($request, $id, true);
    }

    public function rejectLeave(Request $request, int $id): JsonResponse
    {
        return $this->decide($request, $id, false);
    }

    private function decide(Request $request, int $id, bool $approve): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/leave/approve');
        $companyId = $this->scopeCompanyId($request);
        $row = DB::table('leave_requests')->where('company_id', $companyId)->where('id', $id)->first();
        abort_if($row === null, 404);
        $employee = $this->employee($companyId, (int) $row->employee_id);

        if ($employee->user_id !== null && (int) $employee->user_id === (int) Auth::id()) {
            throw ValidationException::withMessages(['status' => ['You cannot decide your own leave.']]);
        }

        $request->validate(['note' => 'nullable|string|max:300']);
        $this->leave->decide($companyId, $id, $approve, Auth::id(), $request->input('note'));

        if ($row->created_by !== null) {
            app(AppNotifier::class)->notify((int) $row->created_by, 'leave', $approve ? 'Leave approved' : 'Leave rejected', 'Leave from '.substr((string) $row->from_date, 0, 10).' to '.substr((string) $row->to_date, 0, 10).' was '.($approve ? 'approved' : 'rejected').'.', '/hrm');
        }

        return response()->json(['message' => $approve ? 'Leave approved' : 'Leave rejected']);
    }

    // ---------------------------------------------------------------- helpers

    private function visibleBranch(): ?int
    {
        $user = Auth::user();

        return $user?->branch_id && ! $user->hasRole('companyadmin') && ! $user->hasRole('superadmin') ? (int) $user->branch_id : null;
    }

    private function employee(int $companyId, int $id): object
    {
        $employee = DB::table('employees')->where('company_id', $companyId)->where('id', $id)->whereNull('deleted_at')
            ->when($this->visibleBranch() !== null, fn ($q) => $q->where('branch_id', $this->visibleBranch()))->first();
        abort_if($employee === null, 404);

        return $employee;
    }
}
