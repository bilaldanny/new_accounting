<?php

namespace App\Services\Reports;

use App\Services\StockMovements;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Stock per product variation on a day, in the product's base unit, with how it got there.
 *
 * The quantities are StockMovements, the one definition of stock (the same one Low Stock, the forms and
 * the guards read), split by kind so nothing is defined twice:
 *
 *   current stock = purchased - purchase returned - sold + sale returned + transferred in
 *                   - transferred out + adjusted
 *
 * It is shown for one branch, for every branch together, or per branch (`by_branch`), up to and
 * including the "as of" day (today when none). Value uses AverageCost (the weighted average purchase
 * cost on that day) and the variation's default sell price per base unit; a variation that was never
 * purchased has no cost, so its stock value and potential profit are null and it stays out of those
 * totals. Quantities of different units are not added together, so the summary has no quantity total.
 */
class StockReport
{
    private const KINDS = ['purchase', 'purchase_return', 'sale', 'sale_return', 'transfer_in', 'transfer_out', 'adjustment'];

    public const SORTABLE = [
        'product_name' => 'product_name',
        'sku' => 'sku',
        'brand_name' => 'brand_name',
        'category_name' => 'category_name',
        'branch_name' => 'branch_name',
        'unit_name' => 'unit_name',
        'purchased' => 'purchased',
        'purchase_returned' => 'purchase_returned',
        'sold' => 'sold',
        'sale_returned' => 'sale_returned',
        'transferred_in' => 'transferred_in',
        'transferred_out' => 'transferred_out',
        'adjusted' => 'adjusted',
        'current_stock' => 'current_stock',
        'average_cost' => 'average_cost',
        'stock_value' => 'stock_value',
        'potential_profit' => 'potential_profit',
    ];

