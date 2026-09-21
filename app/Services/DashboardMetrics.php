<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\Contact;
use App\Models\Product;
use App\Models\TAccount;
use App\Models\Transaction;
use App\Services\Reports\AccountFigures;
use App\Services\Reports\PartyOutstandingReport;
use App\Services\Reports\ProfitLossReport;
use App\Services\Reports\PurchaseSaleSummary;
use App\Services\Reports\StockValuation;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The figures of the dashboard cards. It calculates nothing of its own that a report already does: sales and
 * purchases are PurchaseSaleSummary (net of returns, drafts and quotations left out), what customers owe and
 * what suppliers are owed is PartyOutstandingReport (the contact ledger), the low stock list is LowStockReport,
 * the stock value StockValuation, the month's net profit ProfitLossReport, and cash and bank are the
 * Balance Sheet's way of reading an account (stored opening balance plus the approved postings). What is left
 * are counts.
 *
 * Every method takes the company and the branch the dashboard covers (null = no limit) and knows nothing
 * about who is looking or what they may see; the controller decides that.
 */
class DashboardMetrics
{
    /**
     * A customer is "close to the credit limit" from this share of it (0.8 = 80%).
     */
    public const CREDIT_WARNING_SHARE = 0.8;

    /**
     * How many rows the short lists (credit watch, low stock, recent sales) show.
     */
    public const LIST_SIZE = 8;

    public function __construct(
        private readonly PurchaseSaleSummary $trade,
        private readonly PartyOutstandingReport $outstanding,
        private readonly LowStockReport $lowStock,
        private readonly StockValuation $stockValue,
        private readonly ProfitLossReport $profitLoss,
        private readonly AccountFigures $figures,
    ) {}

    /**
     * Sales (today, this month) and purchases (this month) against last month. The change is against the same
     * days of last month, so that on the 10th this month is not compared with a whole finished month; the whole
     * of last month is given as well.
     *
     * @return array{sales: array<string, float|string|null>, purchases: array<string, float|string|null>}
     */
    public function trade(?int $companyId, ?int $branchId, CarbonInterface $today): array
    {
        $monthStart = $today->copy()->startOfMonth();
        $lastStart = $monthStart->copy()->subMonthNoOverflow()->startOfMonth();
        $lastEnd = $monthStart->copy()->subDay();
        $lastSameDay = $lastStart->copy()->addDays($today->day - 1);

        $day = $this->summary($companyId, $branchId, $today, $today);
        $month = $this->summary($companyId, $branchId, $monthStart, $today);
        $lastToDate = $this->summary($companyId, $branchId, $lastStart, $lastSameDay->gt($lastEnd) ? $lastEnd : $lastSameDay);
        $lastMonth = $this->summary($companyId, $branchId, $lastStart, $lastEnd);

        return [
            'sales' => [
                'today' => $day['net_sales'],
                'this_month' => $month['net_sales'],
                'last_month' => $lastMonth['net_sales'],
                'last_month_to_date' => $lastToDate['net_sales'],
                'change_percent' => $this->change($month['net_sales'], $lastToDate['net_sales']),
                'month_start' => $monthStart->toDateString(),
                'as_of' => $today->toDateString(),
            ],
            'purchases' => [
                'this_month' => $month['net_purchases'],
                'last_month' => $lastMonth['net_purchases'],
                'last_month_to_date' => $lastToDate['net_purchases'],
                'change_percent' => $this->change($month['net_purchases'], $lastToDate['net_purchases']),
            ],
        ];
    }

    /**
     * What customers still owe and what suppliers are still owed (both from the contact ledger), and the
     * customers at or over their credit limit. The customer ledgers are read once for the two customer figures.
     *
     * @return array{receivables: array<string, float|int|string>, payables: array<string, float|int|string>, credit_watch: array<string, mixed>}
     */
    public function receivables(int $companyId, ?int $branchId): array
    {
        $customers = $this->outstanding->rows('customer', $companyId, $branchId);
        $suppliers = $this->outstanding->rows('supplier', $companyId, $branchId);
        $owed = $this->outstanding->summary($customers);
        $payable = $this->outstanding->summary($suppliers);

        return [
            'receivables' => ['total_due' => $owed['total_due'], 'advances' => $owed['total_advance'], 'contacts' => $owed['count'], 'as_of' => $owed['as_of']],
            'payables' => ['total_due' => $payable['total_due'], 'advances' => $payable['total_advance'], 'contacts' => $payable['count'], 'as_of' => $payable['as_of']],
            'credit_watch' => $this->creditWatch($customers),
        ];
    }

