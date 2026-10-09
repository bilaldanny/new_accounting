<?php

namespace App\Http\Controllers;

use App\Models\AssetCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Fixed-asset categories: depreciation defaults and the chart-of-accounts accounts their assets post to.
 */
class AssetCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/assetcategory');

        $categories = AssetCategory::query()
            ->visibleToCurrentUser()
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->input('search').'%'))
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->input('company_id')))
            ->orderBy('name')
            ->paginate(min((int) ($request->input('show_record') ?: 25), 100));

        $categories->getCollection()->transform(fn (AssetCategory $category) => $category->present());

        return response()->json(['data' => $categories]);
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/assetcategory');

        return response()->json(['data' => AssetCategory::query()->visibleToCurrentUser()->findOrFail($id)->present()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/assetcategory/add');

        $companyId = $this->companyId($request);
        $data = $this->validated($request, $companyId);

        $category = AssetCategory::query()->create($data + ['company_id' => $companyId, 'created_by' => $request->user()?->id]);

        return response()->json(['message' => 'Category saved', 'data' => $category->present()]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/assetcategory/:id/edit');

        $category = AssetCategory::query()->visibleToCurrentUser()->findOrFail($id);
        $category->update($this->validated($request, (int) $category->company_id, $category->id));

        return response()->json(['message' => 'Category saved', 'data' => $category->refresh()->present()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/assetcategory/delete');

        $category = AssetCategory::query()->visibleToCurrentUser()->findOrFail($id);

        if ($category->assets()->withTrashed()->exists()) {
            throw ValidationException::withMessages(['category' => ['This category has assets and cannot be deleted. Mark it inactive instead.']]);
        }

        $category->delete();

        return response()->json(['message' => 'Category deleted']);
    }

    private function companyId(Request $request): int
    {
        $user = $request->user();
        $companyId = $user?->company_id ?: $request->integer('company_id');

        if (! $companyId) {
            throw ValidationException::withMessages(['company_id' => ['Choose a company.']]);
        }

        return (int) $companyId;
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, int $companyId, ?int $ignoreId = null): array
    {
        $account = fn () => Rule::exists('chart_of_accounts', 'id')->where('company_id', $companyId)->where('acc_type', 't')->whereNull('deleted_at');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('asset_categories', 'name')->where('company_id', $companyId)->ignore($ignoreId)],
            'method' => ['required', Rule::in(AssetCategory::METHODS)],
            'useful_life_months' => 'nullable|integer|min:1|max:1200',
            'rate' => 'nullable|numeric|gt:0|max:100',
            'salvage_percent' => 'nullable|numeric|min:0|max:99',
            'active' => 'nullable|boolean',
            'asset_coa_id' => ['required', 'integer', $account()],
            'accumulated_coa_id' => ['required', 'integer', $account()],
            'expense_coa_id' => ['required', 'integer', $account()],
            'cwip_coa_id' => ['nullable', 'integer', $account()],
            'revaluation_coa_id' => ['nullable', 'integer', $account()],
            'impairment_coa_id' => ['nullable', 'integer', $account()],
            'disposal_coa_id' => ['nullable', 'integer', $account()],
            'transfer_coa_id' => ['nullable', 'integer', $account()],
        ]);

        $this->assertMethodInputs($data);

        return $data + ['salvage_percent' => 0, 'active' => true];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertMethodInputs(array $data): void
    {
        $method = $data['method'];
        $errors = [];

        if (in_array($method, ['straight_line', 'declining_balance'], true) && empty($data['useful_life_months'])) {
            $errors['useful_life_months'] = ['The useful life in months is required for this method.'];
        }

        if (in_array($method, ['declining_balance', 'wdv'], true) && empty($data['rate'])) {
            $errors['rate'] = [$method === 'wdv' ? 'The yearly rate % is required for WDV.' : 'The declining factor (for example 2) is required.'];
        }

        if ($method === 'declining_balance' && ! empty($data['rate']) && (float) $data['rate'] > 5) {
            $errors['rate'] = ['The declining factor is a multiple of the straight-line rate (1 to 5).'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
