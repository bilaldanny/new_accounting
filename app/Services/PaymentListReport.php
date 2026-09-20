<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Purchase payment and sell payment lists, one row per payment.
 *
 * The date range filters on the day the payment was made (`payments.paid_on`), not on the date of
 * the invoice it settles; the old report used the invoice date. `paid_on` is a `Y-m-d H:i` string,
 * so its first ten characters are the day, and the range is inclusive at both ends. Payments on a
 * draft or quotation sell, or a draft purchase, are left out like the document itself.
 */
class PaymentListReport
{
    /**
     * @var array<string, array{type: string, excluded: list<string>}>
     */
    public const KINDS = [
        'purchase-payment' => ['type' => Transaction::TYPE_PURCHASE, 'excluded' => ['draft']],
        'sell-payment' => ['type' => Transaction::TYPE_SELL, 'excluded' => Transaction::UNPOSTED_SELL_STATUSES],
    ];

    public const SORTABLE = [
        'paid_on' => 'p.paid_on',
        'payment_ref_no' => 'p.payment_ref_no',
        'invoice_no' => 't.invoice_no',
        'contact_name' => 'c.business_name',
        'branch_name' => 'b.name',
        'company_name' => 'co.name',
        'method' => 'p.method',
        'amount' => 'p.amount',
    ];

    /**
     * @param  array{contact_id?: mixed, method?: ?string, start_date?: ?string, end_date?: ?string, search?: ?string}  $filters
     */
    public function query(string $kind, ?int $companyId, ?int $branchId, array $filters = []): Builder
    {
        return $this->base($kind, $companyId, $branchId, $filters)->select([
            'p.id',
            'p.paid_on',
            'p.payment_ref_no',
            'p.method',
            'p.amount',
            'p.cheque_number',
            'p.bank_account_number',
            'p.note',
            't.invoice_no',
            't.sup_ref_no',
            't.final_amount as invoice_amount',
            't.payment_status',
            'c.business_name',
            'c.first_name',
            'c.last_name',
            'b.name as branch_name',
            'co.name as company_name',
        ]);
    }

    /**
     * @param  array{contact_id?: mixed, method?: ?string, start_date?: ?string, end_date?: ?string, search?: ?string}  $filters
     * @return array{count: int, total_amount: float, by_method: array<string, float>}
     */
    public function summary(string $kind, ?int $companyId, ?int $branchId, array $filters = []): array
    {
        $byMethod = $this->base($kind, $companyId, $branchId, $filters)
            ->selectRaw('p.method as method')
            ->selectRaw('count(*) as payments')
            ->selectRaw('coalesce(sum(p.amount), 0) as amount')
            ->groupBy('p.method')
            ->get();

        $methods = array_fill_keys(Payment::METHODS, 0.0);

        foreach ($byMethod as $row) {
            $methods[(string) $row->method] = round((float) $row->amount, 2);
        }

        return [
            'count' => (int) $byMethod->sum('payments'),
            'total_amount' => round((float) array_sum($methods), 2),
            'by_method' => $methods,
        ];
    }

    /**
     * @param  array{contact_id?: mixed, method?: ?string, start_date?: ?string, end_date?: ?string, search?: ?string}  $filters
     */
    private function base(string $kind, ?int $companyId, ?int $branchId, array $filters): Builder
    {
        $config = self::KINDS[$kind] ?? throw new InvalidArgumentException("Unknown payment report [{$kind}].");

        $contactId = $filters['contact_id'] ?? null;
        $method = trim((string) ($filters['method'] ?? ''));
        $startDate = trim((string) ($filters['start_date'] ?? ''));
        $endDate = trim((string) ($filters['end_date'] ?? ''));
        $search = trim((string) ($filters['search'] ?? ''));

        return DB::table('payments as p')
            ->join('transactions as t', 't.id', '=', 'p.transaction_id')
            ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->leftJoin('branches as b', 'b.id', '=', 't.branch_id')
            ->leftJoin('companies as co', 'co.id', '=', 't.company_id')
            ->where('t.type', $config['type'])
            ->whereNull('t.deleted_at')
            ->whereNotIn('t.status', $config['excluded'])
            ->when($companyId !== null, fn (Builder $query) => $query->where('t.company_id', $companyId))
            ->when($branchId !== null, fn (Builder $query) => $query->where('t.branch_id', $branchId))
            ->when(! empty($contactId), fn (Builder $query) => $query->where('t.contact_id', $contactId))
            ->when($method !== '' && $method !== 'all', fn (Builder $query) => $query->where('p.method', $method))
            ->when($startDate !== '', fn (Builder $query) => $query->whereRaw('substr(p.paid_on, 1, 10) >= ?', [$startDate]))
            ->when($endDate !== '', fn (Builder $query) => $query->whereRaw('substr(p.paid_on, 1, 10) <= ?', [$endDate]))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $sub) use ($search): void {
                $sub->where('p.payment_ref_no', 'like', "%{$search}%")
                    ->orWhere('t.invoice_no', 'like', "%{$search}%")
                    ->orWhere('c.business_name', 'like', "%{$search}%")
                    ->orWhere('c.first_name', 'like', "%{$search}%")
                    ->orWhere('c.last_name', 'like', "%{$search}%");
            }));
    }
}
