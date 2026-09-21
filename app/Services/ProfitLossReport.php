<?php

namespace App\Services;

use App\Support\AccountClass;
use Illuminate\Support\Collection;

/**
 * The profit and loss statement of a period: revenue, cost of goods sold, expenses and the profit they
 * leave, from the approved vouchers of the ledger (AccountFigures).
 *
 *   gross profit = revenue - cost of goods sold
 *   net profit   = gross profit - expenses
 *
 * Accounts are sorted into revenue (5xx, credit less debit), cost of goods sold (6xx) and expenses (4xx),
 * both debit less credit, by the first digit of their code (AccountClass), never by the stored
 * `acc_nature`, `bs` or `pl` flags, which are wrong on many live accounts. The period is inclusive at both
 * ends and defaults to the financial year up to today; only the postings of the period count, opening
 * balances belong to the balance sheet.
 *
 * Cost of goods sold here is what the ledger holds in the 6xx accounts. Purchases post straight to the
 * Purchases account (611/612) and no cost is posted when a sale is made, so this is purchases of the
 * period, not an opening-stock-plus-purchases-less-closing-stock cost, and it overstates the cost of
 * goods actually sold while stock is building up. The old report had five different versions; none of
 * them is carried over.
 */
class ProfitLossReport
{
    public const SORTABLE = [
        'id' => 'id',
        'code' => 'code',
        'name' => 'name',
        'amount' => 'amount',
    ];

    /**
     * Class digit => [section key, heading, total label, credit-normal].
     *
     * @var array<int, array{key: string, heading: string, total: string, credit: bool}>
     */
    private const SECTIONS = [
        5 => ['key' => 'revenue', 'heading' => 'Revenue', 'total' => 'Total revenue', 'credit' => true],
        6 => ['key' => 'cogs', 'heading' => 'Cost of goods sold (as posted)', 'total' => 'Total cost of goods sold', 'credit' => false],
        4 => ['key' => 'expenses', 'heading' => 'Expenses', 'total' => 'Total expenses', 'credit' => false],
    ];

    public function __construct(private readonly AccountFigures $figures) {}

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

        $lines = [];
        $totals = [];

        foreach (self::SECTIONS as $digit => $section) {
            $accounts = $period
                ->filter(fn (array $figures, string $code): bool => (AccountClass::of($code)['digit'] ?? 0) === $digit)
                ->map(fn (array $figures): float => round($section['credit'] ? $figures['credit'] - $figures['debit'] : $figures['debit'] - $figures['credit'], 2))
                ->reject(fn (float $amount): bool => $amount == 0.0)
                ->sortKeys();

            $totals[$section['key']] = round((float) $accounts->sum(), 2);

            $lines[] = ['line' => 'header', 'section' => $section['key'], 'code' => '', 'name' => $section['heading'], 'amount' => null];

            foreach ($accounts as $code => $amount) {
                $lines[] = ['line' => 'account', 'section' => $section['key'], 'code' => (string) $code, 'name' => (string) ($names[$code] ?? ''), 'amount' => $amount];
            }

            $lines[] = ['line' => 'total', 'section' => $section['key'], 'code' => '', 'name' => $section['total'], 'amount' => $totals[$section['key']]];

            if ($digit === 6) {
                $grossProfit = round($totals['revenue'] - $totals['cogs'], 2);
                $lines[] = ['line' => 'result', 'section' => 'gross_profit', 'code' => '', 'name' => 'Gross profit', 'amount' => $grossProfit];
            }
        }

        $netProfit = round($totals['revenue'] - $totals['cogs'] - $totals['expenses'], 2);
        $lines[] = ['line' => 'result', 'section' => 'net_profit', 'code' => '', 'name' => $netProfit >= 0 ? 'Net profit' : 'Net loss', 'amount' => $netProfit];

        $rows = collect($lines)->map(fn (array $line, int $index): array => ['id' => $index + 1] + $line);

        return [
            'rows' => $rows,
            'summary' => [
                'count' => $rows->where('line', 'account')->count(),
                'revenue' => $totals['revenue'],
                'cogs' => $totals['cogs'],
                'gross_profit' => round($totals['revenue'] - $totals['cogs'], 2),
                'expenses' => $totals['expenses'],
                'net_profit' => $netProfit,
                'net_margin' => $totals['revenue'] > 0 ? round($netProfit / $totals['revenue'] * 100, 2) : 0.0,
                'from' => $from,
                'to' => $to,
            ],
        ];
    }
}
