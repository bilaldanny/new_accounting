<?php

namespace App\Services\Manufacturing;

use App\Models\Product;
use App\Models\ProductDetail;
use App\Services\StockMovements;
use App\Services\Valuation\CostSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bills of materials: versioned (a new version is a copy that is edited as a draft and activated; activating archives the version
 * that was active), multi-level (a component that has an active BOM of its own is a sub-assembly, exploded down to its raw
 * materials), with by-products / co-products and operations (direct labour and overhead per hour). Cost roll-ups use the unit cost
 * the company's valuation method gives (`CostSource`), so a BOM costs the same way the stock report does.
 *
 * A BOM never changes after it was activated: edit a new version. Work orders keep the version they were made from.
 */
class BomService
{
    public const MAX_DEPTH = 8;

    public function __construct(private readonly CostSource $costs) {}

    /**
     * @param  array<string, mixed>  $data  product_id, variation_id?, code?, name, output_qty?, valid_from?, valid_to?, notes?, items[], outputs[], operations[]
     *
     * @throws ValidationException
     */
    public function create(int $companyId, ?int $branchId, array $data, ?int $userId, ?int $version = null): int
    {
        $product = $this->product($companyId, (int) $data['product_id'], 'product_id');
        $variationId = $this->variation($product, isset($data['variation_id']) ? (int) $data['variation_id'] : null, 'variation_id');
        $outputQty = round((float) ($data['output_qty'] ?? 1), 4);

        if ($outputQty <= 0) {
            throw ValidationException::withMessages(['output_qty' => ['The batch quantity must be above zero.']]);
        }

        $items = $this->items($companyId, (int) $product->id, (array) ($data['items'] ?? []));
        $outputs = $this->outputs($companyId, (int) $product->id, (array) ($data['outputs'] ?? []));
        $operations = $this->operations((array) ($data['operations'] ?? []));

        return (int) DB::transaction(function () use ($companyId, $branchId, $data, $userId, $product, $variationId, $outputQty, $items, $outputs, $operations, $version): int {
            $next = $version ?? ((int) DB::table('boms')->where('company_id', $companyId)->where('product_id', $product->id)->max('version') + 1);
            $id = (int) DB::table('boms')->insertGetId([
                'company_id' => $companyId, 'branch_id' => $branchId, 'product_id' => $product->id, 'variation_id' => $variationId,
                'code' => mb_substr((string) ($data['code'] ?? 'BOM-'.$product->id), 0, 30), 'name' => mb_substr((string) ($data['name'] ?? $product->name), 0, 150), 'version' => $next,
                'status' => 'draft', 'output_qty' => $outputQty, 'valid_from' => $data['valid_from'] ?? null, 'valid_to' => $data['valid_to'] ?? null,
                'notes' => isset($data['notes']) ? mb_substr(strip_tags((string) $data['notes']), 0, 300) : null, 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->writeLines($id, $companyId, $items, $outputs, $operations);

            return $id;
        });
    }

    /**
     * Replaces the lines of a draft BOM.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(int $companyId, int $bomId, array $data): void
    {
        $bom = $this->find($companyId, $bomId);

        if ($bom->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ['Only a draft version can be edited. Make a new version instead.']]);
        }

        $items = $this->items($companyId, (int) $bom->product_id, (array) ($data['items'] ?? []));
        $outputs = $this->outputs($companyId, (int) $bom->product_id, (array) ($data['outputs'] ?? []));
        $operations = $this->operations((array) ($data['operations'] ?? []));
        $outputQty = round((float) ($data['output_qty'] ?? $bom->output_qty), 4);

        if ($outputQty <= 0) {
            throw ValidationException::withMessages(['output_qty' => ['The batch quantity must be above zero.']]);
        }

        DB::transaction(function () use ($bom, $companyId, $data, $items, $outputs, $operations, $outputQty): void {
            DB::table('boms')->where('id', $bom->id)->update([
                'name' => mb_substr((string) ($data['name'] ?? $bom->name), 0, 150), 'output_qty' => $outputQty, 'valid_from' => $data['valid_from'] ?? $bom->valid_from,
                'valid_to' => $data['valid_to'] ?? $bom->valid_to, 'notes' => isset($data['notes']) ? mb_substr(strip_tags((string) $data['notes']), 0, 300) : $bom->notes, 'updated_at' => now(),
            ]);
            $this->writeLines((int) $bom->id, $companyId, $items, $outputs, $operations);
        });
    }

    /**
     * A new draft version that copies an existing one.
     */
    public function newVersion(int $companyId, int $bomId, ?int $userId): int
    {
        $bom = $this->find($companyId, $bomId);
        $detail = $this->detail($companyId, $bomId);

        return $this->create($companyId, $bom->branch_id === null ? null : (int) $bom->branch_id, [
            'product_id' => $bom->product_id, 'variation_id' => $bom->variation_id, 'code' => $bom->code, 'name' => $bom->name, 'output_qty' => $bom->output_qty,
            'valid_from' => $bom->valid_from, 'valid_to' => $bom->valid_to, 'notes' => $bom->notes,
            'items' => array_map(fn (array $i): array => ['component_product_id' => $i['component_product_id'], 'component_variation_id' => $i['component_variation_id'], 'quantity' => $i['quantity'], 'scrap_percent' => $i['scrap_percent'], 'notes' => $i['notes']], $detail['items']),
            'outputs' => array_map(fn (array $o): array => ['product_id' => $o['product_id'], 'variation_id' => $o['variation_id'], 'kind' => $o['kind'], 'quantity' => $o['quantity'], 'cost_share_percent' => $o['cost_share_percent']], $detail['outputs']),
            'operations' => array_map(fn (array $o): array => ['name' => $o['name'], 'minutes' => $o['minutes'], 'labor_rate_per_hour' => $o['labor_rate_per_hour'], 'overhead_rate_per_hour' => $o['overhead_rate_per_hour']], $detail['operations']),
        ], $userId);
    }

    /**
     * Makes a draft the active version of its product; the version that was active becomes archived.
     *
     * @throws ValidationException
     */
    public function activate(int $companyId, int $bomId, ?int $userId): void
    {
        $bom = $this->find($companyId, $bomId);

        if ($bom->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ['Only a draft version can be activated.']]);
        }

        if (! DB::table('bom_items')->where('bom_id', $bomId)->exists()) {
            throw ValidationException::withMessages(['items' => ['A bill of materials needs at least one component.']]);
        }

        if ($this->wouldLoop($companyId, (int) $bom->product_id, array_column($this->detail($companyId, $bomId)['items'], 'component_product_id'))) {
            throw ValidationException::withMessages(['items' => ['This would make the product part of its own components, through a sub-assembly.']]);
        }

        DB::transaction(function () use ($companyId, $bom, $userId): void {
            DB::table('boms')->where('company_id', $companyId)->where('product_id', $bom->product_id)->where('status', 'active')->update(['status' => 'archived', 'updated_at' => now()]);
            DB::table('boms')->where('id', $bom->id)->update(['status' => 'active', 'activated_by' => $userId, 'activated_at' => now(), 'updated_at' => now()]);
        });
    }

