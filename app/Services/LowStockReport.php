<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Products whose stock in a branch is at or below `products.alert_qty`.
 *
 * Stock is StockMovements, the same definition PurchaseLine::currentStock() uses (base units, sales
 * and sale returns included, sales counted from the cutover), summed over the product's variations
 * in one set-based query instead of calling currentStock() per product and variation.
 *
 * Only branches where the product has had stock movement are listed, so a product is never
 * reported for a branch that has never received it.
 */
class LowStockReport
{
    public const SORTABLE = [
        'name' => 'p.name',
        'sku' => 'p.sku',
        'company_name' => 'c.name',
        'branch_name' => 'b.name',
        'category_name' => 'cat.name',
        'brand_name' => 'br.name',
        'unit_name' => 'u.short_name',
        'stock' => 's.stock',
        'alert_qty' => 'p.alert_qty',
        'shortage' => 'shortage',
    ];

    /**
     * @param  array{search?: ?string, category_id?: mixed, brand_id?: mixed}  $filters
     */
    public function query(?int $companyId, ?int $branchId, array $filters = []): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $categoryId = $filters['category_id'] ?? null;
        $brandId = $filters['brand_id'] ?? null;

        return DB::query()
            ->fromSub($this->stockByProductAndBranch(), 's')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->leftJoin('branches as b', 'b.id', '=', 's.branch_id')
            ->leftJoin('companies as c', 'c.id', '=', 'p.company_id')
            ->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
            ->leftJoin('categories as cat', 'cat.id', '=', 'p.category_id')
            ->leftJoin('brands as br', 'br.id', '=', 'p.brand_id')
            ->whereNull('p.deleted_at')
            ->where('p.active', 1)
            ->whereNotNull('p.alert_qty')
            ->whereColumn('s.stock', '<=', 'p.alert_qty')
            ->when($companyId !== null, fn (Builder $query) => $query->where('p.company_id', $companyId))
            ->when($branchId !== null, fn (Builder $query) => $query->where('s.branch_id', $branchId))
            ->when(! empty($categoryId), fn (Builder $query) => $query->where('p.category_id', $categoryId))
            ->when(! empty($brandId), fn (Builder $query) => $query->where('p.brand_id', $brandId))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $sub) use ($search): void {
                $sub->where('p.name', 'like', "%{$search}%")->orWhere('p.sku', 'like', "%{$search}%");
            }))
            ->select([
                'p.id as product_id',
                'p.name',
                'p.sku',
                'p.alert_qty',
                's.branch_id',
                'b.name as branch_name',
                'c.name as company_name',
                'u.short_name as unit_name',
                'cat.name as category_name',
                'br.name as brand_name',
                's.stock',
                DB::raw('(p.alert_qty - s.stock) as shortage'),
            ]);
    }

    private function stockByProductAndBranch(): Builder
    {
        return DB::query()
            ->fromSub(StockMovements::query(), 'm')
            ->select('m.product_id', 'm.branch_id', DB::raw('SUM(m.qty) as stock'))
            ->groupBy('m.product_id', 'm.branch_id');
    }
}
