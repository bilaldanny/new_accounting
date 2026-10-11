<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\ProductAttributeDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The custom fields a company has defined for its products. The values are kept on the products themselves.
 */
class ProductAttributeController extends Controller
{
    use ScopesToCompany;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/productattribute');

        $rows = ProductAttributeDefinition::query()
            ->visibleToCurrentUser()
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->boolean('active_only'), fn ($q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/productattribute/add');

        $companyId = $this->scopeCompanyId($request);
        $data = $this->validated($request);

        $key = $this->resolveKey($companyId, $data['name'], $request->input('field_key'), null);

        $row = ProductAttributeDefinition::query()->create($data + ['company_id' => $companyId, 'field_key' => $key]);

        return response()->json(['message' => 'Successfully Saved', 'data' => $row], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/productattribute/:id/edit');

        $row = ProductAttributeDefinition::query()->visibleToCurrentUser()->findOrFail($id);
        $data = $this->validated($request);

        // The key is what products store their values under, so it is fixed once the field exists.
        $row->update($data);

        return response()->json(['message' => 'Successfully Saved', 'data' => $row->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/productattribute/delete');

        ProductAttributeDefinition::query()->visibleToCurrentUser()->findOrFail($id)->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'company_id' => 'nullable|integer',
            'name' => 'bail|required|string|min:2|max:100',
            'type' => ['required', Rule::in(ProductAttributeDefinition::TYPES)],
            'options' => 'nullable|array|max:100',
            'options.*' => 'string|max:100',
            'is_required' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:65000',
        ]);

        if ($data['type'] === 'select' && empty($data['options'])) {
            throw ValidationException::withMessages(['options' => ['A select field needs at least one option.']]);
        }

        if ($data['type'] !== 'select') {
            $data['options'] = null;
        }

        unset($data['company_id']);

        return $data + ['is_required' => false, 'is_active' => true, 'sort_order' => 0];
    }

    private function resolveKey(int $companyId, string $name, mixed $requested, ?int $exceptId): string
    {
        $key = Str::slug(is_string($requested) && trim($requested) !== '' ? $requested : $name, '_');

        if ($key === '') {
            throw ValidationException::withMessages(['name' => ['Give the field a name made of letters or digits.']]);
        }

        $taken = ProductAttributeDefinition::withTrashed()
            ->where('company_id', $companyId)->where('field_key', $key)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => ['A custom field with this name already exists.']]);
        }

        return $key;
    }
}
