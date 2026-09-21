<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The purchase and sale summary: what was bought and sold in the range, what came back, what was paid
 * or received and what is still due, then the two sides together.
 *
 * Returns are taken off on both sides: net sales = sales - sell returns and net purchases = purchases -
 * purchase returns. (The old report added the returns and read the return type as `salereturn`, so sell
 * returns never showed at all.) A due is the net amount less the payments on the purchase or sale
 * documents, the same figure the party reports give; payments on return documents are not counted.
 * Documents count like everywhere else: every sell except a draft or quotation, every purchase except a
 * draft, returns always; the range is on the document date, inclusive at both ends.
 */
class PurchaseSaleSummary
{
    public const SORTABLE = [
        'id' => 'id',
        'section' => 'section',
        'label' => 'label',
        'amount' => 'amount',
    ];

    /**
     * @param  array{start_date?: ?string, end_date?: ?string}  $filters
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, float|int>}
     */
    public function build(?int $companyId, ?int $branchId, array $filters = []): array
    {
        $purchases = $this->totals(Transaction::TYPE_PURCHASE, ['draft'], $companyId, $branchId, $filters);
        $purchaseReturns = $this->totals(Transaction::TYPE_PURCHASE_RETURN, [], $companyId, $branchId, $filters);
        $sales = $this->totals(Transaction::TYPE_SELL, Transaction::UNPOSTED_SELL_STATUSES, $companyId, $branchId, $filters);
        $sellReturns = $this->totals(Transaction::TYPE_SELL_RETURN, [], $companyId, $branchId, $filters);

        $netPurchases = round($purchases['final'] - $purchaseReturns['final'], 2);
        $netSales = round($sales['final'] - $sellReturns['final'], 2);
        $purchaseDue = round($netPurchases - $purchases['paid'], 2);
        $salesDue = round($netSales - $sales['paid'], 2);

        $lines = [
            ['Purchases', 'Purchase documents', $purchases['count'], true],
            ['Purchases', 'Purchases before tax', $purchases['before_tax']],
            ['Purchases', 'Purchase tax', $purchases['tax']],
            ['Purchases', 'Purchases including tax', $purchases['final']],
            ['Purchases', 'Purchase returns', $purchaseReturns['final']],
            ['Purchases', 'Net purchases', $netPurchases],
            ['Purchases', 'Paid on purchases', $purchases['paid']],
            ['Purchases', 'Purchase due', $purchaseDue],
            ['Sales', 'Sale documents', $sales['count'], true],
            ['Sales', 'Sales before tax', $sales['before_tax']],
            ['Sales', 'Sale tax', $sales['tax']],
            ['Sales', 'Sales including tax', $sales['final']],
            ['Sales', 'Sell returns', $sellReturns['final']],
            ['Sales', 'Net sales', $netSales],
            ['Sales', 'Received on sales', $sales['paid']],
            ['Sales', 'Sales due', $salesDue],
            ['Overall', 'Net sales less net purchases', round($netSales - $netPurchases, 2)],
            ['Overall', 'Sales due less purchase due', round($salesDue - $purchaseDue, 2)],
        ];

        return [
            'rows' => collect($lines)->map(fn (array $line, int $index): array => [
                'id' => $index + 1,
                'section' => $line[0],
                'label' => $line[1],
                'amount' => (float) $line[2],
                'is_count' => ($line[3] ?? false) === true,
            ]),
            'totals' => [
                'net_purchases' => $netPurchases,
                'net_sales' => $netSales,
                'purchase_due' => $purchaseDue,
                'sales_due' => $salesDue,
                'overall' => round($netSales - $netPurchases, 2),
                'overall_due' => round($salesDue - $purchaseDue, 2),
            ],
        ];
    }

    /**
     * @param  list<string>  $excluded
     * @param  array{start_date?: ?string, end_date?: ?string}  $filters
     * @return array{count: int, before_tax: float, tax: float, final: float, paid: float}
     */
    private function totals(string $type, array $excluded, ?int $companyId, ?int $branchId, array $filters): array
    {
        $startDate = trim((string) ($filters['start_date'] ?? ''));
        $endDate = trim((string) ($filters['end_date'] ?? ''));

        $paid = DB::table('payments')
            ->select('transaction_id', DB::raw('sum(amount) as paid'))
            ->groupBy('transaction_id');

        $row = DB::table('transactions as t')
            ->leftJoinSub($paid, 'pay', 'pay.transaction_id', '=', 't.id')
            ->where('t.type', $type)
            ->whereNull('t.deleted_at')
            ->when($excluded !== [], fn (Builder $query) => $query->whereNotIn('t.status', $excluded))
            ->when($companyId !== null, fn (Builder $query) => $query->where('t.company_id', $companyId))
            ->when($branchId !== null, fn (Builder $query) => $query->where('t.branch_id', $branchId))
            ->when($startDate !== '', fn (Builder $query) => $query->whereDate('t.transaction_date', '>=', $startDate))
            ->when($endDate !== '', fn (Builder $query) => $query->whereDate('t.transaction_date', '<=', $endDate))
            ->selectRaw('count(*) as documents')
            ->selectRaw('coalesce(sum(t.total_before_tax), 0) as before_tax')
            ->selectRaw('coalesce(sum(t.tax_amount), 0) as tax')
            ->selectRaw('coalesce(sum(t.final_amount), 0) as final_amount')
            ->selectRaw('coalesce(sum(coalesce(pay.paid, 0)), 0) as paid')
            ->first();

        return [
            'count' => (int) $row->documents,
            'before_tax' => round((float) $row->before_tax, 2),
            'tax' => round((float) $row->tax, 2),
            'final' => round((float) $row->final_amount, 2),
            'paid' => round((float) $row->paid, 2),
        ];
    }
}