    public function archive(int $companyId, int $bomId): void
    {
        $bom = $this->find($companyId, $bomId);

        if ($bom->status === 'draft') {
            throw ValidationException::withMessages(['status' => ['A draft is deleted, not archived.']]);
        }

        DB::table('boms')->where('id', $bom->id)->update(['status' => 'archived', 'updated_at' => now()]);
    }

    public function delete(int $companyId, int $bomId): void
    {
        $bom = $this->find($companyId, $bomId);

        if ($bom->status !== 'draft' || DB::table('work_orders')->where('bom_id', $bomId)->exists()) {
            throw ValidationException::withMessages(['status' => ['Only a draft that no work order used can be deleted.']]);
        }

        DB::transaction(function () use ($bomId): void {
            foreach (['bom_items', 'bom_outputs', 'bom_operations'] as $table) {
                DB::table($table)->where('bom_id', $bomId)->delete();
            }

            DB::table('boms')->where('id', $bomId)->delete();
        });
    }

    public function find(int $companyId, int $bomId): object
    {
        $bom = DB::table('boms')->where('company_id', $companyId)->where('id', $bomId)->first();
        abort_if($bom === null, 404);

        return $bom;
    }

    public function activeFor(int $companyId, int $productId): ?object
    {
        return DB::table('boms')->where('company_id', $companyId)->where('product_id', $productId)->where('status', 'active')->first();
    }

