<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

/**
 * Purchase, purchase return, sell and sell return lists, one row per document.
 *
 * What counts follows the ledger (PurchaseJournal, SellJournal, ContactLedger) and StockMovements,
 * not the old issue/received status rule: every sell except a draft or quotation, every purchase
 * except a draft, and every return. Documents of other types (receiving notes, issue notes,
 * transfers) never appear. The paid amount is the sum of the document's payments, the same figure
 * Payment::paidAmountForTransaction uses, so due = final amount - paid.
 *
 * The date range is inclusive at both ends and is compared as a date, so a document at 23:59 on the
 * "to" day is in and one at 00:00 the next day is out.
 */
class TransactionListReport
{
    /**
     * @var array<string, array{type: string, excluded: list<string>, party: string}>
     */
    public const KINDS = [
        'purchase' => ['type' => Transaction::TYPE_PURCHASE, 'excluded' => ['draft'], 'party' => 'supplier'],
        'purchase-return' => ['type' => Transaction::TYPE_PURCHASE_RETURN, 'excluded' => [], 'party' => 'supplier'],
        'sell' => ['type' => Transaction::TYPE_SELL, 'excluded' => Transaction::UNPOSTED_SELL_STATUSES, 'party' => 'customer'],
        'sell-return' => ['type' => Transaction::TYPE_SELL_RETURN, 'excluded' => [], 'party' => 'customer'],
    ];

    public const SORTABLE = [
        'transaction_date' => 't.transaction_date',
        'invoice_no' => 't.invoice_no',
        'sup_ref_no' => 't.sup_ref_no',
        'contact_name' => 'c.business_name',
        'branch_name' => 'b.name',
        'company_name' => 'co.name',
        'status' => 't.status',
        'payment_status' => 't.payment_status',
        'total_before_tax' => 't.total_before_tax',
        'tax_amount' => 't.tax_amount',
        'discount_amount' => 't.discount_amount',
        'shipping_charges' => 't.shipping_charges',
        'final_amount' => 't.final_amount',
        'paid' => 'paid',
        'due' => 'due',
    ];

    /**
     * @param  array{contact_id?: mixed, status?: ?string, payment_status?: ?string, start_date?: ?string, end_date?: ?string, search?: ?string}  $filters
     */
    public function query(string $kind, ?int $companyId, ?int $branchId, array $filters = []): Builder
    {
        return $this->base($kind, $companyId, $branchId, $filters)->select([
            't.id',
            't.transaction_date',
            't.invoice_no',
            't.sup_ref_no',
            'parent.invoice_no as parent_invoice_no',
            't.status',
            't.payment_status',
            't.total_before_tax',
            't.tax_amount',
            't.discount_amount',
            't.shipping_charges',
            't.final_amount',
            'c.business_name',
            'c.first_name',
            'c.last_name',
            'b.name as branch_name',
            'co.name as company_name',
            DB::raw('coalesce(pay.paid, 0) as paid'),
            DB::raw('(t.final_amount - coalesce(pay.paid, 0)) as due'),
        ]);
    }

    /**
     * Totals over the whole filtered set, not just the page on screen.
     *
     * @param  array{contact_id?: mixed, status?: ?string, payment_status?: ?string, start_date?: ?string, end_date?: ?string, search?: ?string}  $filters
     * @return array{count: int, total_before_tax: float, tax_amount: float, discount_amount: float, shipping_charges: float, final_amount: float, paid: float, due: float}
     */
    public function summary(string $kind, ?int $companyId, ?int $branchId, array $filters = []): array
    {
        $row = $this->base($kind, $companyId, $branchId, $filters)
            ->selectRaw('count(*) as documents')
            ->selectRaw('coalesce(sum(t.total_before_tax), 0) as total_before_tax')
            ->selectRaw('coalesce(sum(t.tax_amount), 0) as tax_amount')
            ->selectRaw('coalesce(sum(t.discount_amount), 0) as discount_amount')
            ->selectRaw('coalesce(sum(t.shipping_charges), 0) as shipping_charges')
            ->selectRaw('coalesce(sum(t.final_amount), 0) as final_amount')
            ->selectRaw('coalesce(sum(coalesce(pay.paid, 0)), 0) as paid')
            ->first();

        $final = round((float) $row->final_amount, 2);
        $paid = round((float) $row->paid, 2);

        return [
            'count' => (int) $row->documents,
            'total_before_tax' => round((float) $row->total_before_tax, 2),
            'tax_amount' => round((float) $row->tax_amount, 2),
            'discount_amount' => round((float) $row->discount_amount, 2),
            'shipping_charges' => round((float) $row->shipping_charges, 2),
            'final_amount' => $final,
            'paid' => $paid,
            'due' => round($final - $paid, 2),
        ];
    }