    /**
     * Products at or under their alert quantity (the Low Stock page's own rows, the worst first) and the worth of
     * the stock on hand today.
     *
     * @return array{count: int, items: list<array<string, mixed>>}
     */
    public function lowStockAlerts(?int $companyId, ?int $branchId): array
    {
        $query = $this->lowStock->query($companyId, $branchId);

        return [
            'count' => DB::query()->fromSub($query, 'low')->count(),
            'items' => (clone $query)
                ->orderByDesc('shortage')
                ->limit(self::LIST_SIZE)
                ->get()
                ->map(fn (object $row): array => [
                    'product_id' => (int) $row->product_id,
                    'name' => (string) $row->name,
                    'branch_name' => $row->branch_name,
                    'stock' => round((float) $row->stock, 2),
                    'alert_qty' => round((float) $row->alert_qty, 2),
                    'unit_name' => $row->unit_name,
                ])
                ->all(),
        ];
    }

    /**
     * @return array{value: float, uncosted: int, as_of: string}
     */
    public function stockOnHand(int $companyId, ?int $branchId, CarbonInterface $today): array
    {
        return $this->stockValue->at($companyId, $branchId, $today->toDateString()) + ['as_of' => $today->toDateString()];
    }

    /**
     * How many documents wait for an approval, by the approval page they are on: purchases still `pending`,
     * sales still `final` (approved ones move on), and the manual vouchers still pending in each family.
     *
     * @param  list<string>  $only  the counts wanted, by key (purchase, sell, journal, payment, expense, deposit, fundtransfer)
     * @return array<string, int>
     */
    public function approvals(?int $companyId, ?int $branchId, array $only): array
    {
        $counts = [];

        foreach ($only as $key) {
            $counts[$key] = match ($key) {
                'purchase' => $this->scoped(Transaction::query()->purchases()->where('status', 'pending'), $companyId, $branchId)->count(),
                'sell' => $this->scoped(Transaction::query()->sells()->where('status', 'final'), $companyId, $branchId)->count(),
                default => $this->scoped(TAccount::query()->manualFamily($key)->where('status', TAccount::STATUS_PENDING), $companyId, $branchId)->count(),
            };
        }

        return $counts;
    }

    /**
     * The net profit of the month so far (the Profit & Loss report over the first of the month up to today).
     *
     * @return array<string, float|int|string>
     */
    public function netProfit(int $companyId, ?int $branchId, CarbonInterface $today): array
    {
        $summary = $this->profitLoss->build($companyId, $branchId, [
            'start_date' => $today->copy()->startOfMonth()->toDateString(),
            'end_date' => $today->toDateString(),
        ])['summary'];

        return [
            'net_profit' => $summary['net_profit'],
            'revenue' => $summary['revenue'],
            'expenses' => $summary['expenses'],
            'net_margin' => $summary['net_margin'],
            'uncosted_stock' => $summary['uncosted_stock'],
            'from' => $summary['from'],
            'to' => $summary['to'],
        ];
    }

    /**
     * What the cash and bank accounts hold today: each account's stored opening balance of the active financial
     * year plus its approved postings up to today, the way the Balance Sheet reads an account. The accounts are
     * the ones the payment screens offer (the children of the mapped Cash and Bank accounts).
     *
     * @return array{total: float, accounts: list<array{code: string, name: string, balance: float}>, as_of: string}
     */
    public function cashAndBank(int $companyId, ?int $branchId, CarbonInterface $today): array
    {
        $year = $this->figures->financialYear($companyId);
        [$from, $to] = $this->figures->range(null, $today->toDateString(), $year);

        $stored = $this->figures->stored($companyId, $branchId, $year);
        $net = $this->figures->net($companyId, $branchId, $from, $to);

        $accounts = ChartOfAccount::bankAndCashAccountOptions($companyId, $branchId)
            ->unique('code')
            ->map(fn (ChartOfAccount $account): array => [
                'code' => (string) $account->code,
                'name' => (string) $account->name,
                'balance' => round((float) ($stored[$account->code] ?? 0) + (float) ($net[$account->code] ?? 0), 2),
            ])
            ->values();

        return [
            'total' => round((float) $accounts->sum('balance'), 2),
            'accounts' => $accounts->reject(fn (array $account): bool => $account['balance'] == 0.0)->sortByDesc('balance')->take(self::LIST_SIZE)->values()->all(),
            'as_of' => $to,
        ];
    }

