<?php

namespace App\Services\Reports;

use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Withholding tax of the range, one row per document: what customers withheld from what they paid us (an asset we claim back from
 * the tax office) and what we withheld from suppliers (a liability we owe the tax office). The amount is the document's own
 * `withholding_amount`, the one WithholdingSettlement books. Drafts and quotations are left out; returns are not netted here.
 * `tax_side` narrows to `receivable` (sales) or `payable` (purchases).
 */
class WithholdingTaxReport
{
    public const SORTABLE = [
        'transaction_date' => 'transaction_date',
        'document' => 'document',
        'invoice_no' => 'invoice_no',
        'contact_name' => 'contact_name',
        'tax_name' => 'tax_name',
        'rate' => 'rate',
        'final_amount' => 'final_amount',
        'withholding_amount' => 'withholding_amount',
    ];

    public const DEFAULT_SORT = 'transaction_date';

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        [$from, $to] = ReportDates::range($filters, 365);
        $side = (string) ($filters['tax_side'] ?? 'all');
        $search = trim((string) ($filters['search'] ?? ''));

        return DB::table('transactions as t')
            ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->leftJoin('taxes as x', 'x.id', '=', 't.withholding_tax_id')
            ->whereIn('t.type', [Transaction::TYPE_SELL, Transaction::TYPE_PURCHASE])
            ->where('t.withholding_amount', '>', 0)
            ->whereNull('t.deleted_at')
            ->where(fn ($q) => $q->where('t.type', Transaction::TYPE_PURCHASE)->orWhereNotIn('t.status', Transaction::UNPOSTED_SELL_STATUSES))
            ->where('t.status', '!=', 'draft')
            ->when($companyId !== null, fn ($q) => $q->where('t.company_id', $companyId))
            ->when($branchId !== null, fn ($q) => $q->where('t.branch_id', $branchId))
            ->when($side === 'receivable', fn ($q) => $q->where('t.type', Transaction::TYPE_SELL))
            ->when($side === 'payable', fn ($q) => $q->where('t.type', Transaction::TYPE_PURCHASE))
            ->whereDate('t.transaction_date', '>=', $from)->whereDate('t.transaction_date', '<=', $to)
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('t.invoice_no', 'like', "%{$search}%")->orWhere('c.business_name', 'like', "%{$search}%")))
            ->orderByDesc('t.transaction_date')
            ->get(['t.id', 't.type', 't.transaction_date', 't.invoice_no', 't.final_amount', 't.withholding_amount', 'x.name as tax_name', 'x.percentage', 'c.business_name', 'c.first_name', 'c.last_name'])
            ->map(function (object $row): array {
                $isSale = $row->type === Transaction::TYPE_SELL;

                return [
                    'id' => (int) $row->id,
                    'transaction_date' => substr((string) $row->transaction_date, 0, 10),
                    'document' => $isSale ? 'Sale' : 'Purchase',
                    'side' => $isSale ? 'receivable' : 'payable',
                    'invoice_no' => (string) $row->invoice_no,
                    'contact_name' => trim((string) ($row->business_name ?: $row->first_name.' '.$row->last_name)),
                    'tax_name' => (string) ($row->tax_name ?? ''),
                    'rate' => $row->percentage === null ? null : round((float) $row->percentage, 2),
                    'final_amount' => round((float) $row->final_amount, 2),
                    'withholding_amount' => round((float) $row->withholding_amount, 2),
                ];
            })->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        $receivable = round((float) $rows->where('side', 'receivable')->sum('withholding_amount'), 2);
        $payable = round((float) $rows->where('side', 'payable')->sum('withholding_amount'), 2);

        return ['count' => $rows->count(), 'receivable' => $receivable, 'payable' => $payable, 'net_payable' => round($payable - $receivable, 2)];
    }
}