    public static function contactName(stdClass $row): string
    {
        $business = trim((string) ($row->business_name ?? ''));

        if ($business !== '') {
            return $business;
        }

        $person = trim(trim((string) ($row->first_name ?? '')).' '.trim((string) ($row->last_name ?? '')));

        return $person !== '' ? $person : '-';
    }

    /**
     * @param  array{contact_id?: mixed, status?: ?string, payment_status?: ?string, start_date?: ?string, end_date?: ?string, search?: ?string}  $filters
     */
    private function base(string $kind, ?int $companyId, ?int $branchId, array $filters): Builder
    {
        $config = self::KINDS[$kind] ?? throw new InvalidArgumentException("Unknown transaction report [{$kind}].");

        $contactId = $filters['contact_id'] ?? null;
        $status = trim((string) ($filters['status'] ?? ''));
        $paymentStatus = trim((string) ($filters['payment_status'] ?? ''));
        $startDate = trim((string) ($filters['start_date'] ?? ''));
        $endDate = trim((string) ($filters['end_date'] ?? ''));
        $search = trim((string) ($filters['search'] ?? ''));

        $paid = DB::table('payments')
            ->select('transaction_id', DB::raw('sum(amount) as paid'))
            ->groupBy('transaction_id');

        return DB::table('transactions as t')
            ->leftJoinSub($paid, 'pay', 'pay.transaction_id', '=', 't.id')
            ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->leftJoin('branches as b', 'b.id', '=', 't.branch_id')
            ->leftJoin('companies as co', 'co.id', '=', 't.company_id')
            ->leftJoin('transactions as parent', 'parent.id', '=', 't.parent_id')
            ->where('t.type', $config['type'])
            ->whereNull('t.deleted_at')
            ->when($config['excluded'] !== [], fn (Builder $query) => $query->whereNotIn('t.status', $config['excluded']))
            ->when($companyId !== null, fn (Builder $query) => $query->where('t.company_id', $companyId))
            ->when($branchId !== null, fn (Builder $query) => $query->where('t.branch_id', $branchId))
            ->when(! empty($contactId), fn (Builder $query) => $query->where('t.contact_id', $contactId))
            ->when($status !== '' && $status !== 'all', fn (Builder $query) => $query->where('t.status', $status))
            ->when($paymentStatus !== '' && $paymentStatus !== 'all', fn (Builder $query) => $query->where('t.payment_status', $paymentStatus))
            ->when($startDate !== '', fn (Builder $query) => $query->whereDate('t.transaction_date', '>=', $startDate))
            ->when($endDate !== '', fn (Builder $query) => $query->whereDate('t.transaction_date', '<=', $endDate))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $sub) use ($search): void {
                $sub->where('t.invoice_no', 'like', "%{$search}%")
                    ->orWhere('t.sup_ref_no', 'like', "%{$search}%")
                    ->orWhere('c.business_name', 'like', "%{$search}%")
                    ->orWhere('c.first_name', 'like', "%{$search}%")
                    ->orWhere('c.last_name', 'like', "%{$search}%");
            }));
    }
}