    /**
     * @return array{items: list<array<string, mixed>>, outputs: list<array<string, mixed>>, operations: list<array<string, mixed>>}
     */
    public function detail(int $companyId, int $bomId): array
    {
        $map = fn (object $row): array => (array) $row;

        return [
            'items' => DB::table('bom_items as i')->join('products as p', 'p.id', '=', 'i.component_product_id')->where('i.company_id', $companyId)->where('i.bom_id', $bomId)->orderBy('i.id')
                ->get(['i.id', 'i.component_product_id', 'i.component_variation_id', 'p.name', 'i.quantity', 'i.scrap_percent', 'i.notes'])->map($map)->all(),
            'outputs' => DB::table('bom_outputs as o')->join('products as p', 'p.id', '=', 'o.product_id')->where('o.company_id', $companyId)->where('o.bom_id', $bomId)->orderBy('o.id')
                ->get(['o.id', 'o.product_id', 'o.variation_id', 'p.name', 'o.kind', 'o.quantity', 'o.cost_share_percent'])->map($map)->all(),
            'operations' => DB::table('bom_operations')->where('company_id', $companyId)->where('bom_id', $bomId)->orderBy('sequence')->get(['id', 'sequence', 'name', 'minutes', 'labor_rate_per_hour', 'overhead_rate_per_hour'])->map($map)->all(),
        ];
    }

    /**
     * The raw materials needed for a quantity of a product, a sub-assembly with an active BOM exploded into its own components.
     * Each row of `tree` has its level; `materials` is the leaf totals (what has to be in stock, or bought).
     *
     * @return array{tree: list<array<string, mixed>>, materials: list<array<string, mixed>>}
     */
    public function explode(int $companyId, int $productId, float $quantity, int $level = 0, array $path = []): array
    {
        $bom = $this->activeFor($companyId, $productId);
        $tree = [];
        $materials = [];

        if ($bom === null || $level >= self::MAX_DEPTH || in_array($productId, $path, true)) {
            return ['tree' => $tree, 'materials' => $materials];
        }

        $factor = $quantity / max(0.0001, (float) $bom->output_qty);
        $names = Product::query()->where('company_id', $companyId)->pluck('name', 'id');

        foreach (DB::table('bom_items')->where('bom_id', $bom->id)->orderBy('id')->get() as $item) {
            $required = round((float) $item->quantity * $factor * (1 + (float) $item->scrap_percent / 100), 4);
            $componentId = (int) $item->component_product_id;
            $sub = $this->explode($companyId, $componentId, $required, $level + 1, [...$path, $productId]);
            $isAssembly = $sub['tree'] !== [] || $sub['materials'] !== [];
            $tree[] = ['level' => $level + 1, 'product_id' => $componentId, 'variation_id' => (int) $item->component_variation_id, 'name' => (string) ($names[$componentId] ?? '#'.$componentId), 'quantity' => $required, 'is_assembly' => $isAssembly];
            array_push($tree, ...$sub['tree']);

            if ($isAssembly) {
                array_push($materials, ...$sub['materials']);
            } else {
                $materials[] = ['product_id' => $componentId, 'variation_id' => (int) $item->component_variation_id, 'name' => (string) ($names[$componentId] ?? '#'.$componentId), 'quantity' => $required];
            }
        }

        $summed = [];

        foreach ($materials as $row) {
            $key = $row['product_id'].':'.$row['variation_id'];
            $summed[$key] = isset($summed[$key]) ? ['quantity' => round($summed[$key]['quantity'] + $row['quantity'], 4)] + $summed[$key] : $row;
        }

        return ['tree' => $tree, 'materials' => array_values($summed)];
    }

