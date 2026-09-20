<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * One row per customer or supplier: what was bought from them, sold to them, returned either way,
 * and what is still to be received or paid, for documents dated inside the range (inclusive).
 *
 * Both dues use the same sign: a positive number is money still to move in the direction the column
 * names, a negative one is an overpayment or a credit. The old report showed the customer's due as
 * "sold minus received" but the supplier's as "paid minus bought", so the same situation read positive
 * for one and negative for the other.
 *
 *   receivable due = sales - sell returns - received on sales
 *   payable due    = purchases - purchase returns - paid on purchases
 *
 * That is the same arithmetic ContactLedger does for a contact without journal postings, so for a
 * customer or supplier this due equals the ledger balance over the same documents. Payments recorded
 * on a return document are not counted, as in the ledger. A contact who is both customer and supplier
 * has both dues on one row; nothing is netted across the two sides. Drafts and quotations are not
 * documents yet and are left out; contacts with no document in the range are not listed.
 */
class PartySummaryReport
{
    public const TYPES = ['all', 'customer', 'supplier'];

    public const SORTABLE = [
        'contact_name' => 'contact_name',
        'code' => 'code',
        'user_type' => 'user_type',
        'group_name' => 'group_name',
        'purchases' => 'purchases',
        'purchase_returns' => 'purchase_returns',
        'sales' => 'sales',
        'sell_returns' => 'sell_returns',
        'received' => 'received',
        'paid' => 'paid',
        'receivable_due' => 'receivable_due',
        'payable_due' => 'payable_due',
    ];

    /**
     * @param  array{contact_id?: mixed, contact_type?: ?string, customer_group_id?: mixed, start_date?: ?string, end_date?: ?string, search?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters = []): Collection
    {
        $paid = DB::table('payments')
            ->select('transaction_id', DB::raw('sum(amount) as paid'))
            ->groupBy('transaction_id');

        return $this->base($companyId, $branchId, $filters)
            ->leftJoinSub($paid, 'pay', 'pay.transaction_id', '=', 't.id')
            ->groupBy('c.id', 'c.business_name', 'c.first_name', 'c.last_name', 'c.code', 'c.user_type', 'c.customer_group_id', 'g.name')
            ->select([
                'c.id',
                'c.business_name',
                'c.first_name',
                'c.last_name',
                'c.code',
                'c.user_type',
                'g.name as group_name',
            ])
            ->selectRaw('coalesce(sum(case when t.type = ? then t.final_amount else 0 end), 0) as purchases', [Transaction::TYPE_PURCHASE])
            ->selectRaw('coalesce(sum(case when t.type = ? then t.final_amount else 0 end), 0) as purchase_returns', [Transaction::TYPE_PURCHASE_RETURN])
            ->selectRaw('coalesce(sum(case when t.type = ? then t.final_amount else 0 end), 0) as sales', [Transaction::TYPE_SELL])
            ->selectRaw('coalesce(sum(case when t.type = ? then t.final_amount else 0 end), 0) as sell_returns', [Transaction::TYPE_SELL_RETURN])
            ->selectRaw('coalesce(sum(case when t.type = ? then coalesce(pay.paid, 0) else 0 end), 0) as received', [Transaction::TYPE_SELL])
            ->selectRaw('coalesce(sum(case when t.type = ? then coalesce(pay.paid, 0) else 0 end), 0) as paid', [Transaction::TYPE_PURCHASE])
            ->get()
            ->map(function (stdClass $row): array {
                $purchases = round((float) $row->purchases, 2);
                $purchaseReturns = round((float) $row->purchase_returns, 2);
                $sales = round((float) $row->sales, 2);
                $sellReturns = round((float) $row->sell_returns, 2);
                $received = round((float) $row->received, 2);
                $paidOut = round((float) $row->paid, 2);

                return [
                    'id' => (int) $row->id,
                    'contact_name' => TransactionListReport::contactName($row),
                    'code' => (string) $row->code,
                    'user_type' => (string) $row->user_type,
                    'group_name' => (string) ($row->group_name ?? ''),
                    'purchases' => $purchases,
                    'purchase_returns' => $purchaseReturns,
                    'sales' => $sales,
                    'sell_returns' => $sellReturns,
                    'received' => $received,
                    'paid' => $paidOut,
                    'receivable_due' => round($sales - $sellReturns - $received, 2),
                    'payable_due' => round($purchases - $purchaseReturns - $paidOut, 2),
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        $total = fn (string $key): float => round((float) $rows->sum($key), 2);

        return [
            'count' => $rows->count(),
            'purchases' => $total('purchases'),
            'purchase_returns' => $total('purchase_returns'),
            'sales' => $total('sales'),
            'sell_returns' => $total('sell_returns'),
            'receivable_due' => $total('receivable_due'),
            'payable_due' => $total('payable_due'),
        ];
    }

    /**
     * @param  array{contact_id?: mixed, contact_type?: ?string, customer_group_id?: mixed, start_date?: ?string, end_date?: ?string, search?: ?string}  $filters
     */
    private function base(?int $companyId, ?int $branchId, array $filters): Builder
    {
        $contactId = $filters['contact_id'] ?? null;
        $type = trim((string) ($filters['contact_type'] ?? ''));
        $groupId = $filters['customer_group_id'] ?? null;
        $startDate = trim((string) ($filters['start_date'] ?? ''));
        $endDate = trim((string) ($filters['end_date'] ?? ''));
        $search = trim((string) ($filters['search'] ?? ''));

        return DB::table('contacts as c')
            ->join('transactions as t', 't.contact_id', '=', 'c.id')
            ->leftJoin('customer_groups as g', 'g.id', '=', 'c.customer_group_id')
            ->whereNull('c.deleted_at')
            ->whereNull('t.deleted_at')
            ->where(function (Builder $documents): void {
                $documents->where(fn (Builder $purchase) => $purchase
                    ->where('t.type', Transaction::TYPE_PURCHASE)
                    ->where('t.status', '!=', 'draft'))
                    ->orWhere(fn (Builder $sell) => $sell
                        ->where('t.type', Transaction::TYPE_SELL)
                        ->whereNotIn('t.status', Transaction::UNPOSTED_SELL_STATUSES))
                    ->orWhereIn('t.type', [Transaction::TYPE_PURCHASE_RETURN, Transaction::TYPE_SELL_RETURN]);
            })
            ->when($companyId !== null, fn (Builder $query) => $query->where('t.company_id', $companyId))
            ->when($branchId !== null, fn (Builder $query) => $query->where('t.branch_id', $branchId))
            ->when(! empty($contactId), fn (Builder $query) => $query->where('c.id', $contactId))
            ->when(in_array($type, ['customer', 'supplier'], true), fn (Builder $query) => $query->whereIn('c.user_type', [$type, 'both']))
            ->when(! empty($groupId), fn (Builder $query) => $query->where('c.customer_group_id', $groupId))
            ->when($startDate !== '', fn (Builder $query) => $query->whereDate('t.transaction_date', '>=', $startDate))
            ->when($endDate !== '', fn (Builder $query) => $query->whereDate('t.transaction_date', '<=', $endDate))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $sub) use ($search): void {
                $sub->where('c.business_name', 'like', "%{$search}%")
                    ->orWhere('c.first_name', 'like', "%{$search}%")
                    ->orWhere('c.last_name', 'like', "%{$search}%")
                    ->orWhere('c.code', 'like', "%{$search}%");
            }));
    }
}
