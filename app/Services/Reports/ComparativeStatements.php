<?php

namespace App\Services\Reports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The profit and loss of two periods and the balance sheet of two days side by side, built from the single-period statements
 * (ProfitLossReport, BalanceSheetReport) so a figure here is always the figure there. A line is matched on its section, code and
 * name; an account that exists in only one of the two columns shows zero in the other.
 *
 * The profit and loss values stock with the company's active costing method (CostSource, through StockValuation). The ledger has no
 * inventory account, so each balance sheet column carries the closing stock of its own day as an asset line, valued with the method in
 * force on that day, and the stock brought forward in equity (see BalanceSheetReport); both columns balance, and a company with no stock
 * or journalled stock gets no stock lines.
 */
class ComparativeStatements
{
    public const SORTABLE = [
        'id' => 'id',
        'code' => 'code',
        'name' => 'name',
        'current' => 'current',
        'previous' => 'previous',
        'change' => 'change',
        'change_percent' => 'change_percent',
    ];

    private const PROFIT_LOSS_SECTIONS = ['revenue' => 1, 'cogs' => 2, 'gross_profit' => 3, 'expenses' => 4, 'net_profit' => 5];

    private const BALANCE_SHEET_SECTIONS = ['assets' => 1, 'liabilities' => 2, 'equity' => 3, 'liabilities_equity' => 4, 'difference' => 5];

    public function __construct(
        private readonly ProfitLossReport $profitLoss,
        private readonly BalanceSheetReport $balanceSheet,
        private readonly AccountFigures $figures,
    ) {}

    /**
     * The comparison period of a profit and loss: the dates given, else the period of the same length right before the current one.
     *
     * @param  array<string, mixed>  $filters
     * @return array{current: array{0: string, 1: string}, previous: array{0: string, 1: string}}
     */
    public function periods(int $companyId, array $filters): array
    {
        [$from, $to] = $this->figures->range($filters['start_date'] ?? null, $filters['end_date'] ?? null, $this->figures->financialYear($companyId));
        $start = trim((string) ($filters['compare_start'] ?? ''));
        $end = trim((string) ($filters['compare_end'] ?? ''));

        if ($start === '' || $end === '') {
            $days = Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
            $end = Carbon::parse($from)->subDay()->toDateString();
            $start = Carbon::parse($end)->subDays($days - 1)->toDateString();
        }

        return ['current' => [$from, $to], 'previous' => [$start, $end]];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: Collection<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function profitLoss(int $companyId, ?int $branchId, array $filters): array
    {
        $periods = $this->periods($companyId, $filters);
        $current = $this->profitLoss->build($companyId, $branchId, ['start_date' => $periods['current'][0], 'end_date' => $periods['current'][1]]);
        $previous = $this->profitLoss->build($companyId, $branchId, ['start_date' => $periods['previous'][0], 'end_date' => $periods['previous'][1]]);

        $rows = $this->merge($current['rows'], $previous['rows'], self::PROFIT_LOSS_SECTIONS);
        $keys = ['revenue', 'cogs', 'gross_profit', 'expenses', 'net_profit'];
        $summary = ['current_from' => $periods['current'][0], 'current_to' => $periods['current'][1], 'previous_from' => $periods['previous'][0], 'previous_to' => $periods['previous'][1]];

        foreach ($keys as $key) {
            $now = (float) $current['summary'][$key];
            $then = (float) $previous['summary'][$key];
            $summary[$key] = $now;
            $summary[$key.'_previous'] = $then;
            $summary[$key.'_change'] = round($now - $then, 2);
        }

        return ['rows' => $rows, 'summary' => $summary];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: Collection<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function balanceSheet(int $companyId, ?int $branchId, array $filters): array
    {
        [, $asOf] = $this->figures->range(null, $filters['end_date'] ?? null, $this->figures->financialYear($companyId));
        $other = trim((string) ($filters['compare_end'] ?? ''));
        $other = $other !== '' ? $other : Carbon::parse($asOf)->subYear()->toDateString();

        $current = $this->balanceSheet->build($companyId, $branchId, ['end_date' => $asOf]);
        $previous = $this->balanceSheet->build($companyId, $branchId, ['end_date' => $other]);
        $rows = $this->merge($current['rows'], $previous['rows'], self::BALANCE_SHEET_SECTIONS);

        $summary = [
            'as_of' => $asOf, 'compare_as_of' => $other,
            'inventory' => (float) $current['summary']['closing_stock'], 'inventory_previous' => (float) $previous['summary']['closing_stock'],
            'inventory_method' => $current['summary']['stock_method'], 'inventory_method_previous' => $previous['summary']['stock_method'],
            'includes_stock' => $current['summary']['includes_stock'] || $previous['summary']['includes_stock'],
            'profit' => (float) $current['summary']['profit'], 'profit_previous' => (float) $previous['summary']['profit'],
        ];

        foreach (['total_assets', 'total_liabilities', 'total_equity', 'difference'] as $key) {
            $summary[$key] = (float) $current['summary'][$key];
            $summary[$key.'_previous'] = (float) $previous['summary'][$key];
        }

        return ['rows' => $rows, 'summary' => $summary];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $current
     * @param  Collection<int, array<string, mixed>>  $previous
     * @param  array<string, int>  $sections
     * @return Collection<int, array<string, mixed>>
     */
    private function merge(Collection $current, Collection $previous, array $sections): Collection
    {
        $merged = [];

        foreach ([['current', $current], ['previous', $previous]] as [$side, $rows]) {
            foreach ($rows as $row) {
                $key = $row['section'].'|'.$row['line'].'|'.$row['code'].'|'.$row['name'];
                $merged[$key] ??= ['section' => $row['section'], 'line' => $row['line'], 'code' => $row['code'], 'name' => $row['name'], 'current' => null, 'previous' => null];
                $merged[$key][$side] = $row['amount'];
            }
        }

        return collect($merged)
            ->sortBy(fn (array $row): array => [$sections[$row['section']] ?? 9, $this->lineRank($row), (string) $row['code']])
            ->values()
            ->map(fn (array $row, int $index): array => $this->line($index + 1, $row['section'], $row['line'], $row['code'], $row['name'], $row['current'], $row['previous']));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function lineRank(array $row): int
    {
        return match ($row['line']) {
            'header' => 0,
            'stock' => str_contains((string) $row['name'], 'Opening') ? 1 : 4,
            'account' => 2,
            'subtotal' => 3,
            'total' => 5,
            default => 6,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function line(int $id, string $section, string $line, string $code, string $name, ?float $current, ?float $previous): array
    {
        $isFigure = $current !== null || $previous !== null;
        $now = $current ?? 0.0;
        $then = $previous ?? 0.0;
        $change = $isFigure ? round($now - $then, 2) : null;

        return [
            'id' => $id, 'line' => $line, 'section' => $section, 'code' => $code, 'name' => $name,
            'current' => $isFigure ? round($now, 2) : null,
            'previous' => $isFigure ? round($then, 2) : null,
            'change' => $change,
            'change_percent' => $isFigure && $then != 0.0 ? round($change / abs($then) * 100, 1) : null,
        ];
    }
}