    /**
     * @param  array{product_id?: mixed, brand_id?: mixed, category_id?: mixed, itemtype_id?: mixed, unit_id?: mixed, end_date?: ?string, by_branch?: mixed, search?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters = []): Collection
    {
        $endDate = trim((string) ($filters['end_date'] ?? ''));
        $asOf = $endDate !== '' ? $endDate : null;
        $byBranch = filter_var($filters['by_branch'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $costs = AverageCost::byVariation($companyId, $asOf);

        return $this->grouped($companyId, $branchId, $filters, $asOf, $byBranch)
            ->get()
            ->map(function (stdClass $row, int $index) use ($costs, $byBranch): array {
                $stock = round((float) $row->current_stock, 4);
                $cost = $costs[(int) $row->variation_id] ?? null;
                $value = $cost === null ? null : round($stock * $cost, 2);
                $sellValue = round($stock * (float) $row->default_sell_price, 2);

                return [
                    'id' => $index + 1,
                    'product_id' => (int) $row->product_id,
                    'variation_id' => (int) $row->variation_id,
                    'branch_id' => $byBranch ? (int) $row->branch_id : null,
                    'branch_name' => $byBranch ? (string) ($row->branch_name ?? '') : '',
                    'product_name' => (string) ($row->product_name ?? '-'),
                    'sku' => (string) ($row->sku ?? ''),
                    'variation_name' => (string) ($row->variation_name ?? ''),
                    'brand_name' => (string) ($row->brand_name ?? ''),
                    'category_name' => (string) ($row->category_name ?? ''),
                    'unit_name' => (string) ($row->unit_name ?? ''),
                    'purchased' => round((float) $row->purchased, 4),
                    'purchase_returned' => round(-(float) $row->purchase_returned, 4),
                    'sold' => round(-(float) $row->sold, 4),
                    'sale_returned' => round((float) $row->sale_returned, 4),
                    'transferred_in' => round((float) $row->transferred_in, 4),
                    'transferred_out' => round(-(float) $row->transferred_out, 4),
                    'adjusted' => round((float) $row->adjusted, 4),
                    'current_stock' => $stock,
                    'average_cost' => $cost === null ? null : round($cost, 4),
                    'stock_value' => $value,
                    'sell_value' => $sellValue,
                    'potential_profit' => $value === null ? null : round($sellValue - $value, 2),
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{count: int, stock_value: float, sell_value: float, potential_profit: float, without_cost: int}
     */
    public function summary(Collection $rows): array
    {
        $costed = $rows->whereNotNull('stock_value');

        return [
            'count' => $rows->count(),
            'stock_value' => round((float) $costed->sum('stock_value'), 2),
            'sell_value' => round((float) $costed->sum('sell_value'), 2),
            'potential_profit' => round((float) $costed->sum('potential_profit'), 2),
            'without_cost' => $rows->whereNull('stock_value')->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function grouped(?int $companyId, ?int $branchId, array $filters, ?string $asOf, bool $byBranch): Builder
    {
        $productId = $filters['product_id'] ?? null;
        $brandId = $filters['brand_id'] ?? null;
        $categoryId = $filters['category_id'] ?? null;
        $itemTypeId = $filters['itemtype_id'] ?? null;
        $unitId = $filters['unit_id'] ?? null;
        $search = trim((string) ($filters['search'] ?? ''));

        $query = DB::query()
            ->fromSub(StockMovements::query(['branch_id' => $branchId, 'as_of' => $asOf, 'with_kinds' => true]), 'm')
            ->join('products as p', 'p.id', '=', 'm.product_id')
            ->leftJoin('product_details as d', 'd.id', '=', 'm.variation_id')
            ->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
            ->leftJoin('brands as br', 'br.id', '=', 'p.brand_id')
            ->leftJoin('categories as cat', 'cat.id', '=', 'p.category_id')
            ->whereNull('p.deleted_at')
            ->when($companyId !== null, fn (Builder $q) => $q->where('p.company_id', $companyId))
            ->when(! empty($productId), fn (Builder $q) => $q->where('p.id', $productId))
            ->when(! empty($brandId), fn (Builder $q) => $q->where('p.brand_id', $brandId))
            ->when(! empty($categoryId), fn (Builder $q) => $q->where('p.category_id', $categoryId))
            ->when(! empty($itemTypeId), fn (Builder $q) => $q->where('p.itemtype_id', $itemTypeId))
            ->when(! empty($unitId), fn (Builder $q) => $q->where('p.unit_id', $unitId))
            ->when($search !== '', fn (Builder $q) => $q->where(function (Builder $sub) use ($search): void {
                $sub->where('p.name', 'like', "%{$search}%")->orWhere('d.sku', 'like', "%{$search}%");
            }));

        $groups = ['m.product_id', 'm.variation_id', 'p.name', 'd.sku', 'd.variation_name', 'd.default_sell_price', 'u.short_name', 'br.name', 'cat.name'];
        $columns = [
            'm.product_id',
            'm.variation_id',
            'p.name as product_name',
            'd.sku',
            'd.variation_name',
            'd.default_sell_price',
            'u.short_name as unit_name',
            'br.name as brand_name',
            'cat.name as category_name',
        ];

        if ($byBranch) {
            $query->leftJoin('branches as b', 'b.id', '=', 'm.branch_id');
            $groups = [...$groups, 'm.branch_id', 'b.name'];
            $columns = [...$columns, 'm.branch_id', 'b.name as branch_name'];
        }

        $query->groupBy($groups)->select($columns);

        $sum = fn (string $kind, string $alias) => $query->selectRaw("coalesce(sum(case when m.kind = ? then m.qty else 0 end), 0) as {$alias}", [$kind]);

        foreach (['purchase' => 'purchased', 'purchase_return' => 'purchase_returned', 'sale' => 'sold', 'sale_return' => 'sale_returned', 'transfer_in' => 'transferred_in', 'transfer_out' => 'transferred_out', 'adjustment' => 'adjusted'] as $kind => $alias) {
            $sum($kind, $alias);
        }

        return $query->selectRaw('coalesce(sum(m.qty), 0) as current_stock');
    }
}
