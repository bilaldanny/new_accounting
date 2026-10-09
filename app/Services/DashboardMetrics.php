<?php

namespace App\Services;

use App\Models\CashCollection;
use App\Models\ChartOfAccount;
use App\Models\Contact;
use App\Models\CreditLimitRequest;
use App\Models\Payment;
use App\Models\PriceList;
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
use Illuminate\Support\Carbon;
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
        private readonly SalesForecast $forecast,
        private readonly UpcomingChequesReport $cheques,
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
     * The estimate of next month's sales from the recent months (see SalesForecast).
     *
     * @return array<string, mixed>
     */
    public function salesForecast(?int $companyId, ?int $branchId, CarbonInterface $today): array
    {
        return $this->forecast->forCompany($companyId, $branchId, $today);
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
     * Post-dated cheques (payments of method `cheque` with a `cheque_date`) not yet in the past, soonest first.
     *
     * @param  'given'|'received'  $direction
     * @return array{count: int, items: list<array<string, mixed>>}
     */
    public function upcomingCheques(?int $companyId, ?int $branchId, CarbonInterface $today, string $direction): array
    {
        $query = $this->cheques->query($companyId, $branchId, $today->toDateString(), $direction);

        return [
            'count' => DB::query()->fromSub($query, 'chq')->count(),
            'items' => (clone $query)
                ->orderBy('p.cheque_date')
                ->limit(self::LIST_SIZE)
                ->get()
                ->map(fn (object $row): array => [
                    'payment_id' => (int) $row->payment_id,
                    'cheque_number' => $row->cheque_number,
                    'cheque_date' => $row->cheque_date,
                    'amount' => round((float) $row->amount, 2),
                    'is_return' => (bool) $row->is_return,
                    'invoice_no' => $row->invoice_no,
                    'transaction_type' => $row->transaction_type,
                    'contact_name' => trim((string) $row->business_name) !== '' ? $row->business_name : trim(($row->first_name ?? '').' '.($row->last_name ?? '')),
                    'branch_name' => $row->branch_name,
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
                'purchasereturn' => $this->scoped(Transaction::query()->purchaseReturns()->where('status', 'pending'), $companyId, $branchId)->count(),
                'stockadjustment' => $this->scoped(Transaction::query()->adjustments()->where('status', 'pending'), $companyId, $branchId)->count(),
                'stocktransfer' => $this->scoped(Transaction::query()->transfers()->where('status', 'pending'), $companyId, $branchId)->count(),
                'cashcollection' => $this->scoped(CashCollection::query()->where('status', CashCollection::STATUS_PENDING), $companyId, $branchId)->count(),
                'pricelist' => $this->scoped(PriceList::query()->where('status', 'pending'), $companyId, $branchId)->count(),
                'creditlimit' => $this->scoped(CreditLimitRequest::query()->where('status', CreditLimitRequest::STATUS_PENDING), $companyId, $branchId)->count(),
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
            'cogs' => $summary['cogs'],
            'gross_profit' => $summary['gross_profit'],
            'gross_margin' => $summary['revenue'] > 0 ? round($summary['gross_profit'] / $summary['revenue'] * 100, 2) : 0.0,
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
     * Net sales and net purchases for each of the last `$months` calendar months (oldest first), and, when one
     * company is covered, each month's net profit (the Profit & Loss report over that month).
     *
     * @return list<array{month: string, sales: float, purchases: float}>
     */
    public function trends(?int $companyId, ?int $branchId, CarbonInterface $today, int $months = 6): array
    {
        $rows = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $start = $today->copy()->startOfMonth()->subMonthsNoOverflow($i);
            $end = $i === 0 ? $today->copy() : $start->copy()->endOfMonth();
            $figures = $this->summary($companyId, $branchId, $start, $end);

            $rows[] = ['month' => $start->format('Y-m'), 'sales' => $figures['net_sales'], 'purchases' => $figures['net_purchases']];
        }

        return $rows;
    }

    /**
     * Net profit for each of the last `$months` calendar months (oldest first). One company only.
     *
     * @return list<array{month: string, net_profit: float}>
     */
    public function profitTrend(int $companyId, ?int $branchId, CarbonInterface $today, int $months = 6): array
    {
        $rows = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $start = $today->copy()->startOfMonth()->subMonthsNoOverflow($i);
            $end = $i === 0 ? $today->copy() : $start->copy()->endOfMonth();

            $summary = $this->profitLoss->build($companyId, $branchId, [
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ])['summary'];

            $rows[] = ['month' => $start->format('Y-m'), 'net_profit' => (float) $summary['net_profit']];
        }

        return $rows;
    }

    /**
     * What is still unpaid on invoices, by how old the invoice is: 0-30, 31-60, 61-90 and over 90 days. Sales
     * (not drafts or quotations) for receivables, approved or received purchases for payables. This is by invoice
     * date and what its payments cover, so it can differ from the contact ledger total, which also holds
     * opening balances and advances.
     *
     * @param  'sell'|'purchaseorder'  $type
     * @return array{buckets: list<array{label: string, amount: float, count: int}>, total: float}
     */
    public function aging(?int $companyId, ?int $branchId, CarbonInterface $today, string $type): array
    {
        $paid = DB::table('payments')
            ->selectRaw('transaction_id, SUM(CASE WHEN is_return = 1 THEN -amount ELSE amount END) as paid')
            ->groupBy('transaction_id');

        $invoices = $this->scoped(Transaction::query()->where('type', $type), $companyId, $branchId)
            ->when($type === Transaction::TYPE_SELL, fn (Builder $query) => $query->whereNotIn('status', Transaction::UNPOSTED_SELL_STATUSES))
            ->when($type !== Transaction::TYPE_SELL, fn (Builder $query) => $query->whereIn('status', ['approved', 'received']))
            ->leftJoinSub($paid, 'pd', 'pd.transaction_id', '=', 'transactions.id')
            ->get(['transactions.id', 'transactions.transaction_date', 'transactions.final_amount', DB::raw('COALESCE(pd.paid, 0) as paid')]);

        $buckets = [
            ['label' => '0-30 days', 'max' => 30, 'amount' => 0.0, 'count' => 0],
            ['label' => '31-60 days', 'max' => 60, 'amount' => 0.0, 'count' => 0],
            ['label' => '61-90 days', 'max' => 90, 'amount' => 0.0, 'count' => 0],
            ['label' => 'Over 90 days', 'max' => PHP_INT_MAX, 'amount' => 0.0, 'count' => 0],
        ];

        foreach ($invoices as $invoice) {
            $due = round((float) $invoice->final_amount - (float) $invoice->paid, 2);

            if ($due <= 0 || $invoice->transaction_date === null) {
                continue;
            }

            $age = (int) Carbon::parse($invoice->transaction_date)->startOfDay()->diffInDays($today->copy()->startOfDay(), false);

            foreach ($buckets as $index => $bucket) {
                if ($age <= $bucket['max']) {
                    $buckets[$index]['amount'] = round($bucket['amount'] + $due, 2);
                    $buckets[$index]['count']++;

                    break;
                }
            }
        }

        return [
            'buckets' => array_map(fn (array $bucket): array => ['label' => $bucket['label'], 'amount' => $bucket['amount'], 'count' => $bucket['count']], $buckets),
            'total' => round(array_sum(array_column($buckets, 'amount')), 2),
        ];
    }

    /**
     * The best sellers of the last 30 days (by quantity sold) next to the dead stock: products with stock on
     * hand that have not sold at all in the last 90 days. Expiry warnings come from the batch-tracked products: the
     * batches that have stock and are expired or expire within 30 days (the earliest first), with how many of each.
     *
     * @return array{top_selling: list<array<string, mixed>>, dead_stock: list<array<string, mixed>>, expiring: list<array<string, mixed>>, expiry_counts: array{expired: int, expiring: int}}
     */
    public function inventoryMovement(?int $companyId, ?int $branchId, CarbonInterface $today): array
    {
        $sold = fn (int $days) => DB::table('sell_lines as sl')
            ->join('transactions as t', 't.id', '=', 'sl.transaction_id')
            ->where('t.type', Transaction::TYPE_SELL)
            ->whereNull('t.deleted_at')
            ->whereNotIn('t.status', Transaction::UNPOSTED_SELL_STATUSES)
            ->whereDate('t.transaction_date', '>=', $today->copy()->subDays($days)->toDateString())
            ->when($companyId !== null, fn ($query) => $query->where('t.company_id', $companyId))
            ->when($branchId !== null, fn ($query) => $query->where('t.branch_id', $branchId));

        $top = (clone $sold(30))
            ->join('products as p', 'p.id', '=', 'sl.product_id')
            ->groupBy('sl.product_id', 'p.name')
            ->orderByDesc(DB::raw('SUM(sl.quantity)'))
            ->limit(self::LIST_SIZE)
            ->get(['sl.product_id', 'p.name', DB::raw('SUM(sl.quantity) as quantity')])
            ->map(fn (object $row): array => ['product_id' => (int) $row->product_id, 'name' => (string) $row->name, 'quantity' => round((float) $row->quantity, 2)])
            ->all();

        $recentlySold = (clone $sold(90))->whereNotNull('sl.product_id')->select('sl.product_id');

        $stock = DB::query()
            ->fromSub(StockMovements::query(), 'm')
            ->when($branchId !== null, fn ($query) => $query->where('m.branch_id', $branchId))
            ->groupBy('m.product_id')
            ->select('m.product_id', DB::raw('SUM(m.qty) as stock'));

        $dead = DB::query()
            ->fromSub($stock, 's')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->whereNull('p.deleted_at')
            ->where('p.active', 1)
            ->where('s.stock', '>', 0)
            ->when($companyId !== null, fn ($query) => $query->where('p.company_id', $companyId))
            ->whereNotIn('p.id', $recentlySold)
            ->orderByDesc('s.stock')
            ->limit(self::LIST_SIZE)
            ->get(['p.id as product_id', 'p.name', 's.stock'])
            ->map(fn (object $row): array => ['product_id' => (int) $row->product_id, 'name' => (string) $row->name, 'stock' => round((float) $row->stock, 2)])
            ->all();

        return ['top_selling' => $top, 'dead_stock' => $dead] + $this->expiringBatches($companyId, $branchId, $today);
    }

    /**
     * @return array{expiring: list<array<string, mixed>>, expiry_counts: array{expired: int, expiring: int}}
     */
    private function expiringBatches(?int $companyId, ?int $branchId, CarbonInterface $today): array
    {
        if (! StockTracking::enabled()) {
            return ['expiring' => [], 'expiry_counts' => ['expired' => 0, 'expiring' => 0]];
        }

        $soon = $today->copy()->addDays(30)->toDateString();
        $batches = DB::query()
            ->fromSub(StockTracking::batchStock(), 'x')
            ->join('products as p', 'p.id', '=', 'x.product_id')
            ->where('x.qty', '>', 0)
            ->whereNotNull('x.expiry_date')
            ->whereDate('x.expiry_date', '<=', $soon)
            ->when($companyId !== null, fn ($query) => $query->where('x.company_id', $companyId))
            ->when($branchId !== null, fn ($query) => $query->where('x.branch_id', $branchId))
            ->orderBy('x.expiry_date')
            ->get(['x.batch_id', 'x.branch_id', 'x.batch_no', 'x.expiry_date', 'x.qty', 'p.name']);

        $day = $today->copy()->startOfDay();
        $rows = $batches->map(function (object $row) use ($day): array {
            $expiry = Carbon::parse(substr((string) $row->expiry_date, 0, 10))->startOfDay();

            return ['batch_id' => (int) $row->batch_id, 'branch_id' => (int) $row->branch_id, 'name' => (string) $row->name, 'batch_no' => (string) $row->batch_no, 'expiry_date' => $expiry->toDateString(), 'days_left' => (int) $day->diffInDays($expiry, false), 'qty' => round((float) $row->qty, 2)];
        });

        return [
            'expiring' => $rows->take(self::LIST_SIZE)->values()->all(),
            'expiry_counts' => ['expired' => $rows->where('days_left', '<', 0)->count(), 'expiring' => $rows->where('days_left', '>=', 0)->count()],
        ];
    }

    /**
     * The latest payments received or made, newest first (a return shows as a return).
     *
     * @return list<array<string, mixed>>
     */
    public function recentPayments(?int $companyId, ?int $branchId): array
    {
        return Payment::query()
            ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->with(['transaction:id,invoice_no,type', 'contact:id,business_name,first_name,last_name'])
            ->orderByDesc('id')
            ->limit(self::LIST_SIZE)
            ->get()
            ->map(fn (Payment $payment): array => [
                'id' => $payment->id,
                'invoice_no' => $payment->transaction?->invoice_no,
                'direction' => $payment->transaction?->type === Transaction::TYPE_SELL ? 'received' : 'paid',
                'contact' => $payment->contact === null ? '-' : PartyOutstandingReport::contactName($payment->contact),
                'method' => $payment->method,
                'is_return' => (bool) $payment->is_return,
                'date' => $payment->paid_on,
                'amount' => round((float) $payment->amount, 2),
            ])
            ->all();
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
