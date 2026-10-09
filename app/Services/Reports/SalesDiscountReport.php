<?php

namespace App\Services\Reports;

use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sales Discount Report: every posted sale in the range that gave a discount, with what the lines gave (list
 * price less the price after discount, times the quantity) and what the invoice-level discount gave (a
 * percentage is taken of the lines after their own discounts). Drafts and quotations are left out.
 */
class SalesDiscountReport
{
    public const SORTABLE = [
        'transaction_date' => 'transaction_date',
        'invoice_no' => 'invoice_no',
        'contact_name' => 'contact_name',
        'gross' => 'gross',
        'line_discount' => 'line_discount',
        'header_discount' => 'header_discount',
        'total_discount' => 'total_discount',
        'discount_percent' => 'discount_percent',
        'final_amount' => 'final_amount',
    ];

    public const DEFAULT_SORT = 'total_discount';

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        [$from, $to] = ReportDates::range($filters);
        $search = trim((string) ($filters['search'] ?? ''));

        $lines = DB::table('sell_lines')
            ->selectRaw('transaction_id, SUM(unit_price * quantity) as gross, SUM((unit_price - unit_price_after_discount) * quantity) as line_discount')
            ->groupBy('transaction_id');

        return Transaction::query()
            ->where('transactions.type', Transaction::TYPE_SELL)
            ->whereNotIn('transactions.status', Transaction::UNPOSTED_SELL_STATUSES)
            ->when($companyId !== null, fn ($q) => $q->where('transactions.company_id', $companyId))
            ->when($branchId !== null, fn ($q) => $q->where('transactions.branch_id', $branchId))
            ->whereDate('transactions.transaction_date', '>=', $from)
            ->whereDate('transactions.transaction_date', '<=', $to)
            ->joinSub($lines, 'sl', 'sl.transaction_id', '=', 'transactions.id')
            ->leftJoin('contacts as c', 'c.id', '=', 'transactions.contact_id')
            ->when($search !== '', fn ($q) => $q->where(fn ($s) => $s->where('transactions.invoice_no', 'like', "%{$search}%")->orWhere('c.business_name', 'like', "%{$search}%")))
            ->get(['transactions.id', 'transactions.transaction_date', 'transactions.invoice_no', 'transactions.discount_type', 'transactions.discount_amount', 'transactions.final_amount', 'c.business_name', 'sl.gross', 'sl.line_discount'])
            ->map(function ($sale): array {
                $gross = round((float) $sale->gross, 2);
                $lineDiscount = round((float) $sale->line_discount, 2);
                $afterLines = $gross - $lineDiscount;
                $header = match ($sale->discount_type) {
                    'percentage' => round($afterLines * (float) $sale->discount_amount / 100, 2),
                    'fixed' => round((float) $sale->discount_amount, 2),
                    default => 0.0,
                };
                $total = round($lineDiscount + $header, 2);

                return [
                    'id' => (int) $sale->id,
                    'transaction_date' => substr((string) $sale->transaction_date, 0, 10),
                    'invoice_no' => $sale->invoice_no,
                    'contact_name' => $sale->business_name,
                    'gross' => $gross,
                    'line_discount' => $lineDiscount,
                    'header_discount' => $header,
                    'total_discount' => $total,
                    'discount_percent' => $gross > 0 ? round($total / $gross * 100, 2) : 0.0,
                    'final_amount' => round((float) $sale->final_amount, 2),
                ];
            })
            ->filter(fn (array $row): bool => $row['total_discount'] > 0)
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        $gross = round((float) $rows->sum('gross'), 2);
        $total = round((float) $rows->sum('total_discount'), 2);

        return [
            'count' => $rows->count(),
            'gross' => $gross,
            'line_discount' => round((float) $rows->sum('line_discount'), 2),
            'header_discount' => round((float) $rows->sum('header_discount'), 2),
            'total_discount' => $total,
            'discount_percent' => $gross > 0 ? round($total / $gross * 100, 2) : 0.0,
        ];
    }
}
