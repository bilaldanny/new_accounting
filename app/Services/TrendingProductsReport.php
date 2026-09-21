<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * The best selling products of a period: the top N by base units sold, net of returns.
 *
 * The old report kept the sales dated *before* its "from" date and cut the list after grouping by
 * document, so the ranking mixed documents and periods. This one takes the sell lines inside the range
 * (inclusive at both ends, drafts and quotations left out) and ranks whole products. Ties are broken by
 * the higher sales amount.
 */
class TrendingProductsReport
{
    public const DEFAULT_TOP = 10;

    public const SORTABLE = [
        'rank' => 'rank',
        'product_name' => 'product_name',
        'sku' => 'sku',
        'brand_name' => 'brand_name',
        'quantity' => 'quantity',
        'net_amount' => 'net_amount',
        'invoices' => 'invoices',
    ];

    public function __construct(private readonly ProductLineReport $lines) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters = []): Collection
    {
        $top = max((int) ($filters['top'] ?? self::DEFAULT_TOP), 1);

        return $this->lines->rows('sell', $companyId, $branchId, $filters)
            ->groupBy('product_id')
            ->map(function (Collection $group): array {
                $first = $group->first();

                return [
                    'id' => $first['product_id'],
                    'product_id' => $first['product_id'],
                    'product_name' => $first['product_name'],
                    'sku' => $first['sku'],
                    'brand_name' => $first['brand_name'],
                    'unit_name' => $first['base_unit_name'],
                    'invoices' => $group->count(),
                    'quantity' => round((float) $group->sum('net_base_quantity'), 4),
                    'net_amount' => round((float) $group->sum('net_amount'), 2),
                ];
            })
            ->sortBy([['quantity', 'desc'], ['net_amount', 'desc'], ['id', 'asc']])
            ->take($top)
            ->values()
            ->map(fn (array $row, int $index): array => ['rank' => $index + 1] + $row);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{count: int, quantity: float, net_amount: float}
     */
    public function summary(Collection $rows): array
    {
        return [
            'count' => $rows->count(),
            'quantity' => round((float) $rows->sum('quantity'), 4),
            'net_amount' => round((float) $rows->sum('net_amount'), 2),
        ];
    }
}
