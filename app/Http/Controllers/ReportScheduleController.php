<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Services\Reports\ReportScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Scheduled and emailed reports (see ReportScheduler). A schedule belongs to the person who made it and to their company and branch
 * scope, which are taken from the signed-in user here and never from the request. To schedule a standard report the user must be
 * allowed to open it; a custom report must be their own or shared with the company.
 */
class ReportScheduleController extends Controller
{
    use ScopesToCompany;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/reportbuilder');
        $companyId = $this->scopeCompanyId($request);
        $scheduler = app(ReportScheduler::class);

        return response()->json([
            'data' => DB::table('report_schedules')->where('company_id', $companyId)
                ->when(! $this->isAdmin(), fn ($q) => $q->where('user_id', Auth::id()))
                ->orderBy('name')->get()->map(fn (object $row): array => $this->present($row))->all(),
            'reports' => collect($scheduler->available())->filter(fn (array $report): bool => hasMenuPermission($report[1]))
                ->map(fn (array $report, string $key): array => ['key' => $key, 'title' => $report[0]])->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/reportbuilder/manage');
        $data = $this->validated($request);
        $companyId = $this->scopeCompanyId($request);
        $scheduler = app(ReportScheduler::class);

        if ($data['report_type'] === 'custom') {
            $report = DB::table('custom_reports')->where('company_id', $companyId)->whereNull('deleted_at')->where('id', $data['custom_report_id'] ?? 0)
                ->where(fn ($q) => $q->where('is_shared', true)->orWhere('user_id', Auth::id()))->first();

            if ($report === null) {
                throw ValidationException::withMessages(['custom_report_id' => ['Choose one of your own or a shared custom report.']]);
            }
        } else {
            $available = $scheduler->available();

            if (! isset($available[$data['report_key'] ?? '']) || ! hasMenuPermission($available[$data['report_key']][1])) {
                throw ValidationException::withMessages(['report_key' => ['Choose a report you are allowed to open.']]);
            }
        }

        $user = Auth::user();
        $branchId = $user->branch_id && ! $user->hasRole('superadmin') && ! $user->hasRole('companyadmin') ? (int) $user->branch_id : null;

        $id = DB::table('report_schedules')->insertGetId([
            'company_id' => $companyId, 'branch_id' => $branchId, 'user_id' => $user->id, 'name' => $data['name'], 'report_type' => $data['report_type'],
            'report_key' => $data['report_type'] === 'standard' ? $data['report_key'] : null,
            'custom_report_id' => $data['report_type'] === 'custom' ? $data['custom_report_id'] : null,
            'filters' => json_encode($data['filters'] ?? []), 'frequency' => $data['frequency'], 'weekday' => $data['frequency'] === 'weekly' ? ($data['weekday'] ?? 1) : null,
            'day_of_month' => $data['frequency'] === 'monthly' ? ($data['day_of_month'] ?? 1) : null,
            'recipients' => json_encode(array_values(array_unique($data['recipients']))), 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Successfully Saved', 'data' => $this->present(DB::table('report_schedules')->find($id))], 201);
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/reportbuilder/manage');
        $schedule = $this->own($request, $id);
        $data = $request->validate(['is_active' => 'required|boolean']);

        DB::table('report_schedules')->where('id', $schedule->id)->update(['is_active' => $data['is_active'], 'updated_at' => now()]);

        return response()->json(['message' => 'Successfully Updated']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/reportbuilder/manage');
        $schedule = $this->own($request, $id);

        DB::table('report_schedule_runs')->where('report_schedule_id', $schedule->id)->delete();
        DB::table('report_schedules')->where('id', $schedule->id)->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }

    public function runNow(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/reportbuilder/manage');
        $schedule = $this->own($request, $id);

        return response()->json(['data' => app(ReportScheduler::class)->run($schedule)]);
    }

    public function runs(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/reportbuilder');
        $schedule = $this->own($request, $id);

        return response()->json(['data' => DB::table('report_schedule_runs')->where('report_schedule_id', $schedule->id)->orderByDesc('id')->limit(30)->get()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|min:2|max:120',
            'report_type' => ['required', Rule::in(['standard', 'custom'])],
            'report_key' => 'nullable|string|max:80',
            'custom_report_id' => 'nullable|integer',
            'filters' => 'nullable|array',
            'frequency' => ['required', Rule::in(ReportScheduler::FREQUENCIES)],
            'weekday' => 'nullable|integer|min:0|max:6',
            'day_of_month' => 'nullable|integer|min:1|max:31',
            'recipients' => 'required|array|min:1|max:'.ReportScheduler::MAX_RECIPIENTS,
            'recipients.*' => 'email:rfc|max:190',
            'company_id' => 'nullable|integer',
        ]);

        $data['filters'] = array_filter((array) ($data['filters'] ?? []), fn (mixed $value): bool => is_scalar($value));

        return $data;
    }

    private function own(Request $request, int $id): object
    {
        $schedule = DB::table('report_schedules')->where('company_id', $this->scopeCompanyId($request))->where('id', $id)->first();
        abort_if($schedule === null, 404);

        if ((int) $schedule->user_id !== (int) Auth::id() && ! $this->isAdmin()) {
            abort(404);
        }

        return $schedule;
    }

    private function isAdmin(): bool
    {
        return Auth::user()->hasRole('companyadmin') || Auth::user()->hasRole('superadmin');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id, 'name' => $row->name, 'report_type' => $row->report_type, 'report_key' => $row->report_key, 'custom_report_id' => $row->custom_report_id,
            'filters' => json_decode((string) $row->filters, true) ?: [], 'frequency' => $row->frequency, 'weekday' => $row->weekday, 'day_of_month' => $row->day_of_month,
            'recipients' => json_decode((string) $row->recipients, true), 'is_active' => (bool) $row->is_active, 'last_run_at' => $row->last_run_at, 'last_status' => $row->last_status,
        ];
    }
}
