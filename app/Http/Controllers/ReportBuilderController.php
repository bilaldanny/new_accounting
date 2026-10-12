<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The custom report builder (whitelisted datasets and columns, see ReportBuilder): saved definitions per company, private to their
 * author or shared with the company, run and exported as CSV. The company and branch always come from the signed-in user.
 */
class ReportBuilderController extends Controller
{
    use ScopesToCompany;

    public function meta(): JsonResponse
    {
        $this->authorizeMenuPermission('/reportbuilder');

        return response()->json(['data' => app(ReportBuilder::class)->metadata()]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/reportbuilder');
        $companyId = $this->scopeCompanyId($request);

        return response()->json(['data' => DB::table('custom_reports')->where('company_id', $companyId)->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('is_shared', true)->orWhere('user_id', Auth::id()))->orderBy('name')->get()
            ->map(fn (object $row): array => $this->present($row))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/reportbuilder/manage');
        $data = $this->validated($request);
        $companyId = $this->scopeCompanyId($request);
        $definition = app(ReportBuilder::class)->normalise($data['dataset'], $data['definition']);

        $id = DB::table('custom_reports')->insertGetId([
            'company_id' => $companyId, 'user_id' => Auth::id(), 'name' => $data['name'], 'dataset' => $data['dataset'],
            'definition' => json_encode($definition), 'is_shared' => (bool) ($data['is_shared'] ?? false), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Successfully Saved', 'data' => $this->present(DB::table('custom_reports')->find($id))], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/reportbuilder/manage');
        $report = $this->own($request, $id);
        $data = $this->validated($request);
        $definition = app(ReportBuilder::class)->normalise($data['dataset'], $data['definition']);

        DB::table('custom_reports')->where('id', $report->id)->update([
            'name' => $data['name'], 'dataset' => $data['dataset'], 'definition' => json_encode($definition),
            'is_shared' => (bool) ($data['is_shared'] ?? false), 'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Successfully Updated', 'data' => $this->present(DB::table('custom_reports')->find($report->id))]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/reportbuilder/manage');
        $report = $this->own($request, $id);

        DB::table('custom_reports')->where('id', $report->id)->update(['deleted_at' => now()]);
        DB::table('report_schedules')->where('custom_report_id', $report->id)->update(['is_active' => false, 'updated_at' => now()]);

        return response()->json(['message' => 'Successfully Deleted']);
    }

    /**
     * Runs a definition without saving it.
     */
    public function preview(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/reportbuilder');
        $data = $request->validate(['dataset' => 'required|string|max:40', 'definition' => 'required|array']);
        $built = app(ReportBuilder::class)->run($data['dataset'], $data['definition'], $this->scopeCompanyId($request), $this->branchId());

        return response()->json(['columns' => $built['columns'], 'data' => $built['rows'], 'truncated' => $built['truncated']]);
    }

    public function run(Request $request, int $id): JsonResponse|StreamedResponse
    {
        $this->authorizeMenuPermission('/reportbuilder');
        $companyId = $this->scopeCompanyId($request);
        $report = DB::table('custom_reports')->where('company_id', $companyId)->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('is_shared', true)->orWhere('user_id', Auth::id()))->where('id', $id)->first();
        abort_if($report === null, 404);

        $built = app(ReportBuilder::class)->run($report->dataset, (array) json_decode((string) $report->definition, true), $companyId, $this->branchId());

        if ($request->input('format') === 'csv') {
            $csv = app(ReportScheduler::class)->csv(collect($built['columns'])->pluck('label', 'key')->all(), $built['rows']);

            return response()->streamDownload(function () use ($csv): void {
                echo $csv;
            }, preg_replace('/[^A-Za-z0-9_-]+/', '-', $report->name).'.csv', ['Content-Type' => 'text/csv']);
        }

        return response()->json(['report' => $this->present($report), 'columns' => $built['columns'], 'data' => $built['rows'], 'truncated' => $built['truncated']]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|min:2|max:120', 'dataset' => 'required|string|max:40', 'definition' => 'required|array',
            'is_shared' => 'nullable|boolean', 'company_id' => 'nullable|integer',
        ]);
    }

    /**
     * A report of the company that the signed-in user may change: their own, or any of the company for a company admin.
     */
    private function own(Request $request, int $id): object
    {
        $report = DB::table('custom_reports')->where('company_id', $this->scopeCompanyId($request))->whereNull('deleted_at')->where('id', $id)->first();
        abort_if($report === null, 404);

        if ((int) $report->user_id !== (int) Auth::id() && ! Auth::user()->hasRole('companyadmin') && ! Auth::user()->hasRole('superadmin')) {
            throw ValidationException::withMessages(['report' => ['Only the person who made this report or a company admin can change it.']]);
        }

        return $report;
    }

    private function branchId(): ?int
    {
        $user = Auth::user();

        return $user->branch_id && ! $user->hasRole('superadmin') && ! $user->hasRole('companyadmin') ? (int) $user->branch_id : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id, 'name' => $row->name, 'dataset' => $row->dataset, 'definition' => json_decode((string) $row->definition, true),
            'is_shared' => (bool) $row->is_shared, 'is_mine' => (int) $row->user_id === (int) Auth::id(), 'updated_at' => $row->updated_at,
        ];
    }
}