    /**
     * Cost of making a quantity from a BOM: materials at the valuation method's unit cost on the day (a variation with no cost counts
     * as zero and is listed in `unpriced`), plus operations at their hourly labour and overhead rates, in proportion to the batch.
     *
     * @return array{quantity: float, material: float, labor: float, overhead: float, total: float, unit: float, unpriced: list<string>, lines: list<array<string, mixed>>}
     */
    public function cost(int $companyId, int $bomId, float $quantity, ?string $asOf = null): array
    {
        $bom = $this->find($companyId, $bomId);
        $costs = $this->costs->stockCosts($companyId, $asOf);
        $factor = $quantity / max(0.0001, (float) $bom->output_qty);
        $material = 0.0;
        $unpriced = [];
        $lines = [];
        $names = Product::query()->where('company_id', $companyId)->pluck('name', 'id');

        foreach (DB::table('bom_items')->where('bom_id', $bomId)->get() as $item) {
            $required = (float) $item->quantity * $factor * (1 + (float) $item->scrap_percent / 100);
            $unit = $costs[(int) $item->component_variation_id] ?? null;

            if ($unit === null) {
                $unpriced[] = (string) ($names[(int) $item->component_product_id] ?? '#'.$item->component_product_id);
            }

            $amount = round($required * (float) ($unit ?? 0), 2);
            $material += $amount;
            $lines[] = ['product_id' => (int) $item->component_product_id, 'name' => (string) ($names[(int) $item->component_product_id] ?? ''), 'quantity' => round($required, 4), 'unit_cost' => $unit === null ? null : round((float) $unit, 4), 'amount' => $amount];
        }

        $labor = 0.0;
        $overhead = 0.0;

        foreach (DB::table('bom_operations')->where('bom_id', $bomId)->get() as $operation) {
            $hours = (float) $operation->minutes / 60 * $factor;
            $labor += $hours * (float) $operation->labor_rate_per_hour;
            $overhead += $hours * (float) $operation->overhead_rate_per_hour;
        }

        $total = round($material + $labor + $overhead, 2);

        return [
            'quantity' => round($quantity, 4), 'material' => round($material, 2), 'labor' => round($labor, 2), 'overhead' => round($overhead, 2), 'total' => $total,
            'unit' => $quantity > 0 ? round($total / $quantity, 4) : 0.0, 'unpriced' => array_values(array_unique($unpriced)), 'lines' => $lines,
        ];
    }

    // ------------------------------------------------------------------------------------------------------------------

    /**
     * @param  list<int>  $componentProductIds
     */
    private function wouldLoop(int $companyId, int $productId, array $componentProductIds): bool
    {
        $stack = $componentProductIds;
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
            $bom = $this->activeFor($companyId, $current);

            if ($bom !== null) {
                array_push($stack, ...DB::table('bom_items')->where('bom_id', $bom->id)->pluck('component_product_id')->map(fn ($id): int => (int) $id)->all());
            }
        }

