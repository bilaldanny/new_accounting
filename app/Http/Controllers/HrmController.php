<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Company;
use App\Services\Hrm\AttendanceService;
use App\Services\Hrm\HrSettings;
use App\Support\Base64Upload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HR master data: the employee master (separate from login users, optionally linked to one), the organisation tree, the document
 * vault with expiry dates, shifts and rosters, leave types, salary components and structures, and the admin-entered statutory rates
 * and tax slabs. Reading needs `/hrm`, every change `/hrm/manage`. The company always comes from the signed-in user (the superadmin
 * names one); every row is read and written inside that company, and a branch user sees only the employees of their branch.
 */
class HrmController extends Controller
{
    use ScopesToCompany;

    private const DOCUMENT_DISK = 'local';

    private const MAX_KILOBYTES = 5120;

    public const EMPLOYMENT_TYPES = ['permanent', 'contract', 'probation', 'intern', 'daily_wage'];

    public const STATUSES = ['active', 'on_leave', 'resigned', 'terminated'];

    public const DOCUMENT_TYPES = ['cnic', 'contract', 'degree', 'passport', 'visa', 'medical', 'licence', 'police_verification', 'other'];

    public function meta(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm');
        $companyId = $this->scopeCompanyId($request);

        return response()->json(['data' => [
            'employment_types' => self::EMPLOYMENT_TYPES, 'statuses' => self::STATUSES, 'document_types' => self::DOCUMENT_TYPES, 'attendance_statuses' => AttendanceService::STATUSES,
            'settings' => app(HrSettings::class)->all($companyId),
            'departments' => DB::table('departments')->where('company_id', $companyId)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'commission_agents' => DB::table('commission_agents')->where('company_id', $companyId)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'shifts' => DB::table('hr_shifts')->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'start_time', 'end_time']),
            'leave_types' => DB::table('leave_types')->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'days_per_year', 'is_paid']),
            'rates_note' => 'Statutory rates and tax slabs are entered here by your accountant. The application ships none: please verify every rate with your accountant (rates accountant se verify karein).',
        ]]);
    }

    // ---------------------------------------------------------------- settings

    public function settings(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm');

        return response()->json(['data' => app(HrSettings::class)->all($this->scopeCompanyId($request))]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/manage');
        $companyId = $this->scopeCompanyId($request);
        $request->validate(['accounts' => 'nullable|array']);

        return response()->json(['message' => 'Successfully Saved', 'data' => app(HrSettings::class)->save($companyId, $request->except(['company_id']))]);
    }

    // ---------------------------------------------------------------- employees

    public function employees(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm');
        $companyId = $this->scopeCompanyId($request);
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', '');

        $rows = DB::table('employees as e')->leftJoin('departments as d', 'd.id', '=', 'e.department_id')->leftJoin('hr_shifts as s', 's.id', '=', 'e.shift_id')
            ->where('e.company_id', $companyId)->whereNull('e.deleted_at')
            ->when($this->visibleBranch() !== null, fn ($q) => $q->where('e.branch_id', $this->visibleBranch()))
            ->when($status !== '', fn ($q) => $q->where('e.status', $status))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('e.employee_no', 'like', "%{$search}%")->orWhere('e.first_name', 'like', "%{$search}%")->orWhere('e.last_name', 'like', "%{$search}%")->orWhere('e.cnic', 'like', "%{$search}%")))
            ->orderBy('e.employee_no')->limit(1000)
            ->get(['e.id', 'e.employee_no', 'e.first_name', 'e.last_name', 'e.designation', 'e.status', 'e.employment_type', 'e.join_date', 'e.exit_date', 'e.phone', 'e.email', 'e.branch_id', 'e.department_id', 'e.manager_id', 'e.shift_id', 'd.name as department', 's.name as shift']);

        return response()->json(['data' => $rows]);
    }

    public function showEmployee(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm');
        $companyId = $this->scopeCompanyId($request);
        $employee = $this->employee($companyId, $id);
        $structure = DB::table('employee_salaries')->where('company_id', $companyId)->where('employee_id', $id)->orderByDesc('effective_from')->get()
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'effective_from' => substr((string) $row->effective_from, 0, 10), 'basic' => (float) $row->basic, 'components' => json_decode((string) $row->components, true) ?: []]);

        return response()->json(['data' => (array) $employee + ['salary_history' => $structure, 'documents' => $this->documentRows($companyId, $id)]]);
    }

    public function saveEmployee(Request $request, ?int $id = null): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/manage');
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate([
            'employee_no' => ['required', 'string', 'max:30', Rule::unique('employees', 'employee_no')->where('company_id', $companyId)->ignore($id)],
            'first_name' => 'required|string|max:80', 'last_name' => 'nullable|string|max:80', 'cnic' => 'nullable|string|max:20', 'gender' => 'nullable|in:male,female,other',
            'date_of_birth' => 'nullable|date', 'phone' => 'nullable|string|max:30', 'email' => 'nullable|email|max:150', 'address' => 'nullable|string|max:255',
            'designation' => 'nullable|string|max:100', 'employment_type' => ['nullable', Rule::in(self::EMPLOYMENT_TYPES)], 'join_date' => 'nullable|date', 'exit_date' => 'nullable|date|after_or_equal:join_date',
            'status' => ['nullable', Rule::in(self::STATUSES)], 'bank_name' => 'nullable|string|max:100', 'bank_account' => 'nullable|string|max:50',
            'branch_id' => 'nullable|integer', 'user_id' => 'nullable|integer', 'commission_agent_id' => 'nullable|integer', 'department_id' => 'nullable|integer', 'manager_id' => 'nullable|integer', 'shift_id' => 'nullable|integer',
            'company_id' => 'nullable|integer',
        ]);

        $own = fn (string $table, ?int $key, string $field, bool $soft = true): ?int => $key === null ? null : (DB::table($table)->where('company_id', $companyId)->where('id', $key)->when($soft, fn ($q) => $q->whereNull('deleted_at'))->exists()
            ? $key : throw ValidationException::withMessages([$field => ['Choose a record of this company.']]));
        $cast = fn (string $key): ?int => isset($data[$key]) && $data[$key] !== '' ? (int) $data[$key] : null;
        $branchId = $this->visibleBranch() ?? $own('branches', $cast('branch_id'), 'branch_id', false);

        if ($id !== null && $cast('manager_id') === $id) {
            throw ValidationException::withMessages(['manager_id' => ['An employee cannot be their own manager.']]);
        }

        $row = [
            'branch_id' => $branchId, 'user_id' => $own('users', $cast('user_id'), 'user_id', false), 'commission_agent_id' => $own('commission_agents', $cast('commission_agent_id'), 'commission_agent_id'),
            'department_id' => $own('departments', $cast('department_id'), 'department_id'), 'manager_id' => $own('employees', $cast('manager_id'), 'manager_id'), 'shift_id' => $own('hr_shifts', $cast('shift_id'), 'shift_id', false),
            'employee_no' => $data['employee_no'], 'first_name' => $data['first_name'], 'last_name' => $data['last_name'] ?? null, 'cnic' => $data['cnic'] ?? null, 'gender' => $data['gender'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null, 'phone' => $data['phone'] ?? null, 'email' => $data['email'] ?? null, 'address' => $data['address'] ?? null, 'designation' => $data['designation'] ?? null,
            'employment_type' => $data['employment_type'] ?? 'permanent', 'join_date' => $data['join_date'] ?? null, 'exit_date' => $data['exit_date'] ?? null, 'status' => $data['status'] ?? 'active',
            'bank_name' => $data['bank_name'] ?? null, 'bank_account' => $data['bank_account'] ?? null, 'updated_at' => now(),
        ];

        if ($id === null) {
            $id = (int) DB::table('employees')->insertGetId($row + ['company_id' => $companyId, 'created_at' => now()]);
        } else {
            $this->employee($companyId, $id);
            DB::table('employees')->where('id', $id)->update($row);
        }

        return response()->json(['message' => 'Successfully Saved', 'data' => ['id' => $id]], 201);
    }

    public function deleteEmployee(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/manage');
        $companyId = $this->scopeCompanyId($request);
        $this->employee($companyId, $id);

        if (DB::table('payroll_lines')->where('company_id', $companyId)->where('employee_id', $id)->exists()) {
            throw ValidationException::withMessages(['employee' => ['This employee has payroll history. Set the status to resigned or terminated instead of deleting.']]);
        }

        DB::table('employees')->where('id', $id)->update(['deleted_at' => now()]);

        return response()->json(['message' => 'Successfully Deleted']);
    }

    /**
     * Departments with their employees, each employee under their manager (employees without a manager in the same department at the top).
     */
    public function orgTree(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm');
        $companyId = $this->scopeCompanyId($request);
        $employees = DB::table('employees')->where('company_id', $companyId)->whereNull('deleted_at')->whereIn('status', ['active', 'on_leave'])
            ->when($this->visibleBranch() !== null, fn ($q) => $q->where('branch_id', $this->visibleBranch()))->orderBy('employee_no')
            ->get(['id', 'employee_no', 'first_name', 'last_name', 'designation', 'department_id', 'manager_id']);
        $build = function (?int $managerId, ?int $departmentId, array $seen) use (&$build, $employees): array {
            $nodes = [];

            foreach ($employees as $employee) {
                $isChild = $employee->manager_id === null ? $managerId === null : (int) $employee->manager_id === $managerId;
                $sameDepartment = $managerId !== null || ($employee->department_id === null ? $departmentId === null : (int) $employee->department_id === $departmentId);

                if (! $isChild || ! $sameDepartment || in_array((int) $employee->id, $seen, true)) {
                    continue;
                }

                $nodes[] = ['id' => (int) $employee->id, 'employee_no' => $employee->employee_no, 'name' => trim($employee->first_name.' '.$employee->last_name), 'designation' => $employee->designation,
                    'reports' => $build((int) $employee->id, $departmentId, [...$seen, (int) $employee->id])];
            }

            return $nodes;
        };

        $departments = DB::table('departments')->where('company_id', $companyId)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']);
        $tree = $departments->map(fn (object $department): array => ['id' => (int) $department->id, 'name' => $department->name, 'people' => $build(null, (int) $department->id, [])])->all();
        $tree[] = ['id' => null, 'name' => 'No department', 'people' => $build(null, null, [])];

        return response()->json(['data' => $tree]);
    }

    // ---------------------------------------------------------------- document vault

    public function documents(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm');
        $companyId = $this->scopeCompanyId($request);
        $this->employee($companyId, $id);

        return response()->json(['data' => $this->documentRows($companyId, $id), 'max_kilobytes' => self::MAX_KILOBYTES]);
    }

    public function uploadDocument(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/manage');
        $companyId = $this->scopeCompanyId($request);
        $this->employee($companyId, $id);
        $data = $request->validate([
            'file' => 'bail|required|file|max:'.self::MAX_KILOBYTES, 'doc_type' => ['required', Rule::in(self::DOCUMENT_TYPES)], 'title' => 'required|string|max:150',
            'issued_on' => 'nullable|date', 'expires_on' => 'nullable|date|after_or_equal:issued_on',
        ]);
        $file = $request->file('file');
        $mime = (string) $file->getMimeType();
        $extension = Base64Upload::extensionForMime($mime) ?? (in_array(strtolower($file->getClientOriginalExtension()), ['doc', 'docx', 'xls', 'xlsx'], true) && in_array($mime, ['application/msword', 'application/zip', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], true) ? strtolower($file->getClientOriginalExtension()) : null);

        if ($extension === null) {
            throw ValidationException::withMessages(['file' => ['This file type is not allowed. Upload a PDF, image, Word or Excel file.']]);
        }

        Company::query()->find($companyId)?->assertCanAddStorageBytes((int) $file->getSize());
        $path = $file->storeAs('employee-documents/'.$companyId.'/'.$id, Str::ulid().'.'.$extension, self::DOCUMENT_DISK);
        $rowId = DB::table('employee_documents')->insertGetId([
            'company_id' => $companyId, 'employee_id' => $id, 'doc_type' => $data['doc_type'], 'title' => $data['title'], 'file_name' => mb_substr(basename($file->getClientOriginalName()), 0, 180),
            'path' => (string) $path, 'ext' => $extension, 'size' => (int) $file->getSize(), 'issued_on' => $data['issued_on'] ?? null, 'expires_on' => $data['expires_on'] ?? null,
            'uploaded_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Document uploaded', 'data' => ['id' => $rowId]], 201);
    }

    public function downloadDocument(Request $request, int $id): StreamedResponse
    {
        $this->authorizeMenuPermission('/hrm');
        $row = $this->documentRow($this->scopeCompanyId($request), $id);
        abort_if(! Storage::disk(self::DOCUMENT_DISK)->exists((string) $row->path), 404);

        return Storage::disk(self::DOCUMENT_DISK)->download((string) $row->path, (string) $row->file_name);
    }

    public function deleteDocument(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/manage');
        $row = $this->documentRow($this->scopeCompanyId($request), $id);
        Storage::disk(self::DOCUMENT_DISK)->delete((string) $row->path);
        DB::table('employee_documents')->where('id', $row->id)->delete();

        return response()->json(['message' => 'Document deleted']);
    }

    /**
     * Documents already expired or expiring within the alert window of the HR settings.
     */
    public function expiring(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm');
        $companyId = $this->scopeCompanyId($request);
        $days = (int) app(HrSettings::class)->all($companyId)['document_alert_days'];

        $rows = DB::table('employee_documents as d')->join('employees as e', 'e.id', '=', 'd.employee_id')->where('d.company_id', $companyId)->whereNull('e.deleted_at')
            ->whereNotNull('d.expires_on')->whereDate('d.expires_on', '<=', now()->addDays($days)->toDateString())
            ->when($this->visibleBranch() !== null, fn ($q) => $q->where('e.branch_id', $this->visibleBranch()))
            ->orderBy('d.expires_on')->get(['d.id', 'd.doc_type', 'd.title', 'd.expires_on', 'e.id as employee_id', 'e.employee_no', 'e.first_name', 'e.last_name'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id, 'employee_id' => (int) $row->employee_id, 'employee_no' => $row->employee_no, 'employee' => trim($row->first_name.' '.$row->last_name), 'doc_type' => $row->doc_type, 'title' => $row->title,
                'expires_on' => substr((string) $row->expires_on, 0, 10), 'days_left' => (int) now()->startOfDay()->diffInDays(Carbon::parse($row->expires_on)->startOfDay(), false),
            ]);

        return response()->json(['data' => $rows, 'alert_days' => $days]);
    }

    // ---------------------------------------------------------------- shifts and roster

    public function shifts(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm');

        return response()->json(['data' => DB::table('hr_shifts')->where('company_id', $this->scopeCompanyId($request))->orderBy('name')->get()]);
    }

    public function saveShift(Request $request, ?int $id = null): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/manage');
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate([
            'name' => 'required|string|max:80', 'start_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'], 'end_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'grace_minutes' => 'nullable|integer|min:0|max:120', 'weekly_off' => ['nullable', 'regex:/^[0-6](,[0-6])*$/'], 'is_active' => 'nullable|boolean', 'company_id' => 'nullable|integer',
        ]);
        $row = [
            'name' => $data['name'], 'start_time' => $data['start_time'], 'end_time' => $data['end_time'], 'grace_minutes' => (int) ($data['grace_minutes'] ?? 10),
            'weekly_off' => $data['weekly_off'] ?? '0', 'is_active' => (bool) ($data['is_active'] ?? true), 'updated_at' => now(),
        ];

        if ($id === null) {
            $id = (int) DB::table('hr_shifts')->insertGetId($row + ['company_id' => $companyId, 'created_at' => now()]);
        } else {
            abort_unless(DB::table('hr_shifts')->where('company_id', $companyId)->where('id', $id)->exists(), 404);
            DB::table('hr_shifts')->where('id', $id)->update($row);
        }

        return response()->json(['message' => 'Successfully Saved', 'data' => ['id' => $id]], 201);
    }

    public function deleteShift(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/manage');
        $companyId = $this->scopeCompanyId($request);
        abort_unless(DB::table('hr_shifts')->where('company_id', $companyId)->where('id', $id)->exists(), 404);

        if (DB::table('employees')->where('company_id', $companyId)->where('shift_id', $id)->whereNull('deleted_at')->exists()) {
            throw ValidationException::withMessages(['shift' => ['Employees use this shift. Move them first, or switch the shift off.']]);
        }

        DB::table('hr_rosters')->where('company_id', $companyId)->where('shift_id', $id)->delete();
        DB::table('hr_shifts')->where('id', $id)->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }

    public function roster(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm');
        $companyId = $this->scopeCompanyId($request);
        $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $from = (string) $request->query('from', now()->startOfWeek()->toDateString());
        $to = (string) $request->query('to', now()->endOfWeek()->toDateString());

        return response()->json(['data' => DB::table('hr_rosters as r')->join('employees as e', 'e.id', '=', 'r.employee_id')->leftJoin('hr_shifts as s', 's.id', '=', 'r.shift_id')
            ->where('r.company_id', $companyId)->whereBetween('r.work_date', [$from, $to])
            ->when($this->visibleBranch() !== null, fn ($q) => $q->where('e.branch_id', $this->visibleBranch()))
            ->orderBy('r.work_date')->orderBy('e.employee_no')->get(['r.id', 'r.employee_id', 'e.employee_no', 'e.first_name', 'e.last_name', 'r.work_date', 'r.shift_id', 's.name as shift', 'r.is_off'])]);
    }

    public function saveRoster(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/manage');
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate(['employee_id' => 'required|integer', 'work_date' => 'required|date_format:Y-m-d', 'shift_id' => 'nullable|integer', 'is_off' => 'nullable|boolean', 'company_id' => 'nullable|integer']);
        $this->employee($companyId, (int) $data['employee_id']);
        $shiftId = isset($data['shift_id']) ? (int) $data['shift_id'] : null;

        if ($shiftId !== null && ! DB::table('hr_shifts')->where('company_id', $companyId)->where('id', $shiftId)->exists()) {
            throw ValidationException::withMessages(['shift_id' => ['Choose a shift of this company.']]);
        }

        DB::table('hr_rosters')->updateOrInsert(
            ['employee_id' => (int) $data['employee_id'], 'work_date' => $data['work_date']],
            ['company_id' => $companyId, 'shift_id' => $shiftId, 'is_off' => (bool) ($data['is_off'] ?? false), 'updated_at' => now(), 'created_at' => now()],
        );

        return response()->json(['message' => 'Successfully Saved']);
    }

    // ---------------------------------------------------------------- leave types

    public function leaveTypes(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm');

        return response()->json(['data' => DB::table('leave_types')->where('company_id', $this->scopeCompanyId($request))->orderBy('name')->get()]);
    }

    public function saveLeaveType(Request $request, ?int $id = null): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/manage');
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate(['name' => 'required|string|max:80', 'days_per_year' => 'nullable|numeric|min:0|max:366', 'is_paid' => 'nullable|boolean', 'is_active' => 'nullable|boolean', 'company_id' => 'nullable|integer']);
        $row = ['name' => $data['name'], 'days_per_year' => round((float) ($data['days_per_year'] ?? 0), 2), 'is_paid' => (bool) ($data['is_paid'] ?? true), 'is_active' => (bool) ($data['is_active'] ?? true), 'updated_at' => now()];

        if ($id === null) {
            $id = (int) DB::table('leave_types')->insertGetId($row + ['company_id' => $companyId, 'created_at' => now()]);
        } else {
            abort_unless(DB::table('leave_types')->where('company_id', $companyId)->where('id', $id)->exists(), 404);
            DB::table('leave_types')->where('id', $id)->update($row);
        }

        return response()->json(['message' => 'Successfully Saved', 'data' => ['id' => $id]], 201);
    }

    // ---------------------------------------------------------------- salary components and structures

    public function components(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm');

        return response()->json(['data' => DB::table('salary_components')->where('company_id', $this->scopeCompanyId($request))->orderBy('type')->orderBy('name')->get()]);
    }

    public function saveComponent(Request $request, ?int $id = null): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/manage');
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate([
            'name' => 'required|string|max:80', 'type' => ['required', Rule::in(['earning', 'deduction'])], 'calc' => ['required', Rule::in(['fixed', 'percent_basic'])],
            'is_taxable' => 'nullable|boolean', 'is_active' => 'nullable|boolean', 'company_id' => 'nullable|integer',
        ]);
        $row = ['name' => $data['name'], 'type' => $data['type'], 'calc' => $data['calc'], 'is_taxable' => (bool) ($data['is_taxable'] ?? true), 'is_active' => (bool) ($data['is_active'] ?? true), 'updated_at' => now()];

        if ($id === null) {
            $id = (int) DB::table('salary_components')->insertGetId($row + ['company_id' => $companyId, 'created_at' => now()]);
        } else {
            abort_unless(DB::table('salary_components')->where('company_id', $companyId)->where('id', $id)->exists(), 404);
            DB::table('salary_components')->where('id', $id)->update($row);
        }

        return response()->json(['message' => 'Successfully Saved', 'data' => ['id' => $id]], 201);
    }

    public function saveSalary(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/manage');
        $companyId = $this->scopeCompanyId($request);
        $this->employee($companyId, $id);
        $data = $request->validate([
            'effective_from' => 'required|date_format:Y-m-d', 'basic' => 'required|numeric|min:0|max:100000000', 'components' => 'nullable|array|max:40',
            'components.*.component_id' => 'required|integer', 'components.*.value' => 'required|numeric|min:0|max:100000000', 'company_id' => 'nullable|integer',
        ]);
        $components = [];

        foreach ((array) ($data['components'] ?? []) as $row) {
            if (! DB::table('salary_components')->where('company_id', $companyId)->where('id', (int) $row['component_id'])->exists()) {
                throw ValidationException::withMessages(['components' => ['A component is not part of this company.']]);
            }

            $components[] = ['component_id' => (int) $row['component_id'], 'value' => round((float) $row['value'], 2)];
        }

        DB::table('employee_salaries')->updateOrInsert(
            ['employee_id' => $id, 'effective_from' => $data['effective_from']],
            ['company_id' => $companyId, 'basic' => round((float) $data['basic'], 2), 'components' => json_encode($components), 'created_by' => Auth::id(), 'updated_at' => now(), 'created_at' => now()],
        );

        return response()->json(['message' => 'Successfully Saved']);
    }

    // ---------------------------------------------------------------- statutory rates and tax slabs (admin entered)

    public function rates(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm');
        $companyId = $this->scopeCompanyId($request);

        return response()->json([
            'data' => DB::table('statutory_rates')->where('company_id', $companyId)->orderByDesc('effective_from')->orderBy('code')->get(),
            'slabs' => DB::table('tax_slabs')->where('company_id', $companyId)->orderByDesc('effective_from')->orderBy('annual_from')->get(),
            'note' => 'Rates accountant se verify karein: the application ships no statutory rate and no tax slab, only what you enter here applies.',
        ]);
    }

    public function saveRate(Request $request, ?int $id = null): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/manage');
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate([
            'code' => 'required|string|max:30', 'name' => 'required|string|max:100', 'side' => ['required', Rule::in(['employee', 'employer'])], 'basis' => ['required', Rule::in(['basic', 'gross'])],
            'rate_percent' => 'required|numeric|min:0|max:100', 'fixed_amount' => 'nullable|numeric|min:0|max:100000000', 'base_cap' => 'nullable|numeric|min:0|max:1000000000',
            'effective_from' => 'required|date_format:Y-m-d', 'is_active' => 'nullable|boolean', 'company_id' => 'nullable|integer',
        ]);
        $row = [
            'code' => strtoupper($data['code']), 'name' => $data['name'], 'side' => $data['side'], 'basis' => $data['basis'], 'rate_percent' => round((float) $data['rate_percent'], 4),
            'fixed_amount' => round((float) ($data['fixed_amount'] ?? 0), 2), 'base_cap' => isset($data['base_cap']) ? round((float) $data['base_cap'], 2) : null,
            'effective_from' => $data['effective_from'], 'is_active' => (bool) ($data['is_active'] ?? true), 'updated_at' => now(),
        ];

        if ($id === null) {
            $id = (int) DB::table('statutory_rates')->insertGetId($row + ['company_id' => $companyId, 'created_at' => now()]);
        } else {
            abort_unless(DB::table('statutory_rates')->where('company_id', $companyId)->where('id', $id)->exists(), 404);
            DB::table('statutory_rates')->where('id', $id)->update($row);
        }

        return response()->json(['message' => 'Successfully Saved', 'data' => ['id' => $id]], 201);
    }

    public function deleteRate(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/manage');
        $affected = DB::table('statutory_rates')->where('company_id', $this->scopeCompanyId($request))->where('id', $id)->delete();
        abort_if($affected === 0, 404);

        return response()->json(['message' => 'Successfully Deleted']);
    }

    public function saveSlab(Request $request, ?int $id = null): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/manage');
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate([
            'effective_from' => 'required|date_format:Y-m-d', 'annual_from' => 'required|numeric|min:0', 'annual_to' => 'nullable|numeric|gt:annual_from',
            'fixed_amount' => 'nullable|numeric|min:0', 'rate_percent' => 'required|numeric|min:0|max:100', 'company_id' => 'nullable|integer',
        ]);
        $row = [
            'effective_from' => $data['effective_from'], 'annual_from' => round((float) $data['annual_from'], 2), 'annual_to' => isset($data['annual_to']) ? round((float) $data['annual_to'], 2) : null,
            'fixed_amount' => round((float) ($data['fixed_amount'] ?? 0), 2), 'rate_percent' => round((float) $data['rate_percent'], 4), 'updated_at' => now(),
        ];

        if ($id === null) {
            $id = (int) DB::table('tax_slabs')->insertGetId($row + ['company_id' => $companyId, 'created_at' => now()]);
        } else {
            abort_unless(DB::table('tax_slabs')->where('company_id', $companyId)->where('id', $id)->exists(), 404);
            DB::table('tax_slabs')->where('id', $id)->update($row);
        }

        return response()->json(['message' => 'Successfully Saved', 'data' => ['id' => $id]], 201);
    }

    public function deleteSlab(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/hrm/manage');
        $affected = DB::table('tax_slabs')->where('company_id', $this->scopeCompanyId($request))->where('id', $id)->delete();
        abort_if($affected === 0, 404);

        return response()->json(['message' => 'Successfully Deleted']);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The branch a branch user is limited to; a company admin and the superadmin see every branch.
     */
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

    private function documentRow(int $companyId, int $id): object
    {
        $row = DB::table('employee_documents')->where('company_id', $companyId)->where('id', $id)->first();
        abort_if($row === null, 404);
        $this->employee($companyId, (int) $row->employee_id);

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function documentRows(int $companyId, int $employeeId): array
    {
        return DB::table('employee_documents')->where('company_id', $companyId)->where('employee_id', $employeeId)->orderBy('expires_on')->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id, 'doc_type' => $row->doc_type, 'title' => $row->title, 'file_name' => $row->file_name, 'ext' => $row->ext, 'size' => (int) $row->size,
                'issued_on' => $row->issued_on === null ? null : substr((string) $row->issued_on, 0, 10), 'expires_on' => $row->expires_on === null ? null : substr((string) $row->expires_on, 0, 10),
            ])->all();
    }
}
