<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\ItemType;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\Warehouse;
use App\Services\KitAssembly;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Kits and bills of materials: a product component list, and kitting / de-kitting through a stock adjustment.
 */
class ProductComponentController extends Controller
{
    public function __construct(private readonly KitAssembly $assembly) {}

    /**
     * Products that have a component list, or all products when `all=1` (to pick one to edit).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/productbom');

        $listed = ProductComponent::query()->distinct()->pluck('product_id');

        $products = Product::query()
            ->visibleToCurrentUser()
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when(! $request->boolean('all'), fn ($q) => $q->whereIn('id', $listed))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'sku', 'itemtype_id']);

        $behaviours = ItemType::query()->whereIn('id', $products->pluck('itemtype_id'))->pluck('behaviour', 'id');
        $counts = ProductComponent::query()->whereIn('product_id', $products->pluck('id'))->selectRaw('product_id, count(*) as c')->groupBy('product_id')->pluck('c', 'product_id');

        return response()->json(['data' => $products->map(fn (Product $product): array => [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'behaviour' => $behaviours[$product->itemtype_id] ?? null,
            'component_count' => (int) ($counts[$product->id] ?? 0),
        ])->values()]);
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/productbom');

        $product = Product::query()->visibleToCurrentUser()->findOrFail($id);

        $rows = ProductComponent::query()->where('product_id', $product->id)->with('component:id,name,sku')->orderBy('id')->get();

        return response()->json([
            'product' => ['id' => $product->id, 'name' => $product->name, 'behaviour' => ItemType::query()->whereKey($product->itemtype_id)->value('behaviour')],
            'components' => $rows->map(fn (ProductComponent $row): array => [
                'component_product_id' => (int) $row->component_product_id,
                'component_variation_id' => $row->component_variation_id === null ? null : (int) $row->component_variation_id,
                'quantity' => $row->quantity,
                'name' => $row->component?->name,
                'sku' => $row->component?->sku,
            ])->values(),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/productbom/:id/edit');

        $request->validate([
            'components' => 'present|array|max:100',
            'components.*.component_product_id' => 'required|integer',
            'components.*.component_variation_id' => 'nullable|integer',
            'components.*.quantity' => 'required|numeric|gt:0',
        ]);

        $product = Product::query()->visibleToCurrentUser()->findOrFail($id);
        $this->assembly->replaceComponents($product, $request->input('components'));

        return response()->json(['message' => 'Successfully Saved']);
    }

    /**
     * Kitting (components in, finished goods out) or de-kitting, as a completed stock adjustment.
     */
    public function assemble(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/productbom/kitting');

        $data = $request->validate([
            'mode' => 'required|in:kit,dekit',
            'quantity' => 'required|numeric|gt:0',
            'branch_id' => 'nullable|integer',
            'warehouse_id' => 'nullable|integer',
            'date' => 'required|date_format:Y-m-d',
            'note' => 'nullable|string|max:300',
        ]);

        $product = Product::query()->visibleToCurrentUser()->findOrFail($id);
        $user = Auth::user();
        $bound = $user?->branch_id && ! $user->hasRole('companyadmin') && ! $user->hasRole('superadmin');
        $branchId = $bound ? (int) $user->branch_id : (int) ($data['branch_id'] ?? 0);

        if ($branchId <= 0) {
            throw ValidationException::withMessages(['branch_id' => ['Choose the branch the stock moves in.']]);
        }

        if (! $bound && ! Branch::query()->where('company_id', $product->company_id)->whereKey($branchId)->exists()) {
            throw ValidationException::withMessages(['branch_id' => ['Choose a branch of this company.']]);
        }

        $warehouseId = ! empty($data['warehouse_id']) ? (int) $data['warehouse_id'] : null;

        if ($warehouseId !== null && ! Warehouse::query()->whereKey($warehouseId)->where('branch_id', $branchId)->exists()) {
            throw ValidationException::withMessages(['warehouse_id' => ['Choose a warehouse of that branch.']]);
        }

        $adjustment = $this->assembly->assemble($product, $data['mode'], (float) $data['quantity'], $branchId, $warehouseId, $data['date'], $data['note'] ?? null);

        return response()->json(['message' => 'Successfully Saved', 'adjustment_id' => $adjustment->id, 'invoice_no' => $adjustment->invoice_no]);
    }
}
