<?php

namespace App\Services\Reports;

use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Payments by Payment Account: money received on sales and paid on purchases in the range (default the last 30
 * days; the day the payment was recorded), by the cash / bank account it went through and the method. A return
 * payment counts against its side. Payments on other documents are not part of this report.
 */
class PaymentAccountReport
{
    public const SORTABLE = [
        'account_code' => 'account_code',
        'account_name' => 'account_name',
        'method' => 'method',
        'received' => 'received',
        'paid' => 'paid',
        'net' => 'net',
        'count' => 'count',
    ];

    public const DEFAULT_SORT = 'account_code';

    public const DEFAULT_DESC = false;

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        [$from, $to] = ReportDates::range($filters);
        $search = trim((string) ($filters['search'] ?? ''));

        return DB::table('payments as p')
            ->join('transactions as t', 't.id', '=', 'p.transaction_id')
            ->leftJoin('chart_of_accounts as a', 'a.id', '=', 'p.payment_account')
            ->whereIn('t.type', [Transaction::TYPE_SELL, Transaction::TYPE_PURCHASE])
            ->whereNull('t.deleted_at')
            ->when($companyId !== null, fn ($q) => $q->where('t.company_id', $companyId))
            ->when($branchId !== null, fn ($q) => $q->where('t.branch_id', $branchId))
            ->whereDate('p.created_at', '>=', $from)
            ->whereDate('p.created_at', '<=', $to)
            ->when($search !== '', fn ($q) => $q->where(fn ($s) => $s->where('a.name', 'like', "%{$search}%")->orWhere('a.code', 'like', "%{$search}%")))
            ->groupBy('p.payment_account', 'a.code', 'a.name', 'p.method')
            ->selectRaw("p.payment_account as account_id, a.code as account_code, a.name as account_name, p.method as method, COUNT(*) as payment_count, SUM(CASE WHEN t.type = 'sell' THEN (CASE WHEN p.is_return = 1 THEN -p.amount ELSE p.amount END) ELSE 0 END) as received, SUM(CASE WHEN t.type = 'purchaseorder' THEN (CASE WHEN p.is_return = 1 THEN -p.amount ELSE p.amount END) ELSE 0 END) as paid")
            ->get()
            ->map(fn (object $row, int $index): array => [
                'id' => $index + 1,
                'account_code' => $row->account_code ?? '-',
                'account_name' => $row->account_name ?? 'No account',
                'method' => $row->method,
                'count' => (int) $row->payment_count,
                'received' => round((float) $row->received, 2),
                'paid' => round((float) $row->paid, 2),
                'net' => round((float) $row->received - (float) $row->paid, 2),
            ])
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        return [
            'count' => (int) $rows->sum('count'),
            'received' => round((float) $rows->sum('received'), 2),
            'paid' => round((float) $rows->sum('paid'), 2),
            'net' => round((float) $rows->sum('net'), 2),
        ];
    }
}
