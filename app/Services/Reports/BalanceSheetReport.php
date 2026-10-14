<?php

namespace App\Services\Reports;

use App\Services\Valuation\ValuationMethod;
use App\Support\AccountClass;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The balance sheet on a day: assets against liabilities and equity, from the approved vouchers of the
 * ledger (AccountFigures).
 *
 * Each posting account stands at its stored opening balance of the active financial year plus its
 * postings from the start of the year up to and including the day (all postings when there is no active
 * year), shown on the side its class carries: assets (2xx) debit, liabilities (3xx) and equity (1xx)
 * credit, by the first digit of the code (AccountClass), not by the stored flags. Revenue, cost of goods
 * sold and expenses (5xx, 6xx, 4xx) are not on the sheet; what they add up to since the start of the year
 * is the "profit for the year to date", and it is added to equity. Every voucher balances, so
 *
 *   assets = liabilities + equity + profit to date
 *
 * holds whenever the opening balances balance; the report states the difference (0.00 when it holds) so
 * an unbalanced opening or an account outside the six classes shows up instead of being hidden. The
 * profit here includes any stored opening balance on a revenue, cost or expense account, which the
 * profit and loss report (postings of the period only) does not.
 *
 * Stock. Purchases post straight to the Purchases account and no inventory account is posted, so the ledger alone
 * shows the stock as spent. The sheet therefore adds the closing stock (StockValuation on the day, the company's
 * costing method of that day, the figure the Stock Report and the profit and loss use) as an asset line, and makes
 * the profit the profit and loss's own (periodic method: cost of goods = opening stock + purchases - closing stock,
 * from the start of the financial year to the day). The stock the year opened with was never an asset in the
 * ledger either, so it is shown in equity as "Stock brought forward"; the three lines together add exactly the
 * closing stock to both sides and the sheet still balances. A company with no stock, or one that already journals
 * its stock (JournalledStock), gets no stock lines and the figures it always had.
 */
class BalanceSheetReport
{
    public const SORTABLE = [
        'id' => 'id',
        'code' => 'code',
        'name' => 'name',
        'amount' => 'amount',
    ];

    public const CLOSING_STOCK = 'Closing stock (inventory at cost)';

    public const STOCK_BROUGHT_FORWARD = 'Stock brought forward (start of year)';

    /**
     * Class digit => [section key, heading, total label, credit-normal].
     *
     * @var array<int, array{key: string, heading: string, total: string, credit: bool}>
     */
    private const SECTIONS = [
        2 => ['key' => 'assets', 'heading' => 'Assets', 'total' => 'Total assets', 'credit' => false],
        3 => ['key' => 'liabilities', 'heading' => 'Liabilities', 'total' => 'Total liabilities', 'credit' => true],
        1 => ['key' => 'equity', 'heading' => 'Equity', 'total' => 'Total equity', 'credit' => true],
    ];

    public function __construct(
        private readonly AccountFigures $figures,
        private readonly StockValuation $stock,
        private readonly JournalledStock $journalled,
        private readonly ValuationMethod $methods,
    ) {}

