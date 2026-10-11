<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\Transaction;
use App\Services\Reports\AccountFigures;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A forward cash view in weekly buckets: today's balance of the bank and cash accounts, then what is expected to come in (open
 * invoices, on their due date) and go out (open bills and loan instalments, on theirs). Anything already due lands in the first
 * bucket as `overdue`. It is only as good as the due dates and does not guess: no sales forecast, no recurring costs, and cheques
 * are left out because the ledger already holds the payment they were taken against.
 */
class CashFlowForecast
{
    public const MAX_WEEKS = 26;

    public function __construct(private readonly AccountFigures $figures) {}

    /**
     * @return array<string, mixed>
     */
    public function build(int $companyId, ?int $branchId, int $weeks = 8, ?string $asOf = null): array
    {
        $weeks = max(1, min($weeks, self::MAX_WEEKS));
        $today = CarbonImmutable::parse($asOf ?? now()->toDateString())->startOfDay();
        $end = $today->addWeeks($weeks);

        $inflows = $this->documents($companyId, $branchId, Transaction::TYPE_SELL);
        $outflows = $this->documents($companyId, $branchId, Transaction::TYPE_PURCHASE);
        $loans = $this->loanInstalments($companyId, $branchId);

        $buckets = [['label' => 'Overdue', 'from' => null, 'to' => $today->subDay()->toDateString()]];

        for ($i = 0; $i < $weeks; $i++) {
            $from = $today->addWeeks($i);
            $buckets[] = ['label' => 'Week '.($i + 1), 'from' => $from->toDateString(), 'to' => $from->addDays(6)->toDateString()];
        }

        $opening = $this->openingBalance($companyId, $branchId, $today->toDateString());
        $running = $opening;
        $rows = [];

        foreach ($buckets as $bucket) {
            $in = $this->within($inflows, $bucket['from'], $bucket['to']);
            $out = $this->within($outflows, $bucket['from'], $bucket['to']);
            $loan = $this->within($loans, $bucket['from'], $bucket['to']);
            $net = round($in - $out - $loan, 2);
            $running = round($running + $net, 2);

            $rows[] = $bucket + ['receivables' => $in, 'payables' => $out, 'loan_instalments' => $loan, 'net' => $net, 'closing' => $running];
        }

        $beyond = [
            'receivables' => $this->within($inflows, $end->toDateString(), null),
            'payables' => $this->within($outflows, $end->toDateString(), null),
            'loan_instalments' => $this->within($loans, $end->toDateString(), null),
        ];

        return [
            'as_of' => $today->toDateString(),
            'weeks' => $weeks,
            'opening_balance' => round($opening, 2),
            'buckets' => $rows,
            'beyond' => $beyond,
            'lowest_closing' => round((float) collect($rows)->min('closing'), 2),
            'notes' => [
                'Receivables and payables are the open balances of posted invoices and bills, dated by their payment terms.',
                'Cheques are not added: the ledger already holds the payment each was taken against.',
                'No sales forecast and no recurring costs are assumed.',
            ],
        ];
    }

    private function openingBalance(int $companyId, ?int $branchId, string $today): float
    {
        $year = $this->figures->financialYear($companyId);
        $codes = ChartOfAccount::bankAndCashAccountOptions($companyId, $branchId)->pluck('code')->map(fn ($code): string => (string) $code)->unique()->values();

        if ($year === null || $codes->isEmpty()) {
            return 0.0;
        }

        $stored = $this->figures->stored($companyId, $branchId, $year);
        $net = $this->figures->net($companyId, $branchId, $year->start_date->toDateString(), $today);

        return round((float) $codes->sum(fn (string $code): float => (float) ($stored[$code] ?? 0) + (float) ($net[$code] ?? 0)), 2);
    }

    /**
     * @return list<array{due: string, amount: float}>
     */
    private function documents(int $companyId, ?int $branchId, string $type): array
    {
        $statuses = $type === Transaction::TYPE_SELL ? Transaction::UNPOSTED_SELL_STATUSES : ['draft', 'rejected', 'cancelled'];

        return Transaction::query()
            ->where('company_id', $companyId)->where('type', $type)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->whereNotIn('status', $statuses)->where('payment_status', '!=', 'paid')
            ->limit(5000)->get(['transaction_date', 'pay_term', 'pay_type', 'final_amount', 'paid_amount'])
            ->map(function (Transaction $document): ?array {
                $left = round((float) $document->final_amount - (float) $document->paid_amount, 2);

                if ($left <= 0 || $document->transaction_date === null) {
                    return null;
                }

                $date = CarbonImmutable::parse($document->transaction_date->toDateString());
                $term = (int) $document->pay_term;
                $due = match ((string) $document->pay_type) {
                    'month' => $date->addMonths($term),
                    'year' => $date->addYears($term),
                    default => $date->addDays($term),
                };

                return ['due' => $due->toDateString(), 'amount' => $left];
            })->filter()->values()->all();
    }

    /**
     * @return list<array{due: string, amount: float}>
     */
    private function loanInstalments(int $companyId, ?int $branchId): array
    {
        if (! Schema::hasTable('loan_schedule_rows')) {
            return [];
        }

        return DB::table('loan_schedule_rows as r')->join('loans as l', 'l.id', '=', 'r.loan_id')
            ->where('l.company_id', $companyId)->where('l.status', 'active')->whereNull('r.paid_on')
            ->when($branchId !== null, fn ($q) => $q->where('l.branch_id', $branchId))
            ->get(['r.due_on', 'r.total'])
            ->map(fn (object $row): array => ['due' => substr((string) $row->due_on, 0, 10), 'amount' => round((float) $row->total, 2)])->all();
    }

    /**
     * @param  list<array{due: string, amount: float}>  $items
     */
    private function within(array $items, ?string $from, ?string $to): float
    {
        return round((float) collect($items)
            ->filter(fn (array $item): bool => ($from === null || $item['due'] >= $from) && ($to === null || $item['due'] <= $to))
            ->sum('amount'), 2);
    }
}
