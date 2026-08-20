<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ChartOfAccountController extends Controller
{
    /**
     * @return array<string, string>
     */
    protected function storeRules(): array
    {
        return [
            'name' => 'bail|required|string|min:3|max:200',
            'code' => 'bail|required|string|max:255',
            'parent_id' => 'bail|required|integer|exists:chart_of_accounts,id',
            'acc_type' => 'bail|required|in:t,c',
            'acc_nature' => 'bail|required|in:cr,dr',
            'active' => 'bail|required|boolean',
            'pl' => 'nullable|boolean',
            'bs' => 'nullable|boolean',
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function updateRules(): array
    {
        return [
            'name' => 'bail|required|string|min:3|max:200',
            'parent_id' => 'bail|required|integer|exists:chart_of_accounts,id',
            'active' => 'bail|required|boolean',
            'pl' => 'nullable|boolean',
            'bs' => 'nullable|boolean',
        ];
    }

    protected function normalizeBooleanFields(Request $request): void
    {
        foreach (['active', 'bs', 'pl'] as $field) {
            if (! $request->has($field)) {
                continue;
            }

            $value = $request->input($field);

            if (is_bool($value)) {
                continue;
            }

            if ($value === 1 || $value === 0 || $value === '1' || $value === '0') {
                $request->merge([
                    $field => (bool) (int) $value,
                ]);

                continue;
            }

            $request->merge([
                $field => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            ]);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $status = $request->status ?? 'all';

        $accounts = ChartOfAccount::query()
            ->visibleToCurrentUser()
            ->when($status !== 'all', fn ($query) => $query->where('active', $status))
            ->when($status === 'all', fn ($query) => $query->whereIn('active', [0, 1]))
            ->when($request->filled('company_id'), fn ($query) => $query->where('company_id', $request->integer('company_id')))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->whereNull('parent_id')
            ->with(['children' => ChartOfAccount::nestedChildrenRelation()])
            ->orderBy('code')
            ->get();

        return response()->json($accounts);
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalizeBooleanFields($request);
        $request->validate($this->storeRules());

        try {
            ChartOfAccount::createChartOfAccount($request);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return response()->json(['errormessage' => $e]);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function show(int $id): JsonResponse
    {
        $chartOfAccount = ChartOfAccount::findVisibleToCurrentUser($id);

        if ($chartOfAccount === null) {
            abort(404);
        }

        return response()->json($chartOfAccount);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->normalizeBooleanFields($request);
        $request->validate($this->updateRules());

        try {
            ChartOfAccount::updateChartOfAccount($request, $id);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return response()->json(['errormessage' => $e]);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function checkCode(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string',
            'except_id' => 'nullable|integer',
            'company_id' => 'nullable',
            'branch_id' => 'nullable',
        ]);

        $companyId = ChartOfAccount::resolveScopedId($request->company_id);
        $branchId = ChartOfAccount::resolveScopedId($request->branch_id);

        return response()->json([
            'code_taken' => ChartOfAccount::codeExists(
                $request->string('code')->toString(),
                $companyId,
                $branchId,
                $request->integer('except_id') ?: null,
            ),
        ]);
    }

    public function generateCode(Request $request): JsonResponse
    {
        $request->validate([
            'parent_id' => 'required|integer|exists:chart_of_accounts,id',
            'company_id' => 'required|integer',
            'branch_id' => 'required|integer',
            'acc_type' => 'nullable|in:t,c',
        ]);

        $parent = ChartOfAccount::query()->findOrFail($request->integer('parent_id'));
        ChartOfAccount::assertParentInScope(
            $parent,
            $request->integer('company_id'),
            $request->integer('branch_id'),
        );

        $code = ChartOfAccount::generateAccountCode(
            $request->integer('parent_id'),
            $request->integer('company_id'),
            $request->integer('branch_id'),
            $request->string('acc_type', 't')->toString(),
        );

        return response()->json(['code' => $code]);
    }

    public function resolveFromParent(Request $request): JsonResponse
    {
        $request->validate([
            'parent_id' => 'required|integer|exists:chart_of_accounts,id',
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'acc_type' => 'nullable|in:t,c',
        ]);

        $parent = ChartOfAccount::query()->findOrFail($request->integer('parent_id'));
        ChartOfAccount::assertParentInScope(
            $parent,
            ChartOfAccount::resolveScopedId($request->company_id),
            ChartOfAccount::resolveScopedId($request->branch_id),
        );

        return response()->json(
            ChartOfAccount::metadataForParent(
                $parent->id,
                $request->string('acc_type', 't')->toString(),
            ),
        );
    }

    public function fetchParentAccounts(Request $request): JsonResponse
    {
        return response()->json($this->accountOptions($request, 'c'));
    }

    public function fetchControlAccounts(Request $request): JsonResponse
    {
        return $this->fetchParentAccounts($request);
    }

    public function fetchChildAccounts(Request $request): JsonResponse
    {
        return response()->json($this->accountOptions($request, 't'));
    }

    public function fetchAllAccounts(Request $request): JsonResponse
    {
        return response()->json($this->accountOptions($request));
    }

    public function fetchParentSaleAccounts(Request $request): JsonResponse
    {
        $request->validate([
            'parent_id' => 'required|integer',
        ]);

        $accounts = ChartOfAccount::query()
            ->where('active', true)
            ->when($request->filled('company_id'), fn ($query) => $query->where('company_id', $request->integer('company_id')))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->where('parent_id', $request->integer('parent_id'))
            ->where('acc_type', 't')
            ->orderBy('code')
            ->select('chart_of_accounts.*', DB::raw("CONCAT(code, ' - ', name) AS text"))
            ->get();

        return response()->json($accounts);
    }

    public function fetchParentPurchaseAccounts(Request $request): JsonResponse
    {
        return $this->fetchParentSaleAccounts($request);
    }

    public function fetchObAccounts(Request $request): JsonResponse
    {
        $accounts = ChartOfAccount::query()
            ->where('active', true)
            ->where('bs', true)
            ->when($request->filled('company_id'), fn ($query) => $query->where('company_id', $request->integer('company_id')))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->orderBy('code')
            ->select('chart_of_accounts.*', DB::raw("CONCAT(code, ' - ', name) AS text"))
            ->get();

        return response()->json($accounts);
    }

    /**
     * @return Collection<int, ChartOfAccount>
     */
    private function accountOptions(Request $request, ?string $accType = null)
    {
        return ChartOfAccount::query()
            ->where('active', true)
            ->when($request->filled('company_id'), fn ($query) => $query->where('company_id', $request->integer('company_id')))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($accType !== null, fn ($query) => $query->where('acc_type', $accType))
            ->orderBy('code')
            ->select('chart_of_accounts.*', DB::raw("CONCAT(code, ' - ', name) AS text"))
            ->get();
    }
}