        return false;
    }

    private function product(int $companyId, int $productId, string $field): Product
    {
        $product = Product::query()->where('company_id', $companyId)->find($productId);

        if ($product === null) {
            throw ValidationException::withMessages([$field => ['Choose a product of this company.']]);
        }

        if (! StockMovements::productKeepsStock((int) $product->id)) {
            throw ValidationException::withMessages([$field => [$product->name.' is not a stocked item.']]);
        }

        return $product;
    }

    private function variation(Product $product, ?int $variationId, string $field): int
    {
        $variations = ProductDetail::query()->where('product_id', $product->id)->pluck('id');

        if ($variationId === null && $variations->count() === 1) {
            return (int) $variations->first();
        }

        if ($variationId === null || ! $variations->contains($variationId)) {
            throw ValidationException::withMessages([$field => ['Choose which variation of '.$product->name.' is meant.']]);
        }

        return $variationId;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function items(int $companyId, int $productId, array $rows): array
    {
        $result = [];
        $seen = [];

        foreach (array_values($rows) as $index => $row) {
            $field = "items.{$index}";
            $component = $this->product($companyId, (int) ($row['component_product_id'] ?? 0), $field);

            if ((int) $component->id === $productId) {
                throw ValidationException::withMessages([$field => ['A product cannot be a component of itself.']]);
            }

            $quantity = round((float) ($row['quantity'] ?? 0), 4);
            $scrap = round((float) ($row['scrap_percent'] ?? 0), 2);

            if ($quantity <= 0 || $scrap < 0 || $scrap > 100) {
                throw ValidationException::withMessages([$field => ['Each component needs a quantity above zero and a scrap between 0 and 100 %.']]);
            }

            $variationId = $this->variation($component, isset($row['component_variation_id']) ? (int) $row['component_variation_id'] : null, $field);
            $key = $component->id.':'.$variationId;

            if (isset($seen[$key])) {
                throw ValidationException::withMessages([$field => [$component->name.' is listed twice.']]);
            }

            $seen[$key] = true;
            $result[] = ['component_product_id' => (int) $component->id, 'component_variation_id' => $variationId, 'quantity' => $quantity, 'scrap_percent' => $scrap, 'notes' => isset($row['notes']) ? mb_substr(strip_tags((string) $row['notes']), 0, 200) : null];
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function outputs(int $companyId, int $productId, array $rows): array
    {
        $result = [];
        $share = 0.0;

        foreach (array_values($rows) as $index => $row) {
            $field = "outputs.{$index}";
            $product = $this->product($companyId, (int) ($row['product_id'] ?? 0), $field);

            if ((int) $product->id === $productId) {
                throw ValidationException::withMessages([$field => ['The finished product is not its own by-product.']]);
            }

            $kind = (string) ($row['kind'] ?? 'byproduct');
            $quantity = round((float) ($row['quantity'] ?? 0), 4);
            $percent = round((float) ($row['cost_share_percent'] ?? 0), 2);

            if (! in_array($kind, ['byproduct', 'coproduct'], true) || $quantity <= 0 || $percent < 0 || $percent > 100) {
                throw ValidationException::withMessages([$field => ['An output needs a kind, a quantity above zero and a cost share between 0 and 100 %.']]);
            }

            $share += $percent;
            $result[] = ['product_id' => (int) $product->id, 'variation_id' => $this->variation($product, isset($row['variation_id']) ? (int) $row['variation_id'] : null, $field), 'kind' => $kind, 'quantity' => $quantity, 'cost_share_percent' => $percent];
        }

        if ($share > 100.0001) {
            throw ValidationException::withMessages(['outputs' => ['The cost shares of the by-products and co-products add up to more than 100 %.']]);
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function operations(array $rows): array
    {
        $result = [];

        foreach (array_values($rows) as $index => $row) {
            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                throw ValidationException::withMessages(["operations.{$index}" => ['An operation needs a name.']]);
            }

            $result[] = [
                'sequence' => $index + 1, 'name' => mb_substr(strip_tags($name), 0, 100), 'minutes' => max(0, round((float) ($row['minutes'] ?? 0), 2)),
                'labor_rate_per_hour' => max(0, round((float) ($row['labor_rate_per_hour'] ?? 0), 2)), 'overhead_rate_per_hour' => max(0, round((float) ($row['overhead_rate_per_hour'] ?? 0), 2)),
            ];
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $outputs
     * @param  list<array<string, mixed>>  $operations
     */
    private function writeLines(int $bomId, int $companyId, array $items, array $outputs, array $operations): void
    {
        foreach (['bom_items', 'bom_outputs', 'bom_operations'] as $table) {
            DB::table($table)->where('bom_id', $bomId)->delete();
        }

        foreach ($items as $row) {
            DB::table('bom_items')->insert($row + ['company_id' => $companyId, 'bom_id' => $bomId, 'created_at' => now(), 'updated_at' => now()]);
        }

        foreach ($outputs as $row) {
            DB::table('bom_outputs')->insert($row + ['company_id' => $companyId, 'bom_id' => $bomId, 'created_at' => now(), 'updated_at' => now()]);
        }

        foreach ($operations as $row) {
            DB::table('bom_operations')->insert($row + ['company_id' => $companyId, 'bom_id' => $bomId, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
