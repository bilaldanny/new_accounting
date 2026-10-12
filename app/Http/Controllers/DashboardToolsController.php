<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesReportScope;
use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Http\Controllers\DashboardInsightsController as Insights;
use App\Models\Role;
use App\Services\Dashboard\DailyBusinessSummary;
use App\Services\Dashboard\DashboardLayouts;
use App\Services\Reports\InsightReportCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Dashboard tools closed in wave 1b: the layout the signed-in user ends up with (own, role, preset), the layouts a company sets per
 * role (`/dashboard/layouts`), the daily business summary settings and its send now (`/dashboard/summary`), and the KPI widgets a
 * user builds from the figures of the reports they may open. Company comes from the signed-in user; a user never reads another
 * user's widgets, and a widget is computed under the report's own menu permission every time.
 */
class DashboardToolsController extends Controller
{
    use ResolvesReportScope;
    use ScopesToCompany;

    public const PERIODS = ['today', 'week', 'month', 'last_30'];

    public const MAX_KPIS = 12;

    public function __construct(private readonly DashboardLayouts $layouts, private readonly DailyBusinessSummary $summary) {}

    // ---------------------------------------------------------------- layouts

    public function layout(): JsonResponse
    {
        return response()->json(['data' => $this->layouts->effective(Auth::user()), 'widgets' => Insights::WIDGETS]);
    }

    public function roleLayouts(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/dashboard/layouts');
        $companyId = $this->scopeCompanyId($request);
        $saved = DB::table('dashboard_role_layouts')->where('company_id', $companyId)->get()->mapWithKeys(fn (object $r): array => [(int) $r->role_id => (array) json_decode((string) $r->layout, true)]);

        return response()->json(['data' => Role::query()->where('company_id', $companyId)->orderBy('name')->get(['id', 'name'])->map(fn (Role $role): array => [
            'role_id' => (int) $role->id, 'name' => $role->name, 'shown' => $saved[(int) $role->id]['shown'] ?? null, 'preset_kind' => DashboardLayouts::kindFor($role->name),
            'preset' => ($kind = DashboardLayouts::kindFor($role->name)) === null ? null : DashboardLayouts::PRESETS[$kind],
        ])->all(), 'widgets' => Insights::WIDGETS, 'presets' => DashboardLayouts::PRESETS]);
    }

