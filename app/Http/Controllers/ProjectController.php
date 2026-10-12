<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Contact;
use App\Models\CostCenter;
use App\Models\Project;
use App\Models\PurchaseLine;
use App\Models\SellLine;
use App\Models\TAccount;
use App\Models\TAccountDetail;
use App\Models\Transaction;
use App\Services\ProjectProfitability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The project master (separate from cost centers), tagging of documents and vouchers to a project, and the profitability report
 * (see Services\ProjectProfitability). A tag is a dimension: nothing is posted.
 */
class ProjectController extends Controller
{
    use ScopesToCompany;

    public function __construct(private readonly ProjectProfitability $profitability) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/project');

        $rows = Project::query()->visibleToCurrentUser()
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderBy('code')->limit(500)->get();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/project/add');

        $companyId = $this->scopeCompanyId($request);
        $data = $this->validated($request, $companyId);

        if (Project::query()->where('company_id', $companyId)->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => ['This project code is already used.']]);
        }

        $project = Project::query()->create($data + ['company_id' => $companyId, 'branch_id' => $this->scopeBranchId($request, $companyId), 'created_by' => Auth::id()]);

        return response()->json(['message' => 'Successfully Saved', 'data' => $project], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/project/:id/edit');

        $project = Project::query()->visibleToCurrentUser()->findOrFail($id);
        $data = $this->validated($request, (int) $project->company_id);

        if (Project::query()->where('company_id', $project->company_id)->where('code', $data['code'])->whereKeyNot($project->id)->exists()) {
            throw ValidationException::withMessages(['code' => ['This project code is already used.']]);
        }

        $project->update($data);

        return response()->json(['message' => 'Successfully Saved', 'data' => $project->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/project/delete');

        $project = Project::query()->visibleToCurrentUser()->findOrFail($id);
        $used = SellLine::query()->where('project_id', $project->id)->exists()
            || PurchaseLine::query()->where('project_id', $project->id)->exists()
            || TAccountDetail::query()->where('project_id', $project->id)->exists();

        if ($used) {
            throw ValidationException::withMessages(['project' => ['A project with tagged documents cannot be deleted; mark it completed or cancelled instead.']]);
        }

        $project->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }

    /**
     * Tags every line of the given sales, purchases and vouchers with the project (or clears it with a null project). A line in a
     * locked period is refused like any other change to it.
     */
    public function tag(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/project/tag');

        $data = $request->validate([
            'project_id' => 'nullable|integer',
            'transaction_ids' => 'nullable|array|max:200',
            'transaction_ids.*' => 'integer',
            'voucher_ids' => 'nullable|array|max:200',
            'voucher_ids.*' => 'integer',
        ]);

        $companyId = $this->scopeCompanyId($request);
        $projectId = $data['project_id'] ?? null;

        if ($projectId !== null && ! Project::query()->where('company_id', $companyId)->whereKey($projectId)->exists()) {
            throw ValidationException::withMessages(['project_id' => ['Choose a project of this company.']]);
        }

        $documents = Transaction::query()->where('company_id', $companyId)->whereIn('id', $data['transaction_ids'] ?? [])->get(['id', 'type']);
        $vouchers = TAccount::query()->where('company_id', $companyId)->whereIn('id', $data['voucher_ids'] ?? [])->pluck('id');

        if ($documents->count() !== count(array_unique($data['transaction_ids'] ?? [])) || $vouchers->count() !== count(array_unique($data['voucher_ids'] ?? []))) {
            throw ValidationException::withMessages(['transaction_ids' => ['A document or voucher is not part of this company.']]);
        }

        $count = DB::transaction(function () use ($documents, $vouchers, $projectId): int {
            $count = 0;

            foreach (SellLine::query()->whereIn('transaction_id', $documents->where('type', Transaction::TYPE_SELL)->pluck('id'))->get() as $line) {
                $line->project_id = $projectId;
                $line->save();
                $count++;
            }

            foreach (PurchaseLine::query()->whereIn('transaction_id', $documents->where('type', Transaction::TYPE_PURCHASE)->pluck('id'))->get() as $line) {
                $line->project_id = $projectId;
                $line->save();
                $count++;
            }

            foreach (TAccountDetail::query()->whereIn('t_account_id', $vouchers)->get() as $line) {
                $line->project_id = $projectId;
                $line->save();
                $count++;
            }

            return $count;
        });

        return response()->json(['message' => 'Successfully Saved', 'lines' => $count]);
    }

    public function report(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/project');

        $data = $request->validate(['project_id' => 'nullable|integer', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);

        return response()->json($this->profitability->build($this->scopeCompanyId($request), $data['project_id'] ?? null, $data['from'] ?? null, $data['to'] ?? null));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, int $companyId): array
    {
        $data = $request->validate([
            'code' => 'required|string|min:1|max:30',
            'name' => 'required|string|min:2|max:150',
            'contact_id' => 'nullable|integer',
            'cost_center_id' => 'nullable|integer',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'budget_revenue' => 'nullable|numeric|min:0',
            'budget_cost' => 'nullable|numeric|min:0',
            'status' => ['nullable', Rule::in(Project::STATUSES)],
            'description' => 'nullable|string|max:2000',
        ]);

        if (! empty($data['contact_id']) && ! Contact::query()->where('company_id', $companyId)->whereKey($data['contact_id'])->exists()) {
            throw ValidationException::withMessages(['contact_id' => ['Choose a customer of this company.']]);
        }

        if (! empty($data['cost_center_id']) && ! CostCenter::query()->where('company_id', $companyId)->whereKey($data['cost_center_id'])->exists()) {
            throw ValidationException::withMessages(['cost_center_id' => ['Choose a cost center of this company.']]);
        }

        return [
            'code' => $data['code'], 'name' => $data['name'], 'contact_id' => $data['contact_id'] ?? null, 'cost_center_id' => $data['cost_center_id'] ?? null,
            'start_date' => $data['start_date'] ?? null, 'end_date' => $data['end_date'] ?? null,
            'budget_revenue' => $data['budget_revenue'] ?? 0, 'budget_cost' => $data['budget_cost'] ?? 0,
            'status' => $data['status'] ?? 'active', 'description' => $data['description'] ?? null,
        ];
    }
}
