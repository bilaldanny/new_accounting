<?php

namespace App\Services\Reports;

use App\Support\AccountClass;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The profit and loss statement of a period: revenue, cost of goods sold, expenses and the profit they
 * leave, from the approved vouchers of the ledger (AccountFigures) and the value of the stock.
 *
 *   cost of goods sold = opening stock + purchases - closing stock
 *   gross profit       = revenue - cost of goods sold
 *   net profit         = gross profit - expenses
 *
 * Purchases are what the ledger holds in the 6xx accounts for the period (purchases post straight to the
 * Purchases account, 611/612). Opening stock is the value of the stock at the end of the day before the
 * period starts and closing stock its value at the end of the period, both at the weighted average
 * purchase cost on that day (StockValuation), the method Item Profit & Loss and the Stock Report use, so
 * the closing stock here is the total of the Stock Report for the same day. Without the adjustment the
 * cost would be the purchases alone and profit would be understated while stock builds up. A variation
 * with stock but never purchased has no cost; it is left out of the stock values and counted in
 * `uncosted_stock`, which the page reports.
 *
 * Revenue (5xx) is credit less debit and expenses (4xx) debit less credit, by the first digit of the code
 * (AccountClass), never by the stored `acc_nature`, `bs` or `pl` flags, which are wrong on many live
 * accounts. The period is inclusive at both ends and defaults to the financial year up to today; only the
 * postings of the period count, opening balances belong to the balance sheet.
 *
 * The balance sheet still takes its profit straight from the ledger (no inventory account is posted), so
 * its "profit for the year to date" differs from this net profit by the change in stock value.
 */
class ProfitLossReport
{
    public const SORTABLE = [
        'id' => 'id',
        'code' => 'code',
        'name' => 'name',
        'amount' => 'amount',
    ];

    public function __construct(
        private readonly AccountFigures $figures,
        private readonly StockValuation $stock,
    ) {}

    /**
     * @param  array{start_date?: ?string, end_date?: ?string}  $filters
     * @return array{rows: Collection<int, array<string, mixed>>, summary: array<string, float|int|string>}
     */
    public function build(int $companyId, ?int $branchId, array $filters = []): array
    {
        $financialYear = $this->figures->financialYear($companyId);
        [$from, $to] = $this->figures->range($filters['start_date'] ?? null, $filters['end_date'] ?? null, $financialYear);

        $period = $this->figures->period($companyId, $branchId, $from, $to);
        $names = $this->figures->names($companyId, $period->keys());

        $opening = $this->stock->at($companyId, $branchId, Carbon::parse($from)->subDay()->toDateString());
        $closing = $this->stock->at($companyId, $branchId, $to);

        $revenue = $this->accounts($period, 5, true);
        $purchases = $this->accounts($period, 6, false);
        $expenses = $this->accounts($period, 4, false);

        $revenueTotal = round((float) $revenue->sum(), 2);
        $purchasesTotal = round((float) $purchases->sum(), 2);
        $expensesTotal = round((float) $expenses->sum(), 2);
        $cogs = round($opening['value'] + $purchasesTotal - $closing['value'], 2);
        $grossProfit = round($revenueTotal - $cogs, 2);
        $netProfit = round($grossProfit - $expensesTotal, 2);

        $lines = [];
        $add = function (string $line, string $section, string $code, string $name, ?float $amount) use (&$lines): void {
            $lines[] = ['line' => $line, 'section' => $section, 'code' => $code, 'name' => $name, 'amount' => $amount];
        };

        $add('header', 'revenue', '', 'Revenue', null);
        foreach ($revenue as $code => $amount) {
            $add('account', 'revenue', (string) $code, (string) ($names[$code] ?? ''), $amount);
        }
        $add('total', 'revenue', '', 'Total revenue', $revenueTotal);

        $add('header', 'cogs', '', 'Cost of goods sold', null);
        $add('stock', 'cogs', '', 'Opening stock', $opening['value']);
        foreach ($purchases as $code => $amount) {
            $add('account', 'cogs', (string) $code, (string) ($names[$code] ?? ''), $amount);
        }
        $add('subtotal', 'cogs', '', 'Add: purchases', $purchasesTotal);
        $add('stock', 'cogs', '', 'Less: closing stock', $closing['value'] == 0.0 ? 0.0 : -$closing['value']);
        $add('total', 'cogs', '', 'Total cost of goods sold', $cogs);

        $add('result', 'gross_profit', '', 'Gross profit', $grossProfit);

        $add('header', 'expenses', '', 'Expenses', null);
        foreach ($expenses as $code => $amount) {
            $add('account', 'expenses', (string) $code, (string) ($names[$code] ?? ''), $amount);
        }
        $add('total', 'expenses', '', 'Total expenses', $expensesTotal);

        $add('result', 'net_profit', '', $netProfit >= 0 ? 'Net profit' : 'Net loss', $netProfit);

        $rows = collect($lines)->map(fn (array $line, int $index): array => ['id' => $index + 1] + $line);

        return [
            'rows' => $rows,
            'summary' => [
                'count' => $rows->where('line', 'account')->count(),
                'revenue' => $revenueTotal,
                'opening_stock' => $opening['value'],
                'purchases' => $purchasesTotal,
                'closing_stock' => $closing['value'],
                'cogs' => $cogs,
                'gross_profit' => $grossProfit,
                'expenses' => $expensesTotal,
                'net_profit' => $netProfit,
                'net_margin' => $revenueTotal > 0 ? round($netProfit / $revenueTotal * 100, 2) : 0.0,
                'uncosted_stock' => max($opening['uncosted'], $closing['uncosted']),
                'from' => $from,
                'to' => $to,
            ],
        ];
    }

    /**
     * The postings of one account class in the period by account code, on the side the class carries.
     *
     * @param  Collection<string, array{debit: float, credit: float}>  $period
     * @return Collection<string, float>
     */
    private function accounts(Collection $period, int $digit, bool $creditNormal): Collection
    {
        return $period
            ->filter(fn (array $figures, string $code): bool => (AccountClass::of($code)['digit'] ?? 0) === $digit)
            ->map(fn (array $figures): float => round($creditNormal ? $figures['credit'] - $figures['debit'] : $figures['debit'] - $figures['credit'], 2))
            ->reject(fn (float $amount): bool => $amount == 0.0)
            ->sortKeys();
    }
}