    /**
     * @param  array{end_date?: ?string}  $filters
     * @return array{rows: Collection<int, array<string, mixed>>, summary: array<string, float|int|string|bool>}
     */
    public function build(int $companyId, ?int $branchId, array $filters = []): array
    {
        $financialYear = $this->figures->financialYear($companyId);
        $yearStart = $financialYear?->start_date?->toDateString();
        [, $asOf] = $this->figures->range(null, $filters['end_date'] ?? null, $financialYear);

        $stored = $this->figures->stored($companyId, $branchId, $financialYear);
        $net = $this->figures->net($companyId, $branchId, $yearStart, $asOf);

        // Debit-positive balance of every account code on the day.
        $balances = $stored->keys()->merge($net->keys())->unique()
            ->mapWithKeys(fn (string $code): array => [$code => round((float) ($stored[$code] ?? 0) + (float) ($net[$code] ?? 0), 2)]);
        $names = $this->figures->names($companyId, $balances->keys());

        $ledgerProfit = round(-(float) $balances
            ->filter(fn (float $balance, string $code): bool => in_array(AccountClass::of($code)['digit'] ?? 0, [4, 5, 6], true))
            ->sum(), 2);

        // the stock the periodic method adds: closing stock on the day, and the stock the year opened with (the same day the profit and
        // loss uses, so the sheet's profit is that statement's net profit)
        $closing = ['value' => 0.0, 'uncosted' => 0];
        $opening = ['value' => 0.0, 'uncosted' => 0];
        $stockLines = false;

        if (! $this->journalled->exists($companyId, $branchId)) {
            $closing = $this->stock->at($companyId, $branchId, $asOf);
            $opening = $this->stock->at($companyId, $branchId, Carbon::parse($yearStart ?? '2000-01-01')->subDay()->toDateString());
            $stockLines = $closing['value'] != 0.0 || $opening['value'] != 0.0;
        }

        $profit = $stockLines ? round($ledgerProfit + $closing['value'] - $opening['value'], 2) : $ledgerProfit;

        $lines = [];
        $totals = [];

        foreach (self::SECTIONS as $digit => $section) {
            $accounts = $balances
                ->filter(fn (float $balance, string $code): bool => (AccountClass::of($code)['digit'] ?? 0) === $digit)
                ->map(fn (float $balance): float => $section['credit'] ? -$balance : $balance)
                ->reject(fn (float $amount): bool => $amount == 0.0)
                ->sortKeys();

            $lines[] = ['line' => 'header', 'section' => $section['key'], 'code' => '', 'name' => $section['heading'], 'amount' => null];

            foreach ($accounts as $code => $amount) {
                $lines[] = ['line' => 'account', 'section' => $section['key'], 'code' => (string) $code, 'name' => (string) ($names[$code] ?? ''), 'amount' => round($amount, 2)];
            }

            $total = (float) $accounts->sum();

            if ($stockLines && $digit === 2) {
                $lines[] = ['line' => 'stock', 'section' => 'assets', 'code' => '', 'name' => self::CLOSING_STOCK, 'amount' => $closing['value']];
                $total += $closing['value'];
            }

            if ($stockLines && $digit === 1 && $opening['value'] != 0.0) {
                $lines[] = ['line' => 'stock', 'section' => 'equity', 'code' => '', 'name' => self::STOCK_BROUGHT_FORWARD, 'amount' => $opening['value']];
                $total += $opening['value'];
            }

            if ($digit === 1) {
                $lines[] = ['line' => 'account', 'section' => 'equity', 'code' => '', 'name' => $profit >= 0 ? 'Profit for the year to date' : 'Loss for the year to date', 'amount' => $profit];
                $total += $profit;
            }

            $totals[$section['key']] = round($total, 2);
            $lines[] = ['line' => 'total', 'section' => $section['key'], 'code' => '', 'name' => $section['total'], 'amount' => $totals[$section['key']]];
        }

        $liabilitiesAndEquity = round($totals['liabilities'] + $totals['equity'], 2);
        $difference = round($totals['assets'] - $liabilitiesAndEquity, 2);

        $lines[] = ['line' => 'result', 'section' => 'liabilities_equity', 'code' => '', 'name' => 'Total liabilities and equity', 'amount' => $liabilitiesAndEquity];
        $lines[] = ['line' => 'result', 'section' => 'difference', 'code' => '', 'name' => 'Difference (assets less liabilities and equity)', 'amount' => $difference];

        $rows = collect($lines)->map(fn (array $line, int $index): array => ['id' => $index + 1] + $line);

        return [
            'rows' => $rows,
            'summary' => [
                'count' => $rows->where('line', 'account')->count(),
                'total_assets' => $totals['assets'],
                'total_liabilities' => $totals['liabilities'],
                'total_equity' => $totals['equity'],
                'profit' => $profit,
                'ledger_profit' => $ledgerProfit,
                'includes_stock' => $stockLines,
                'closing_stock' => $stockLines ? $closing['value'] : 0.0,
                'opening_stock' => $stockLines ? $opening['value'] : 0.0,
                'stock_method' => $this->methods->methodOn($companyId, $asOf)['method'],
                'uncosted_stock' => $stockLines ? max($closing['uncosted'], $opening['uncosted']) : 0,
                'total_liabilities_equity' => $liabilitiesAndEquity,
                'difference' => $difference,
                'is_balanced' => $difference == 0.0,
                'as_of' => $asOf,
            ],
        ];
    }
}