    public function saveRoleLayout(Request $request, int $roleId): JsonResponse
    {
        $this->authorizeMenuPermission('/dashboard/layouts');
        $companyId = $this->scopeCompanyId($request);
        abort_unless(Role::query()->where('company_id', $companyId)->whereKey($roleId)->exists(), 404);
        $data = $request->validate(['shown' => 'required|array|max:40', 'shown.*' => ['string', Rule::in(Insights::WIDGETS)], 'company_id' => 'nullable|integer']);
        $this->layouts->saveForRole($companyId, $roleId, $data['shown']);

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function resetRoleLayout(Request $request, int $roleId): JsonResponse
    {
        $this->authorizeMenuPermission('/dashboard/layouts');
        DB::table('dashboard_role_layouts')->where('company_id', $this->scopeCompanyId($request))->where('role_id', $roleId)->delete();

        return response()->json(['message' => 'Back to the built-in layout']);
    }

    // ---------------------------------------------------------------- daily summary

    public function summary(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/dashboard/summary');
        $companyId = $this->scopeCompanyId($request);

        return response()->json(['data' => $this->summary->settings($companyId) + [
            'runs' => DB::table('daily_summary_runs')->where('company_id', $companyId)->orderByDesc('id')->limit(30)->get(['run_date', 'channel', 'recipient', 'status', 'error']),
            'channels_available' => DailyBusinessSummary::CHANNELS,
        ]]);
    }

    public function saveSummary(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/dashboard/summary');
        $companyId = $this->scopeCompanyId($request);
        $data = $request->validate([
            'enabled' => 'nullable|boolean', 'send_hour' => 'nullable|integer|min:0|max:23', 'channels' => 'nullable|array|max:3', 'channels.*' => ['string', Rule::in(DailyBusinessSummary::CHANNELS)],
            'recipients' => 'nullable|array|max:10', 'recipients.*' => 'string|max:150', 'company_id' => 'nullable|integer',
        ]);

        return response()->json(['message' => 'Successfully Saved', 'data' => $this->summary->save($companyId, $data)]);
    }

    public function previewSummary(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/dashboard/summary');
        $companyId = $this->scopeCompanyId($request);
        $request->validate(['date' => 'nullable|date_format:Y-m-d']);
        $date = (string) $request->query('date', now()->toDateString());

        return response()->json(['data' => ['figures' => $this->summary->figures($companyId, $date), 'text' => $this->summary->text($companyId, $date)]]);
    }

    public function sendSummary(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/dashboard/summary');
        $companyId = $this->scopeCompanyId($request);
        $request->validate(['date' => 'nullable|date_format:Y-m-d', 'company_id' => 'nullable|integer']);

        return response()->json(['data' => $this->summary->send($companyId, (string) $request->input('date', now()->toDateString()), true)]);
    }

    // ---------------------------------------------------------------- KPI widgets

    /**
     * The reports the user may open that a KPI can be built from.
     */
    public function kpiCatalog(): JsonResponse
    {
        $reports = [];

        foreach (InsightReportCatalog::REPORTS as $key => [$title, $path]) {
            if (hasMenuPermission($path)) {
                $reports[] = ['key' => $key, 'title' => $title];
            }
        }

        return response()->json(['data' => $reports, 'periods' => self::PERIODS]);
    }

    /**
     * The numeric figures a report shows in its summary for a period, so a KPI names a real figure.
     */
    public function kpiFigures(Request $request, string $report): JsonResponse
    {
        [$companyId, $branchId] = $this->reportScope($request);
        $this->assertReport($report);

        if ($companyId === null) {
            return response()->json(['data' => [], 'needs_company' => true]);
        }

        $summary = app(InsightReportCatalog::class)->run($report, $companyId, $branchId, $this->filters('month'))['summary'];

        return response()->json(['data' => array_values(array_keys(array_filter($summary, fn ($v): bool => is_int($v) || is_float($v))))]);
    }

    public function kpis(Request $request): JsonResponse
    {
        [$companyId, $branchId] = $this->reportScope($request);

        if ($companyId === null) {
            return response()->json(['data' => [], 'needs_company' => true]);
        }

        $catalog = app(InsightReportCatalog::class);
        $cache = [];
        $rows = DB::table('dashboard_kpis')->where('company_id', $companyId)->where('user_id', Auth::id())->orderBy('sort')->orderBy('id')->get()
            ->map(function (object $row) use ($catalog, $companyId, $branchId, &$cache): array {
                $path = InsightReportCatalog::REPORTS[$row->report_key][1] ?? null;
                $value = null;

                if ($path !== null && hasMenuPermission($path)) {
                    $key = $row->report_key.'|'.$row->period;
                    $cache[$key] ??= $catalog->run($row->report_key, $companyId, $branchId, $this->filters($row->period))['summary'];
                    $raw = $cache[$key][$row->figure_key] ?? null;
                    $value = is_numeric($raw) ? round((float) $raw, 2) : null;
                }

                return ['id' => (int) $row->id, 'label' => $row->label, 'report_key' => $row->report_key, 'figure_key' => $row->figure_key, 'period' => $row->period, 'value' => $value];
            })->all();

        return response()->json(['data' => $rows]);
    }

    public function saveKpi(Request $request): JsonResponse
    {
        [$companyId, $branchId] = $this->reportScope($request);
        $data = $request->validate([
            'report_key' => 'required|string|max:40', 'figure_key' => 'required|string|max:60', 'period' => ['required', Rule::in(self::PERIODS)],
            'label' => 'nullable|string|max:80', 'company_id' => 'nullable|integer', 'branch_id' => 'nullable|integer',
        ]);
        $this->assertReport($data['report_key']);

        if ($companyId === null) {
            throw ValidationException::withMessages(['company_id' => ['Choose a company.']]);
        }

        if (DB::table('dashboard_kpis')->where('company_id', $companyId)->where('user_id', Auth::id())->count() >= self::MAX_KPIS) {
            throw ValidationException::withMessages(['report_key' => ['You can keep at most '.self::MAX_KPIS.' KPI widgets.']]);
        }

        $summary = app(InsightReportCatalog::class)->run($data['report_key'], $companyId, $branchId, $this->filters($data['period']))['summary'];

        if (! isset($summary[$data['figure_key']]) || ! is_numeric($summary[$data['figure_key']])) {
            throw ValidationException::withMessages(['figure_key' => ['That report has no such numeric figure.']]);
        }

        $id = DB::table('dashboard_kpis')->insertGetId([
            'company_id' => $companyId, 'user_id' => Auth::id(), 'report_key' => $data['report_key'], 'figure_key' => $data['figure_key'], 'period' => $data['period'],
            'label' => mb_substr(strip_tags((string) ($data['label'] ?? '')) ?: InsightReportCatalog::REPORTS[$data['report_key']][0].': '.str_replace('_', ' ', $data['figure_key']), 0, 80),
            'sort' => (int) DB::table('dashboard_kpis')->where('company_id', $companyId)->where('user_id', Auth::id())->max('sort') + 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Widget added', 'data' => ['id' => $id]], 201);
    }

    public function deleteKpi(Request $request, int $id): JsonResponse
    {
        $affected = DB::table('dashboard_kpis')->where('user_id', Auth::id())->where('id', $id)->delete();
        abort_if($affected === 0, 404);

        return response()->json(['message' => 'Widget removed']);
    }

    // ---------------------------------------------------------------- helpers

    private function assertReport(string $report): void
    {
        abort_unless(array_key_exists($report, InsightReportCatalog::REPORTS), 404);
        $this->authorizeMenuPermission(InsightReportCatalog::REPORTS[$report][1]);
    }

    /**
     * @return array<string, string>
     */
    private function filters(string $period): array
    {
        $today = now();
        $from = match ($period) {
            'today' => $today->copy(),
            'week' => $today->copy()->startOfWeek(),
            'last_30' => $today->copy()->subDays(29),
            default => $today->copy()->startOfMonth(),
        };

        return ['start_date' => $from->toDateString(), 'end_date' => $today->toDateString(), 'period' => $today->format('Y-m')];
    }
}
