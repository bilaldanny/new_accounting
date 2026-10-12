<?php

namespace App\Services\Reports;

use App\Models\ChartOfAccount;
use App\Support\AccountClass;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Cash Flow Statement, indirect method: the net profit of the period (revenue less cost of goods sold and expenses, from the approved
 * vouchers) followed by the cash effect of every balance sheet account that is not cash or bank. Every voucher balances, so the
 * credit less debit of those accounts plus the profit is exactly the movement of the cash and bank accounts: the report states the
 * difference to the direct method (CashFlowReport) and it is 0.00 whenever the books balance.
 *
 *  - Operating: the profit, then receivables, payables, stock-like and other current accounts (a depreciation expense is non-cash, so its
 *    credit to the accumulated depreciation account is the add-back);
 *  - Investing / Financing: the same reading of class and name as the direct method (CashFlowReport::section).
 *
 * The profit is the ledger's: purchases are posted straight to expense, so the change in stock value (which the Profit & Loss
 * adjusts for) is shown as a memo line and does not enter the totals.
 */
class IndirectCashFlowReport
{
    public const SORTABLE = [
        'section' => 'section_order',
        'account_code' => 'account_code',
        'account_name' => 'account_name',
        'amount' => 'amount',
    ];

    public const DEFAULT_SORT = 'section';

    public const DEFAULT_DESC = false;

    private const SECTIONS = ['Operating' => 1, 'Investing' => 2, 'Financing' => 3];

    public function __construct(
        private readonly AccountFigures $figures,
        private readonly CashFlowReport $direct,
        private readonly StockValuation $stock,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: Collection<int, array<string, mixed>>, summary: array<string, float|string>}
     */
    public function build(?int $companyId, ?int $branchId, array $filters): array
    {
        if ($companyId === null) {
            return ['rows' => collect(), 'summary' => []];
        }

        $year = $this->figures->financialYear($companyId);
        [$from, $to] = $this->figures->range($filters['start_date'] ?? null, $filters['end_date'] ?? null, $year);
        $cashCodes = ChartOfAccount::bankAndCashAccountOptions($companyId, $branchId)->pluck('code')->map(fn ($code): string => (string) $code)->unique()->all();
        $period = $this->figures->period($companyId, $branchId, $from, $to);
        $names = $this->figures->names($companyId, $period->keys());

        $profit = round((float) $period
            ->filter(fn (array $f, string $code): bool => in_array(AccountClass::of($code)['digit'] ?? 0, [4, 5, 6], true))
            ->sum(fn (array $f): float => $f['credit'] - $f['debit']), 2);

        $lines = [];
        $lines[] = ['section' => 'Operating', 'kind' => 'profit', 'account_code' => '', 'account_name' => $profit >= 0 ? 'Net profit for the period' : 'Net loss for the period', 'amount' => $profit];

        $adjustments = $period
            ->reject(fn (array $f, string $code): bool => in_array($code, $cashCodes, true) || ! in_array(AccountClass::of($code)['digit'] ?? 0, [1, 2, 3], true))
            ->map(fn (array $f): float => round($f['credit'] - $f['debit'], 2))
            ->reject(fn (float $amount): bool => $amount == 0.0)
            ->sortKeys();

        foreach ($adjustments as $code => $amount) {
            $name = (string) ($names[(string) $code] ?? '');
            $lines[] = ['section' => $this->direct->section((string) $code, $name), 'kind' => 'adjustment', 'account_code' => (string) $code, 'account_name' => $name, 'amount' => $amount];
        }

        $rows = collect($lines)
            ->map(fn (array $line): array => $line + ['section_order' => self::SECTIONS[$line['section']], 'kind_order' => $line['kind'] === 'profit' ? 0 : 1])
            ->sortBy(['section_order', 'kind_order', 'account_code'])
            ->values()
            ->map(fn (array $line, int $index): array => ['id' => $index + 1] + $line);

        $section = fn (string $name): float => round((float) $rows->where('section', $name)->sum('amount'), 2);
        $net = round((float) $rows->sum('amount'), 2);
        $direct = $this->direct->build($companyId, $branchId, ['start_date' => $from, 'end_date' => $to]);
        $directNet = round((float) $direct['rows']->sum('net'), 2);
        $stockChange = round($this->stock->at($companyId, $branchId, $to)['value'] - $this->stock->at($companyId, $branchId, Carbon::parse($from)->subDay()->toDateString())['value'], 2);

        return [
            'rows' => $rows,
            'summary' => [
                'profit' => $profit,
                'operating' => $section('Operating'),
                'investing' => $section('Investing'),
                'financing' => $section('Financing'),
                'net_change' => $net,
                'opening_cash' => $direct['opening'],
                'closing_cash' => round($direct['opening'] + $net, 2),
                'direct_method_net' => $directNet,
                'difference_to_direct' => round($net - $directNet, 2),
                'stock_change_memo' => $stockChange,
                'from' => $from,
                'to' => $to,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        return $this->build($companyId, $branchId, $filters)['rows'];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return array<string, float|string>
     */
    public function summary(Collection $rows, ?int $companyId = null, ?int $branchId = null, array $filters = []): array
    {
        return $this->build($companyId, $branchId, $filters)['summary'];
    }
}
