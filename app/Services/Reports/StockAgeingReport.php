<?php

namespace App\Services\Reports;

use App\Services\StockMovements;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stock Ageing: how old the stock on hand is, per variation, on a day (default today). Stock is StockMovements, the one
 * definition. The age follows the usual first-in-first-out reading: what is on hand is the most recently received, so the
 * stock is laid over the receipts (purchases, sale returns, transfers in, positive adjustments) newest first, and each
 * receipt's share lands in its age band. Stock not explained by any receipt (history before the app) lands in `unknown`.
 * It is a view over the movements; nothing is stored and no cost method is involved except the optional stock value, which
 * is the weighted average cost the Stock Report uses.
 */
class StockAgeingReport
{
    public const SORTABLE = [
        'product_name' => 'product_name',
        'stock' => 'stock',
        'age_0_30' => 'age_0_30',
        'age_31_60' => 'age_31_60',
        'age_61_90' => 'age_61_90',
        'age_91_180' => 'age_91_180',
        'age_181_plus' => 'age_181_plus',
        'oldest_days' => 'oldest_days',
        'stock_value' => 'stock_value',
    ];

    public const DEFAULT_SORT = 'age_181_plus';

    private const BANDS = ['age_0_30' => [0, 30], 'age_31_60' => [31, 60], 'age_61_90' => [61, 90], 'age_91_180' => [91, 180], 'age_181_plus' => [181, PHP_INT_MAX]];

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        $asOf = trim((string) ($filters['end_date'] ?? '')) ?: Carbon::today()->toDateString();
        $search = trim((string) ($filters['search'] ?? ''));
        $day = Carbon::parse($asOf)->startOfDay();

        $movements = DB::query()
            ->fromSub(StockMovements::query(['branch_id' => $branchId, 'as_of' => $asOf, 'with_kinds' => true, 'with_documents' => true]), 'm')
            ->join('products as p', 'p.id', '=', 'm.product_id')
            ->whereNull('p.deleted_at')
            ->when($companyId !== null, fn ($q) => $q->where('p.company_id', $companyId))
            ->when(! empty($filters['product_id']), fn ($q) => $q->where('m.product_id', $filters['product_id']))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('p.name', 'like', "%{$search}%")->orWhere('p.sku', 'like', "%{$search}%")))
            ->get(['m.product_id', 'm.variation_id', 'm.qty', 'm.kind', 'm.document_date', 'p.name as product_name', 'p.sku', 'p.company_id']);

        $costs = AverageCost::byVariation($companyId, $asOf);

        return $movements->groupBy('variation_id')->map(function (Collection $group, $variationId) use ($day, $costs): ?array {
            $stock = round((float) $group->sum('qty'), 4);

            if ($stock <= 0) {
                return null;
            }

            $layers = $group->filter(fn (object $row): bool => (float) $row->qty > 0 && $row->document_date !== null)
                ->sortByDesc(fn (object $row): string => substr((string) $row->document_date, 0, 10))
                ->values();

            $bands = array_fill_keys(array_keys(self::BANDS), 0.0);
            $remaining = $stock;
            $oldest = null;

            foreach ($layers as $layer) {
                if ($remaining <= 0) {
                    break;
                }

                $take = min($remaining, (float) $layer->qty);
                $date = Carbon::parse(substr((string) $layer->document_date, 0, 10))->startOfDay();
                $age = max((int) $date->diffInDays($day, false), 0);

                foreach (self::BANDS as $key => [$low, $high]) {
                    if ($age >= $low && $age <= $high) {
                        $bands[$key] += $take;

                        break;
                    }
                }

                $oldest = $age;
                $remaining -= $take;
            }

            $first = $group->first();
            $cost = $costs[(int) $variationId] ?? null;

            return [
                'id' => (int) $variationId,
                'product_id' => (int) $first->product_id,
                'product_name' => $first->product_name,
                'sku' => $first->sku,
                'stock' => $stock,
                ...array_map(fn (float $quantity): float => round($quantity, 4), $bands),
                'unknown' => round(max($remaining, 0), 4),
                'oldest_days' => $oldest,
                'stock_value' => $cost === null ? null : round($stock * $cost, 2),
            ];
        })->filter()->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        return [
            'variations' => $rows->count(),
            'stock' => round((float) $rows->sum('stock'), 4),
            'older_than_180' => round((float) $rows->sum('age_181_plus'), 4),
            'stock_value' => round((float) $rows->whereNotNull('stock_value')->sum('stock_value'), 2),
        ];
    }
}
