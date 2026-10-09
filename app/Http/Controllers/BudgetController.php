<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Budget Management: planned amounts per company, optionally per branch, cost center and expense or revenue account,
 * for a month or a whole year. Planning data only; nothing here touches the ledger.
 */
class BudgetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/budget');

        $budgets = Budget::query()
            ->visibleToCurrentUser()
            ->with(['branch:id,name', 'costCenter:id,code,name,deleted_at', 'account:id,code,name'])
            ->when($request->boolean('trashed'), fn ($q) => $q->onlyTrashed())
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->input('company_id')))
            ->when($request->filled('year'), fn ($q) => $q->where('year', $request->input('year')))
            ->when($request->filled('cost_center_id'), fn ($q) => $q->where('cost_center_id', $request->input('cost_center_id')))
            ->orderByDesc('year')->orderBy('month')->orderBy('id')
            ->paginate(min((int) ($request->input('show_record') ?: 25), 200));

        $budgets->getCollection()->transform(fn (Budget $budget) => $budget->present());

        return response()->json(['data' => $budgets]);
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/budget');

        return response()->json(['data' => Budget::withTrashed()->visibleToCurrentUser()->with(['branch:id,name', 'costCenter:id,code,name,deleted_at', 'account:id,code,name'])->findOrFail($id)->present()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/budget/add');

        $user = $request->user();
        $companyId = (int) ($user?->company_id ?: $request->integer('company_id'));

        if ($companyId === 0) {
            throw ValidationException::withMessages(['company_id' => ['Choose a company.']]);
        }

        $data = $this->validated($request, $companyId);
        $this->assertNoConflict($companyId, $data);

        $budget = Budget::query()->create($data + ['company_id' => $companyId, 'created_by' => $user?->id]);

        return response()->json(['message' => 'Budget saved', 'data' => $this->loaded($budget)->present()]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/budget/:id/edit');

        $budget = Budget::query()->visibleToCurrentUser()->findOrFail($id);
        $data = $this->validated($request, (int) $budget->company_id);
        $this->assertNoConflict((int) $budget->company_id, $data, $budget->id);

        $budget->update($data + ['updated_by' => $request->user()?->id]);

        return response()->json(['message' => 'Budget saved', 'data' => $this->loaded($budget->refresh())->present()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/budget/delete');

        Budget::query()->visibleToCurrentUser()->findOrFail($id)->delete();

        return response()->json(['message' => 'Budget deleted']);
    }

    public function restore(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/budget/restore');

        $budget = Budget::onlyTrashed()->visibleToCurrentUser()->findOrFail($id);
        $this->assertNoConflict((int) $budget->company_id, $budget->only(['branch_id', 'cost_center_id', 'coa_id', 'year', 'month']), $budget->id);
        $budget->restore();

        return response()->json(['message' => 'Budget restored', 'data' => $this->loaded($budget->refresh())->present()]);
    }

    private function loaded(Budget $budget): Budget
    {
        return $budget->load(['branch:id,name', 'costCenter:id,code,name,deleted_at', 'account:id,code,name']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, int $companyId): array
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'cost_center_id' => ['nullable', 'integer', Rule::exists('cost_centers', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'coa_id' => ['nullable', 'integer', Rule::exists('chart_of_accounts', 'id')->where('company_id', $companyId)->where('acc_type', 't')->whereNull('deleted_at'), function (string $attribute, mixed $value, \Closure $fail): void {
                $code = (string) DB::table('chart_of_accounts')->where('id', $value)->value('code');

                if (! in_array(substr($code, 0, 1), ['4', '5', '6'], true)) {
                    $fail('A budget account must be an expense, cost of goods sold or revenue account.');
                }
            }],
            'year' => 'required|integer|min:2000|max:2100',
            'month' => 'nullable|integer|min:1|max:12',
            'amount' => 'required|numeric|gt:0|max:999999999999',
            'note' => 'nullable|string|max:255',
        ]);

        return $data + ['branch_id' => null, 'cost_center_id' => null, 'coa_id' => null, 'month' => null, 'note' => null];
    }

    /**
     * One budget per scope and month, and a yearly budget cannot sit beside monthly ones for the same scope and year
     * (it would count twice).
     *
     * @param  array<string, mixed>  $data
     */
    private function assertNoConflict(int $companyId, array $data, ?int $ignoreId = null): void
    {
        $same = Budget::query()
            ->where('company_id', $companyId)
            ->where('year', $data['year'])
            ->where('branch_id', $data['branch_id'] ?? null)
            ->where('cost_center_id', $data['cost_center_id'] ?? null)
            ->where('coa_id', $data['coa_id'] ?? null)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->get(['id', 'month']);

        $month = $data['month'] ?? null;

        if ($same->contains(fn (Budget $budget): bool => $budget->month === $month)) {
            throw ValidationException::withMessages(['budget' => ['There is already a budget for this branch, cost center, account and period.']]);
        }

        if ($same->isNotEmpty() && ($month === null || $same->contains(fn (Budget $budget): bool => $budget->month === null))) {
            throw ValidationException::withMessages(['budget' => ['A yearly budget and monthly budgets cannot both exist for the same scope and year. Remove one kind first.']]);
        }
    }
}
