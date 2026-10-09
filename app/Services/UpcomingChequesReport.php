<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Post-dated cheques still to clear: `payments` of method `cheque` that carry a `cheque_date`, the day
 * the cheque is meant to be presented (separate from `paid_on`, when it was received). There is no
 * "cleared" flag yet, so every dated cheque payment is listed, oldest date first; a company using this
 * only cares about ones still ahead, so the query can be limited to a date not in the past.
 *
 * `given` (paid to a supplier, against a purchase) and `received` (from a customer, against a sale) are
 * kept separate because they are gated by different menu permissions (Purchase Payment / Sell Payment).
 */
class UpcomingChequesReport
{
    /**
     * @param  'given'|'received'|null  $direction
     */
    public function query(?int $companyId, ?int $branchId, ?string $from = null, ?string $direction = null): Builder
    {
        return DB::query()
            ->from('payments as p')
            ->join('transactions as t', 't.id', '=', 'p.transaction_id')
            ->leftJoin('contacts as ct', 'ct.id', '=', 'p.contact_id')
            ->leftJoin('branches as b', 'b.id', '=', 'p.branch_id')
            ->where('p.method', 'cheque')
            ->whereNotNull('p.cheque_date')
            ->when($companyId !== null, fn (Builder $query) => $query->where('p.company_id', $companyId))
            ->when($branchId !== null, fn (Builder $query) => $query->where('p.branch_id', $branchId))
            ->when($from !== null, fn (Builder $query) => $query->where('p.cheque_date', '>=', $from))
            ->when($direction === 'given', fn (Builder $query) => $query->where('t.type', Transaction::TYPE_PURCHASE))
            ->when($direction === 'received', fn (Builder $query) => $query->where('t.type', Transaction::TYPE_SELL))
            ->select([
                'p.id as payment_id',
                'p.cheque_number',
                'p.cheque_date',
                'p.amount',
                'p.is_return',
                'p.payment_ref_no',
                't.id as transaction_id',
                't.invoice_no',
                't.type as transaction_type',
                'ct.business_name',
                'ct.first_name',
                'ct.last_name',
                'b.name as branch_name',
            ]);
    }
}
