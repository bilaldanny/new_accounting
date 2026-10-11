<?php

namespace App\Services;

use App\Models\ItemType;
use App\Models\Product;
use App\Models\ProductComponent;
use App\Models\ProductDetail;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A product component list (bill of materials) and the two stock movements built on it:
 *
 *  - kitting:    components go out, `quantity` finished units come in;
 *  - de-kitting: `quantity` finished units go out, their components come back.
 *
 * Both are an ordinary completed stock adjustment (adjustment type `unboxing`) written through
 * Transaction::createAdjustment, so the stock guards, the warehouse tag, branch scoping and the Stock Adjustment list all
 * apply as they do for any other adjustment. There is no costing here: cost stays the purchase average.
 *
 * A kit-behaviour product is not assembled: its components are taken out by each sale (StockMovements).
 */
class KitAssembly
{
    /**
     * Replaces the component list of a product.
     *
     * @param  list<array{component_product_id: int, component_variation_id?: int|null, quantity: float|int|string}>  $components
     *
     * @throws ValidationException
     */
    public function replaceComponents(Product $product, array $components): void
    {
        $companyId = (int) $product->company_id;
        $seen = [];
        $rows = [];

        foreach ($components as $index => $row) {
            $componentId = (int) ($row['component_product_id'] ?? 0);
            $quantity = (float) ($row['quantity'] ?? 0);
            $field = "components.{$index}";

            if ($componentId === $product->id) {
                throw ValidationException::withMessages([$field => ['A product cannot be a component of itself.']]);
            }

            if ($quantity <= 0) {
                throw ValidationException::withMessages([$field => ['Each component needs a quantity above zero.']]);
            }

            $component = Product::query()->where('company_id', $companyId)->find($componentId);

            if ($component === null) {
                throw ValidationException::withMessages([$field => ['Choose a product of this company as the component.']]);
            }

            if (! StockMovements::productKeepsStock($componentId) || StockMovements::kitComponents($componentId) !== []) {
                throw ValidationException::withMessages([$field => ["{$component->name} is not a stocked item, so it cannot be a component."]]);
            }

            $variationId = ! empty($row['component_variation_id']) ? (int) $row['component_variation_id'] : null;
            $variations = ProductDetail::query()->where('product_id', $componentId)->pluck('id');

            if ($variationId === null && $variations->count() === 1) {
                $variationId = (int) $variations->first();
            }

            if ($variationId === null || ! $variations->contains($variationId)) {
                throw ValidationException::withMessages([$field => ["Choose which variation of {$component->name} is used."]]);
            }

            $key = $componentId.':'.$variationId;

            if (isset($seen[$key])) {
                throw ValidationException::withMessages([$field => ["{$component->name} is listed twice."]]);
            }

            $seen[$key] = true;
            $rows[] = ['component_product_id' => $componentId, 'component_variation_id' => $variationId, 'quantity' => round($quantity, 4)];
        }

        if ($this->containsProduct($rows, (int) $product->id)) {
            throw ValidationException::withMessages(['components' => ['This would make the product part of its own components.']]);
        }

        DB::transaction(function () use ($product, $companyId, $rows): void {
            ProductComponent::query()->where('product_id', $product->id)->delete();

            foreach ($rows as $row) {
                ProductComponent::query()->create($row + ['company_id' => $companyId, 'product_id' => $product->id]);
            }
        });
    }

    /**
     * @param  'kit'|'dekit'  $mode
     *
     * @throws ValidationException
     */
    public function assemble(Product $product, string $mode, float $quantity, int $branchId, ?int $warehouseId, string $date, ?string $note): Transaction
    {
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => ['Enter a quantity above zero.']]);
        }

        if (ItemType::query()->whereKey($product->itemtype_id)->value('behaviour') === ItemType::BEHAVIOUR_KIT) {
            throw ValidationException::withMessages(['product_id' => ['A kit is not assembled in stock: its components go out with each sale.']]);
        }

        $components = ProductComponent::query()->where('product_id', $product->id)->get();

        if ($components->isEmpty()) {
            throw ValidationException::withMessages(['product_id' => ['This product has no component list (bill of materials) yet.']]);
        }

        $finished = ProductDetail::query()->where('product_id', $product->id)->orderBy('id')->first();

        if ($finished === null) {
            throw ValidationException::withMessages(['product_id' => ['This product has no variation to receive.']]);
        }

        $sign = $mode === 'kit' ? 1 : -1;
        $units = Product::query()->whereIn('id', $components->pluck('component_product_id')->push($product->id))->pluck('unit_id', 'id');

        $lines = [[
            'product_id' => $product->id, 'variation_id' => $finished->id, 'unit_id' => $units[$product->id],
            'itemtype_id' => $product->itemtype_id ?: null, 'quantity_adjustment' => $sign * $quantity,
        ]];

        foreach ($components as $component) {
            $lines[] = [
                'product_id' => $component->component_product_id, 'variation_id' => $component->component_variation_id,
                'unit_id' => $units[$component->component_product_id], 'quantity_adjustment' => -$sign * $quantity * (float) $component->quantity,
            ];
        }

        $request = (object) [
            'company_id' => $product->company_id,
            'branch_id' => $branchId,
            'warehouse_id' => $warehouseId,
            'transaction_date' => $date,
            'adjustment_type' => 'unboxing',
            'additional_note' => trim(($mode === 'kit' ? 'Kitting' : 'De-kitting').' of '.$product->name.($note ? ': '.$note : '')),
            'status' => 'completed',
            'total_item' => count($lines),
            'purchaselines' => $lines,
        ];

        return DB::transaction(fn (): Transaction => Transaction::createAdjustment($request));
    }

    /**
     * Whether any component, followed down through their own component lists, is the product.
     *
     * @param  list<array{component_product_id: int}>  $rows
     */
    private function containsProduct(array $rows, int $productId): bool
    {
        $stack = array_column($rows, 'component_product_id');
        $visited = [];

        while ($stack !== []) {
            $current = (int) array_pop($stack);

            if ($current === $productId) {
                return true;
            }

            if (isset($visited[$current])) {
                continue;
            }

            $visited[$current] = true;
            array_push($stack, ...ProductComponent::query()->where('product_id', $current)->pluck('component_product_id')->all());
        }

        return false;
    }
}
