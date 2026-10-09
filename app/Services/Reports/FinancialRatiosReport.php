<?php

namespace App\Services\Reports;

use Illuminate\Support\Collection;

/**
 * Financial Ratios, worked out from the Balance Sheet on the end day and the Profit & Loss over the range
 * (the financial year to date when no range is given). One company only.
 *
 * The chart of accounts has no current / non-current flag, so there is no current ratio or quick ratio:
 * only ratios that need the six account classes. Equity here is the balance sheet's equity plus the profit
 * for the year to date, the way its sheet balances.
 */
class FinancialRatiosReport
{
    public const SORTABLE = [
        'id' => 'id',
        'group' => 'group',
        'label' => 'label',
        'value' => 'value',
    ];

    public const DEFAULT_SORT = 'id';

    public const DEFAULT_DESC = false;

    public function __construct(
        private readonly BalanceSheetReport $balanceSheet,
        private readonly ProfitLossReport $profitLoss,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        if ($companyId === null) {
            return collect();
        }

        $range = ['start_date' => $filters['start_date'] ?? null, 'end_date' => $filters['end_date'] ?? null];
        $sheet = $this->balanceSheet->build($companyId, $branchId, $range)['summary'];
        $pl = $this->profitLoss->build($companyId, $branchId, $range)['summary'];

        $assets = (float) $sheet['total_assets'];
        $liabilities = (float) $sheet['total_liabilities'];
        $equity = (float) $sheet['total_equity'] + (float) $sheet['profit'];
        $revenue = (float) $pl['revenue'];

        $percent = fn (float $part, float $whole): ?float => $whole != 0.0 ? round($part / $whole * 100, 2) : null;
        $ratio = fn (float $part, float $whole): ?float => $whole != 0.0 ? round($part / $whole, 2) : null;

        $lines = [
            ['Profitability', 'Gross margin', $percent((float) $pl['gross_profit'], $revenue), '%', 'Gross profit / revenue'],
            ['Profitability', 'Net margin', $percent((float) $pl['net_profit'], $revenue), '%', 'Net profit / revenue'],
            ['Profitability', 'Expense ratio', $percent((float) $pl['expenses'], $revenue), '%', 'Expenses / revenue'],
            ['Returns', 'Return on assets', $percent((float) $pl['net_profit'], $assets), '%', 'Net profit / total assets'],
            ['Returns', 'Return on equity', $percent((float) $pl['net_profit'], $equity), '%', 'Net profit / equity'],
            ['Leverage', 'Debt ratio', $percent($liabilities, $assets), '%', 'Total liabilities / total assets'],
            ['Leverage', 'Equity ratio', $percent($equity, $assets), '%', 'Equity / total assets'],
            ['Leverage', 'Debt to equity', $ratio($liabilities, $equity), 'x', 'Total liabilities / equity'],
        ];

        return collect($lines)->map(fn (array $line, int $index): array => [
            'id' => $index + 1,
            'group' => $line[0],
            'label' => $line[1],
            'value' => $line[2],
            'unit' => $line[3],
            'formula' => $line[4],
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int|null>
     */
    public function summary(Collection $rows): array
    {
        $value = fn (string $label) => $rows->firstWhere('label', $label)['value'] ?? null;

        return [
            'gross_margin' => $value('Gross margin'),
            'net_margin' => $value('Net margin'),
            'debt_ratio' => $value('Debt ratio'),
            'debt_to_equity' => $value('Debt to equity'),
        ];
    }
}
