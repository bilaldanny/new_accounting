<?php

namespace App\Services\Reports;

use App\Services\StockMovements;
use Illuminate\Support\Collection;

/**
 * Sales per product variation over the range, with the stock left on the last day of the range (or today).
 * Counting rules and amounts are ProductLineReport's; stock is StockMovements' (StockPosition).
 */
class ProductSellSummaryReport
{
    public const SORTABLE = [
        'product_name' => 'product_name',
        'sku' => 'sku',
        'brand_name' => 'brand_name',
        'quantity' => 'quantity',
        'returned_quantity' => 'returned_quantity',
        'net_quantity' => 'net_quantity',
        'net_amount' => 'net_amount',
        'current_stock' => 'current_stock',
    ];

    public function __construct(private readonly ProductLineReport $lines) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters = []): Collection
    {
        $endDate = trim((string) ($filters['end_date'] ?? ''));
        $stock = StockPosition::byVariation($branchId, $endDate !== '' ? $endDate : null);

        return $this->lines->rows('sell', $companyId, $branchId, $filters)
            ->groupBy(fn (array $line): string => $line['product_id'].':'.($line['variation_id'] ?? 0))
            ->values()
            ->map(function (Collection $group, int $index) use ($stock): array {
                $first = $group->first();

                return [
                    'id' => $index + 1,
                    'product_id' => $first['product_id'],
                    'variation_id' => $first['variation_id'],
                    'product_name' => $first['product_name'],
                    'sku' => $first['sku'],
                    'variation_name' => $first['variation_name'],
                    'brand_name' => $first['brand_name'],
                    'unit_name' => $first['base_unit_name'],
                    'quantity' => round((float) $group->sum('base_quantity'), 4),
                    'returned_quantity' => round((float) $group->sum('base_quantity') - (float) $group->sum('net_base_quantity'), 4),
                    'net_quantity' => round((float) $group->sum('net_base_quantity'), 4),
                    'net_amount' => round((float) $group->sum('net_amount'), 2),
                    'current_stock' => (float) ($stock[$first['variation_id'] ?? 0] ?? 0),
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{count: int, quantity: float, net_quantity: float, net_amount: float}
     */
    public function summary(Collection $rows): array
    {
        return [
            'count' => $rows->count(),
            'quantity' => round((float) $rows->sum('quantity'), 4),
            'net_quantity' => round((float) $rows->sum('net_quantity'), 4),
            'net_amount' => round((float) $rows->sum('net_amount'), 2),
        ];
    }
}
