<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * The particular item purchase and sell reports: for every product variation, how much was bought from
 * each supplier or sold to each customer in the range, in the product's base unit, net of returns.
 * Built from the same lines and counting rules as ProductLineReport, so the two always agree.
 */
class ItemTradeReport
{
    public const SORTABLE = [
        'product_name' => 'product_name',
        'sku' => 'sku',
        'contact_name' => 'contact_name',
        'quantity' => 'quantity',
        'invoices' => 'invoices',
        'amount' => 'amount',
    ];

    public function __construct(private readonly ProductLineReport $lines) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(string $kind, ?int $companyId, ?int $branchId, array $filters = []): Collection
    {
        return $this->lines->rows($kind, $companyId, $branchId, $filters)
            ->groupBy(fn (array $line): string => $line['product_id'].':'.($line['variation_id'] ?? 0).':'.($line['contact_id'] ?? 0))
            ->values()
            ->map(function (Collection $group, int $index): array {
                $first = $group->first();

                return [
                    'id' => $index + 1,
                    'product_id' => $first['product_id'],
                    'variation_id' => $first['variation_id'],
                    'product_name' => $first['product_name'],
                    'sku' => $first['sku'],
                    'variation_name' => $first['variation_name'],
                    'contact_id' => $first['contact_id'],
                    'contact_name' => $first['contact_name'],
                    'unit_name' => $first['base_unit_name'],
                    'invoices' => $group->count(),
                    'quantity' => round((float) $group->sum('net_base_quantity'), 4),
                    'amount' => round((float) $group->sum('net_amount'), 2),
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{count: int, quantity: float, amount: float}
     */
    public function summary(Collection $rows): array
    {
        return [
            'count' => $rows->count(),
            'quantity' => round((float) $rows->sum('quantity'), 4),
            'amount' => round((float) $rows->sum('amount'), 2),
        ];
    }
}
