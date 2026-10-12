<?php

namespace App\Services\Reports;

use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tax exemption usage: every sale and purchase line that was taxed at zero because an exemption rule applied, with the rule and its
 * certificate, the amount of the line and the tax that would have been charged at the line's tax rate (the rate of the tax chosen
 * on the line; a group tax uses its stored percentage). It answers "what did the exemption certificates save, or cost us".
 */
class TaxExemptionReport
{
    public const SORTABLE = [
        'transaction_date' => 'transaction_date',
        'document' => 'document',
        'invoice_no' => 'invoice_no',
        'contact_name' => 'contact_name',
        'product_name' => 'product_name',
        'exemption_name' => 'exemption_name',
        'certificate_no' => 'certificate_no',
        'amount' => 'amount',
        'tax_forgone' => 'tax_forgone',
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
        $rows = collect();

        if ($side !== 'input') {
            $rows = $rows->concat($this->lines('sell_lines', 'l.subtotal', 'Sale', Transaction::TYPE_SELL, $companyId, $branchId, $from, $to, Transaction::UNPOSTED_SELL_STATUSES));
        }

        if ($side !== 'output') {
            $rows = $rows->concat($this->lines('purchase_lines', '(l.purchase_rate * l.quantity * (1 - coalesce(l.discount_percent, 0) / 100))', 'Purchase', Transaction::TYPE_PURCHASE, $companyId, $branchId, $from, $to, ['draft']));
        }

        return $rows
            ->when($search !== '', fn (Collection $all): Collection => $all->filter(fn (array $row): bool => stripos($row['invoice_no'].' '.$row['contact_name'].' '.$row['product_name'].' '.$row['exemption_name'], $search) !== false))
            ->sortByDesc('transaction_date')->values()->map(fn (array $row, int $index): array => ['id' => $index + 1] + $row);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        return [
            'lines' => $rows->count(),
            'rules' => $rows->pluck('exemption_name')->unique()->count(),
            'amount' => round((float) $rows->sum('amount'), 2),
            'tax_forgone' => round((float) $rows->sum('tax_forgone'), 2),
            'sales_tax_forgone' => round((float) $rows->where('document', 'Sale')->sum('tax_forgone'), 2),
            'purchase_tax_forgone' => round((float) $rows->where('document', 'Purchase')->sum('tax_forgone'), 2),
        ];
    }

    /**
     * @param  list<string>  $excluded
     * @return Collection<int, array<string, mixed>>
     */
    private function lines(string $table, string $amountSql, string $label, string $type, ?int $companyId, ?int $branchId, string $from, string $to, array $excluded): Collection
    {
        return DB::table("{$table} as l")
            ->join('transactions as t', 't.id', '=', 'l.transaction_id')
            ->join('tax_exemptions as e', 'e.id', '=', 'l.tax_exemption_id')
            ->leftJoin('taxes as x', 'x.id', '=', 'l.tax_id')
            ->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->where('t.type', $type)->whereNull('t.deleted_at')->whereNotIn('t.status', $excluded)
            ->when($companyId !== null, fn ($q) => $q->where('t.company_id', $companyId))
            ->when($branchId !== null, fn ($q) => $q->where('t.branch_id', $branchId))
            ->whereDate('t.transaction_date', '>=', $from)->whereDate('t.transaction_date', '<=', $to)
            ->selectRaw("l.id, t.transaction_date, t.invoice_no, {$amountSql} as amount, x.percentage, e.name as exemption_name, e.certificate_no, p.name as product_name, c.business_name, c.first_name, c.last_name")
            ->get()
            ->map(function (object $row) use ($label): array {
                $amount = round((float) $row->amount, 2);
                $rate = (float) ($row->percentage ?? 0);

                return [
                    'transaction_date' => substr((string) $row->transaction_date, 0, 10), 'document' => $label, 'invoice_no' => (string) $row->invoice_no,
                    'contact_name' => trim((string) ($row->business_name ?: $row->first_name.' '.$row->last_name)), 'product_name' => (string) ($row->product_name ?? ''),
                    'exemption_name' => (string) $row->exemption_name, 'certificate_no' => (string) ($row->certificate_no ?? ''),
                    'rate' => round($rate, 2), 'amount' => $amount, 'tax_forgone' => round($amount * $rate / 100, 2),
                ];
            });
    }
}
