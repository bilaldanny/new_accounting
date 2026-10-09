<?php

namespace App\Http\Controllers;

use App\Models\AssetCategory;
use App\Models\FixedAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The Fixed Asset Register: one line per asset with its category, branch, cost, depreciation settings and
 * accumulated depreciation. This controller covers the register itself and assets carried over from before it
 * (they post nothing); acquisitions, CWIP, depreciation, transfers, revaluation, impairment and disposal build on it.
 */
class FixedAssetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/fixedasset');

        $assets = FixedAsset::query()
            ->visibleToCurrentUser()
            ->with(['category:id,name', 'branch:id,name'])
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('code', 'like', '%'.$request->input('search').'%')->orWhere('name', 'like', '%'.$request->input('search').'%')->orWhere('serial_no', 'like', '%'.$request->input('search').'%')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('category_id'), fn ($q) => $q->where('asset_category_id', $request->input('category_id')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->input('branch_id')))
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->input('company_id')))
            ->orderByDesc('id')
            ->paginate(min((int) ($request->input('show_record') ?: 25), 100));

        $assets->getCollection()->transform(fn (FixedAsset $asset) => $asset->present());

        return response()->json(['data' => $assets]);
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/fixedasset');

        $asset = FixedAsset::query()->visibleToCurrentUser()->with(['category', 'branch:id,name'])->findOrFail($id);

        return response()->json(['data' => $asset->present()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/fixedasset/add');

        $user = $request->user();
        $companyId = (int) ($user?->company_id ?: $request->integer('company_id'));

        if ($companyId === 0) {
            throw ValidationException::withMessages(['company_id' => ['Choose a company.']]);
        }

        $data = $this->validated($request, $companyId);
        $category = AssetCategory::query()->where('company_id', $companyId)->findOrFail($data['asset_category_id']);
        $data = FixedAsset::applyCategoryDefaults($data, $category);
        FixedAsset::assertDepreciationInputs($data);

        $asset = FixedAsset::query()->create($data + [
            'company_id' => $companyId,
            'code' => FixedAsset::nextCode($companyId),
            'status' => FixedAsset::STATUS_ACTIVE,
            'source' => 'opening',
            'created_by' => $user?->id,
        ]);

        return response()->json(['message' => 'Asset saved', 'data' => $asset->load(['category', 'branch:id,name'])->present()]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/fixedasset/:id/edit');

        $asset = FixedAsset::query()->visibleToCurrentUser()->findOrFail($id);

        if (! $asset->isDeletable()) {
            $data = $request->validate([
                'name' => 'required|string|max:200',
                'description' => 'nullable|string|max:500',
                'serial_no' => 'nullable|string|max:100',
            ]);
            $asset->update($data + ['updated_by' => $request->user()?->id]);

            return response()->json(['message' => 'Asset saved', 'data' => $asset->load(['category', 'branch:id,name'])->present()]);
        }

        $data = $this->validated($request, (int) $asset->company_id);
        $category = AssetCategory::query()->where('company_id', $asset->company_id)->findOrFail($data['asset_category_id']);
        $data = FixedAsset::applyCategoryDefaults($data, $category);
        FixedAsset::assertDepreciationInputs($data);

        $asset->update($data + ['updated_by' => $request->user()?->id]);

        return response()->json(['message' => 'Asset saved', 'data' => $asset->load(['category', 'branch:id,name'])->present()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/fixedasset/delete');

        $asset = FixedAsset::query()->visibleToCurrentUser()->findOrFail($id);

        if (! $asset->isDeletable()) {
            throw ValidationException::withMessages(['asset' => ['An asset with postings or depreciation cannot be deleted. Dispose of it instead.']]);
        }

        $asset->delete();

        return response()->json(['message' => 'Asset deleted']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, int $companyId): array
    {
        return $request->validate([
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'asset_category_id' => ['required', 'integer', Rule::exists('asset_categories', 'id')->where('company_id', $companyId)->where('active', true)],
            'cost_center_id' => ['nullable', 'integer', Rule::exists('cost_centers', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'name' => 'required|string|max:200',
            'description' => 'nullable|string|max:500',
            'serial_no' => 'nullable|string|max:100',
            'acquired_on' => 'required|date',
            'in_service_on' => 'nullable|date|after_or_equal:acquired_on',
            'cost' => 'required|numeric|gt:0|max:999999999999',
            'salvage_value' => 'nullable|numeric|min:0',
            'accumulated_depreciation' => 'nullable|numeric|min:0',
            'method' => ['nullable', Rule::in(AssetCategory::METHODS)],
            'useful_life_months' => 'nullable|integer|min:1|max:1200',
            'rate' => 'nullable|numeric|gt:0|max:100',
            'depreciated_through' => 'nullable|date|after_or_equal:acquired_on|before_or_equal:today',
        ]);
    }
}