    /**
     * Active customers, active suppliers (a contact who is both counts in each) and active products.
     *
     * @param  list<string>  $only  the counts wanted: customers, suppliers, products
     * @return array<string, int>
     */
    public function stats(?int $companyId, ?int $branchId, array $only): array
    {
        $contacts = fn (string $kind): int => $this->scoped(
            Contact::query()->whereIn('user_type', [$kind, 'both'])->where('active', true),
            $companyId,
            $branchId,
        )->count();

        $counts = [];

        foreach ($only as $key) {
            $counts[$key] = match ($key) {
                'customers' => $contacts('customer'),
                'suppliers' => $contacts('supplier'),
                default => Product::query()->where('active', true)->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))->count(),
            };
        }

        return $counts;
    }

    /**
     * The latest sales, newest first, whatever their status (a draft shows as a draft).
     *
     * @return list<array<string, mixed>>
     */
    public function recentSales(?int $companyId, ?int $branchId): array
    {
        return $this->scoped(Transaction::query()->sells(), $companyId, $branchId)
            ->with('contact:id,business_name,first_name,last_name')
            ->orderByDesc('id')
            ->limit(self::LIST_SIZE)
            ->get(['id', 'contact_id', 'invoice_no', 'status', 'payment_status', 'transaction_date', 'final_amount'])
            ->map(fn (Transaction $sale): array => [
                'id' => $sale->id,
                'invoice_no' => $sale->invoice_no,
                'customer' => $sale->contact === null ? '-' : PartyOutstandingReport::contactName($sale->contact),
                'status' => $sale->status,
                'payment_status' => $sale->payment_status,
                'date' => $sale->transaction_date?->toDateString(),
                'amount' => round((float) $sale->final_amount, 2),
            ])
            ->all();
    }

    /**
     * @return array{net_sales: float, net_purchases: float}
     */
    private function summary(?int $companyId, ?int $branchId, CarbonInterface $from, CarbonInterface $to): array
    {
        $totals = $this->trade->build($companyId, $branchId, [
            'start_date' => $from->toDateString(),
            'end_date' => $to->toDateString(),
        ])['totals'];

        return ['net_sales' => (float) $totals['net_sales'], 'net_purchases' => (float) $totals['net_purchases']];
    }

    /**
     * The customers whose balance is at least CREDIT_WARNING_SHARE of their credit limit (a limit of 0 means no
     * limit, as everywhere else), the most used first.
     *
     * @param  Collection<int, array{id: int, contact_name: string, balance: float}>  $customers
     * @return array{near: int, exceeded: int, items: list<array<string, mixed>>}
     */
    private function creditWatch(Collection $customers): array
    {
        $limits = Contact::query()
            ->whereIn('id', $customers->where('balance', '>', 0)->pluck('id'))
            ->where('credit_limit', '>', 0)
            ->pluck('credit_limit', 'id');

        $watch = $customers
            ->filter(fn (array $customer): bool => $limits->has($customer['id']) && $customer['balance'] >= $limits[$customer['id']] * self::CREDIT_WARNING_SHARE)
            ->map(fn (array $customer): array => [
                'id' => $customer['id'],
                'name' => $customer['contact_name'],
                'balance' => $customer['balance'],
                'credit_limit' => round((float) $limits[$customer['id']], 2),
                'used_percent' => round($customer['balance'] / (float) $limits[$customer['id']] * 100, 1),
                'exceeded' => $customer['balance'] > (float) $limits[$customer['id']],
            ])
            ->sortByDesc('used_percent')
            ->values();

        return [
            'near' => $watch->where('exceeded', false)->count(),
            'exceeded' => $watch->where('exceeded', true)->count(),
            'items' => $watch->take(self::LIST_SIZE)->all(),
        ];
    }

    /**
     * @template TQuery of Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    private function scoped(Builder $query, ?int $companyId, ?int $branchId): Builder
    {
        return $query
            ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId));
    }

    private function change(float $current, float $previous): ?float
    {
        return $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null;
    }
}
