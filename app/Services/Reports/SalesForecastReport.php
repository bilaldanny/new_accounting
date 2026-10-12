<?php

namespace App\Services\Reports;

use App\Services\SalesForecast;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The sales forecast as a report: the months of history, the month so far with its run rate, and next month's estimate with the
 * low and high of the two methods (moving average of three months and a straight line). It is SalesForecast, the engine behind
 * the dashboard card, so the figures are identical; with fewer than two months of history there is no estimate and the report says so.
 */
class SalesForecastReport
{
    public const SORTABLE = [
        'month' => 'month',
        'kind' => 'kind',
        'net_sales' => 'net_sales',
        'low' => 'low',
        'high' => 'high',
    ];

    public const DEFAULT_SORT = 'month';

    public const DEFAULT_DESC = false;

    public function __construct(private readonly SalesForecast $forecast) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        $built = $this->forecast->forCompany($companyId, $branchId, Carbon::today());
        $rows = collect($built['history'])->map(fn (array $month): array => [
            'month' => $month['month'], 'kind' => 'Actual', 'net_sales' => $month['net_sales'], 'low' => null, 'high' => null, 'note' => '',
        ]);

        $rows->push([
            'month' => $built['this_month']['month'], 'kind' => 'This month (run rate)', 'net_sales' => $built['this_month']['run_rate'], 'low' => null, 'high' => null,
            'note' => 'sold so far '.number_format((float) $built['this_month']['sold_so_far'], 2).' in '.$built['this_month']['days_elapsed'].' of '.$built['this_month']['days_in_month'].' days',
        ]);

        $rows->push([
            'month' => $built['target_month'], 'kind' => 'Forecast', 'net_sales' => $built['estimate'], 'low' => $built['low'], 'high' => $built['high'],
            'note' => $built['estimate'] === null ? 'not enough history (needs at least '.SalesForecast::MIN_MONTHS.' months with sales)' : 'method: '.$built['method'].', trend: '.$built['trend'],
        ]);

        return $rows->values()->map(fn (array $row, int $index): array => ['id' => $index + 1] + $row);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int|string|null>
     */
    public function summary(Collection $rows): array
    {
        $actual = $rows->where('kind', 'Actual');
        $forecast = $rows->firstWhere('kind', 'Forecast');

        return [
            'months' => $actual->count(),
            'average' => $actual->isEmpty() ? 0.0 : round((float) $actual->avg('net_sales'), 2),
            'estimate' => $forecast['net_sales'] ?? null,
            'low' => $forecast['low'] ?? null,
            'high' => $forecast['high'] ?? null,
            'forecast_month' => $forecast['month'] ?? '',
        ];
    }
}
