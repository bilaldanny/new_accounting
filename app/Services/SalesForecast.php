<?php

namespace App\Services;

use App\Services\Reports\PurchaseSaleSummary;
use Carbon\CarbonInterface;

/**
 * A basic estimate of next month's sales from the last few months, nothing cleverer: the net sales of each of the
 * last full calendar months (PurchaseSaleSummary, the same figure the Purchase & Sale report gives: net of
 * returns, drafts and quotations left out), then
 *
 * - a moving average: the mean of the last three months;
 * - a linear trend: the least-squares line through the months, read one month ahead (with R-squared, how well
 *   the line fits, so a wobbly history is not mistaken for a trend);
 * - the run rate: the current month so far, scaled to a whole month.
 *
 * The estimate is the linear trend when there are four or more months of history and the moving average when
 * there are two or three, never below zero; the range runs from the lower to the higher of the two methods.
 * Months before the company's first sale are left out so a young business is not dragged down by empty months,
 * and with fewer than two months of sales there is no estimate at all rather than a made-up one.
 */
class SalesForecast
{
    /**
     * How many full months of history are looked at.
     */
    public const HISTORY_MONTHS = 6;

    /**
     * The fewest months of sales an estimate needs.
     */
    public const MIN_MONTHS = 2;

    /**
     * A slope smaller than this share of the average month counts as flat.
     */
    public const FLAT_SHARE = 0.02;

    public function __construct(private readonly PurchaseSaleSummary $summary) {}

    /**
     * @return array<string, mixed>
     */
    public function forCompany(?int $companyId, ?int $branchId, CarbonInterface $today): array
    {
        $monthStart = $today->copy()->startOfMonth();
        $months = [];

        for ($back = self::HISTORY_MONTHS; $back >= 1; $back--) {
            $start = $monthStart->copy()->subMonthsNoOverflow($back);
            $months[] = [
                'month' => $start->format('Y-m'),
                'net_sales' => $this->netSales($companyId, $branchId, $start, $start->copy()->endOfMonth()),
            ];
        }

        // months before the first sale are not history, they are a business that had not started
        while ($months !== [] && $months[0]['net_sales'] <= 0) {
            array_shift($months);
        }

        $sold = $this->netSales($companyId, $branchId, $monthStart, $today);
        $elapsed = $today->day;
        $runRate = round($sold / $elapsed * $today->daysInMonth, 2);
        $result = [
            'target_month' => $monthStart->copy()->addMonthNoOverflow()->format('Y-m'),
            'history' => $months,
            'this_month' => ['month' => $monthStart->format('Y-m'), 'sold_so_far' => $sold, 'days_elapsed' => $elapsed, 'days_in_month' => $today->daysInMonth, 'run_rate' => $runRate],
            'estimate' => null,
            'low' => null,
            'high' => null,
            'method' => null,
            'moving_average' => null,
            'linear' => null,
            'trend' => null,
            'fit' => null,
            'reason' => null,
        ];

        $values = array_column($months, 'net_sales');
        $count = count($values);

        if ($count < self::MIN_MONTHS) {
            $result['reason'] = 'not_enough_history';

            return $result;
        }

        $average = round(array_sum(array_slice($values, -3)) / min(3, $count), 2);
        [$slope, $intercept, $fit] = $this->line($values);
        $linear = round(max($intercept + $slope * $count, 0), 2);
        $mean = array_sum($values) / $count;

        $result['moving_average'] = $average;
        $result['linear'] = $linear;
        $result['method'] = $count >= 4 ? 'linear' : 'moving_average';
        $result['estimate'] = $count >= 4 ? $linear : $average;
        $result['low'] = min($average, $linear);
        $result['high'] = max($average, $linear);
        $result['fit'] = round($fit, 3);
        $result['trend'] = match (true) {
            $mean > 0 && abs($slope) / $mean < self::FLAT_SHARE => 'flat',
            $slope > 0 => 'up',
            default => 'down',
        };

        return $result;
    }

    private function netSales(?int $companyId, ?int $branchId, CarbonInterface $from, CarbonInterface $to): float
    {
        return (float) $this->summary->build($companyId, $branchId, [
            'start_date' => $from->toDateString(),
            'end_date' => $to->toDateString(),
        ])['totals']['net_sales'];
    }

    /**
     * The least-squares line through the values at 0, 1, 2 ...: its slope, its value at 0 and R-squared (1 for a
     * perfect line, 0 when the line explains nothing; 1 too when every month is the same).
     *
     * @param  list<float>  $values
     * @return array{0: float, 1: float, 2: float}
     */
    private function line(array $values): array
    {
        $n = count($values);
        $meanX = ($n - 1) / 2;
        $meanY = array_sum($values) / $n;
        $covariance = 0.0;
        $varianceX = 0.0;
        $total = 0.0;

        foreach ($values as $x => $y) {
            $covariance += ($x - $meanX) * ($y - $meanY);
            $varianceX += ($x - $meanX) ** 2;
            $total += ($y - $meanY) ** 2;
        }

        $slope = $varianceX > 0 ? $covariance / $varianceX : 0.0;
        $intercept = $meanY - $slope * $meanX;
        $residual = 0.0;

        foreach ($values as $x => $y) {
            $residual += ($y - ($intercept + $slope * $x)) ** 2;
        }

        return [$slope, $intercept, $total > 0 ? max(1 - $residual / $total, 0.0) : 1.0];
    }
}
