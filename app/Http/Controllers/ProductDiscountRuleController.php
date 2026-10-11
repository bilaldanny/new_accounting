<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDiscountRule;
use App\Services\ProductDiscountRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Per-product, per-category and per-brand discount rules.
 */
class ProductDiscountRuleController extends Controller
{
    use ScopesToCompany;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/productdiscountrule');

        $rows = ProductDiscountRule::query()
            ->visibleToCurrentUser()
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->orderByDesc('is_active')
            ->orderBy('scope')
            ->orderByDesc('priority')
            ->orderBy('name')
            ->get();

        $targets = $this->targetNames($rows);

        return response()->json(['data' => $rows->map(fn (ProductDiscountRule $rule): array => $rule->toArray() + [
            'target_name' => $targets[$rule->scope.':'.$this->targetId($rule)] ?? ($rule->scope === 'all' ? 'Everything' : ''),
        ])->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/productdiscountrule/add');

        $companyId = $this->scopeCompanyId($request);
        $data = $this->validated($request, $companyId);

        $rule = ProductDiscountRule::query()->create($data + ['company_id' => $companyId]);

        return response()->json(['message' => 'Successfully Saved', 'data' => $rule], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/productdiscountrule/:id/edit');

        $rule = ProductDiscountRule::query()->visibleToCurrentUser()->findOrFail($id);
        $rule->update($this->validated($request, (int) $rule->company_id));

        return response()->json(['message' => 'Successfully Saved', 'data' => $rule->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/productdiscountrule/delete');

        ProductDiscountRule::query()->visibleToCurrentUser()->findOrFail($id)->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }

    /**
     * The discount that applies to a quantity of a product today (what the sale form prefills).
     */
    public function resolve(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/sell');

        $request->validate(['product_id' => 'required|integer', 'quantity' => 'nullable|numeric|min:0', 'date' => 'nullable|date_format:Y-m-d']);

        $product = Product::query()->visibleToCurrentUser()->findOrFail($request->integer('product_id'));
        $rule = app(ProductDiscountRules::class)->bestFor((int) $product->company_id, $product, (float) ($request->input('quantity') ?? 1), $request->input('date'));

        return response()->json(['rule' => $rule]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, int $companyId): array
    {
        $data = $request->validate([
            'company_id' => 'nullable|integer',
            'name' => 'bail|required|string|min:2|max:150',
            'scope' => ['required', Rule::in(ProductDiscountRule::SCOPES)],
            'product_id' => 'nullable|integer',
            'category_id' => 'nullable|integer',
            'brand_id' => 'nullable|integer',
            'discount_type' => ['required', Rule::in(ProductDiscountRule::TYPES)],
            'value' => 'required|numeric|gt:0|max:1000000000',
            'min_quantity' => 'nullable|numeric|min:0',
            'starts_on' => 'nullable|date_format:Y-m-d',
            'ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:starts_on',
            'priority' => 'nullable|integer|min:0|max:1000',
            'is_active' => 'nullable|boolean',
        ]);

        if ($data['discount_type'] === 'percent' && (float) $data['value'] > 100) {
            throw ValidationException::withMessages(['value' => ['A percentage cannot be more than 100.']]);
        }

        $targets = ['product' => [Product::class, 'product_id'], 'category' => [Category::class, 'category_id'], 'brand' => [Brand::class, 'brand_id']];

        foreach ($targets as $scope => [$model, $field]) {
            if ($data['scope'] !== $scope) {
                $data[$field] = null;

                continue;
            }

            if (empty($data[$field]) || ! $model::query()->where('company_id', $companyId)->whereKey($data[$field])->exists()) {
                throw ValidationException::withMessages([$field => ["Choose a {$scope} of this company."]]);
            }
        }

        unset($data['company_id']);

        return $data + ['priority' => 0, 'is_active' => true];
    }

    private function targetId(ProductDiscountRule $rule): ?int
    {
        return match ($rule->scope) {
            'product' => $rule->product_id,
            'category' => $rule->category_id,
            'brand' => $rule->brand_id,
            default => null,
        };
    }

    /**
     * @param  iterable<ProductDiscountRule>  $rules
     * @return array<string, string>
     */
    private function targetNames(iterable $rules): array
    {
        $names = [];
        $sources = ['product' => [Product::class, 'product_id'], 'category' => [Category::class, 'category_id'], 'brand' => [Brand::class, 'brand_id']];

        foreach ($sources as $scope => [$model, $field]) {
            $ids = collect($rules)->where('scope', $scope)->pluck($field)->filter()->unique()->all();

            foreach ($model::query()->whereIn('id', $ids)->pluck('name', 'id') as $id => $name) {
                $names["{$scope}:{$id}"] = (string) $name;
            }
        }

        return $names;
    }
}
