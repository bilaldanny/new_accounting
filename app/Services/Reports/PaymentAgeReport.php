<?php

namespace App\Services\Reports;

use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Payments by Age: how long after the invoice date payments were made (0-30, 31-60, 61-90 and over 90 days),
 * for sales (received) and purchases (paid), among the payments recorded in the range (default the last 30
 * days). A return payment counts negative.
 */
class PaymentAgeReport
{
    public const SORTABLE = [
        'direction' => 'direction',
        'bucket' => 'bucket_order',
        'count' => 'count',
        'amount' => 'amount',
    ];

    public const DEFAULT_SORT = 'direction';

    public const DEFAULT_DESC = false;

    private const BUCKETS = [[30, '0-30 days'], [60, '31-60 days'], [90, '61-90 days'], [PHP_INT_MAX, 'Over 90 days']];

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        [$from, $to] = ReportDates::range($filters);

        $payments = DB::table('payments as p')
            ->join('transactions as t', 't.id', '=', 'p.transaction_id')
            ->whereIn('t.type', [Transaction::TYPE_SELL, Transaction::TYPE_PURCHASE])
            ->whereNull('t.deleted_at')
            ->when($companyId !== null, fn ($q) => $q->where('t.company_id', $companyId))
            ->when($branchId !== null, fn ($q) => $q->where('t.branch_id', $branchId))
            ->whereDate('p.created_at', '>=', $from)
            ->whereDate('p.created_at', '<=', $to)
            ->get(['t.type', 't.transaction_date', 'p.created_at', 'p.amount', 'p.is_return']);

        $rows = [];

        foreach (['received' => Transaction::TYPE_SELL, 'paid' => Transaction::TYPE_PURCHASE] as $direction => $type) {
            foreach (self::BUCKETS as $order => [, $label]) {
                $rows[$direction.$order] = ['id' => count($rows) + 1, 'direction' => $direction, 'bucket' => $label, 'bucket_order' => $order, 'count' => 0, 'amount' => 0.0];
            }

            foreach ($payments->where('type', $type) as $payment) {
                $age = max((int) Carbon::parse($payment->transaction_date)->startOfDay()->diffInDays(Carbon::parse($payment->created_at)->startOfDay(), false), 0);

                foreach (self::BUCKETS as $order => [$max]) {
                    if ($age <= $max) {
                        $key = $direction.$order;
                        $rows[$key]['count']++;
                        $rows[$key]['amount'] = round($rows[$key]['amount'] + ($payment->is_return ? -1 : 1) * (float) $payment->amount, 2);

                        break;
                    }
                }
            }
        }

        return collect(array_values($rows));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        return [
            'received' => round((float) $rows->where('direction', 'received')->sum('amount'), 2),
            'paid' => round((float) $rows->where('direction', 'paid')->sum('amount'), 2),
            'received_over_60' => round((float) $rows->where('direction', 'received')->where('bucket_order', '>=', 2)->sum('amount'), 2),
            'paid_over_60' => round((float) $rows->where('direction', 'paid')->where('bucket_order', '>=', 2)->sum('amount'), 2),
        ];
    }
}
