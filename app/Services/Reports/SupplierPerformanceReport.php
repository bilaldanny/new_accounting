<?php

namespace App\Services\Reports;

use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Supplier Performance & Comparison: for each supplier with purchases in the range (default the last 365
 * days), what was bought, what went back (the return rate), what was paid and what is still owed on those
 * purchases, the average order and the last purchase date. Drafts are left out. Suppliers are ranked by the
 * value bought so they can be compared side by side.
 */
class SupplierPerformanceReport
{
    public const SORTABLE = [
        'supplier_name' => 'supplier_name',
        'purchase_count' => 'purchase_count',
        'purchased' => 'purchased',
        'returned' => 'returned',
        'return_rate' => 'return_rate',
        'paid' => 'paid',
        'outstanding' => 'outstanding',
        'average_order' => 'average_order',
        'last_purchase' => 'last_purchase',
        'rank' => 'rank',
    ];

    public const DEFAULT_SORT = 'rank';

    public const DEFAULT_DESC = false;

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        [$from, $to] = ReportDates::range($filters, 365);
        $search = trim((string) ($filters['search'] ?? ''));

        $scope = fn (string $type) => Transaction::query()
            ->where('transactions.type', $type)
            ->where('transactions.status', '!=', 'draft')
            ->when($companyId !== null, fn ($q) => $q->where('transactions.company_id', $companyId))
            ->when($branchId !== null, fn ($q) => $q->where('transactions.branch_id', $branchId))
            ->whereDate('transactions.transaction_date', '>=', $from)
            ->whereDate('transactions.transaction_date', '<=', $to);

        $purchases = $scope(Transaction::TYPE_PURCHASE)
            ->groupBy('transactions.contact_id')
            ->selectRaw('transactions.contact_id as contact_id, COUNT(*) as purchase_count, SUM(transactions.final_amount) as purchased, MAX(transactions.transaction_date) as last_purchase')
            ->get()
            ->keyBy('contact_id');

        $returns = $scope(Transaction::TYPE_PURCHASE_RETURN)
            ->groupBy('transactions.contact_id')
            ->selectRaw('transactions.contact_id as contact_id, SUM(transactions.final_amount) as returned')
            ->pluck('returned', 'contact_id');

        $paid = DB::table('payments as p')
            ->join('transactions as t', 't.id', '=', 'p.transaction_id')
            ->where('t.type', Transaction::TYPE_PURCHASE)
            ->where('t.status', '!=', 'draft')
            ->whereNull('t.deleted_at')
            ->when($companyId !== null, fn ($q) => $q->where('t.company_id', $companyId))
            ->when($branchId !== null, fn ($q) => $q->where('t.branch_id', $branchId))
            ->whereDate('t.transaction_date', '>=', $from)
            ->whereDate('t.transaction_date', '<=', $to)
            ->groupBy('t.contact_id')
            ->selectRaw('t.contact_id as contact_id, SUM(CASE WHEN p.is_return = 1 THEN -p.amount ELSE p.amount END) as paid')
            ->pluck('paid', 'contact_id');

        $names = DB::table('contacts')->whereIn('id', $purchases->keys())->pluck('business_name', 'id');

        return $purchases
            ->map(function ($row, $contactId) use ($returns, $paid, $names): array {
                $purchased = round((float) $row->purchased, 2);
                $returned = round((float) ($returns[$contactId] ?? 0), 2);
                $amountPaid = round((float) ($paid[$contactId] ?? 0), 2);

                return [
                    'id' => (int) $contactId,
                    'supplier_name' => (string) ($names[$contactId] ?? '-'),
                    'purchase_count' => (int) $row->purchase_count,
                    'purchased' => $purchased,
                    'returned' => $returned,
                    'return_rate' => $purchased > 0 ? round($returned / $purchased * 100, 2) : 0.0,
                    'paid' => $amountPaid,
                    'outstanding' => round($purchased - $returned - $amountPaid, 2),
                    'average_order' => round($purchased / max((int) $row->purchase_count, 1), 2),
                    'last_purchase' => substr((string) $row->last_purchase, 0, 10),
                ];
            })
            ->when($search !== '', fn (Collection $rows) => $rows->filter(fn (array $row): bool => str_contains(strtolower($row['supplier_name']), strtolower($search))))
            ->sortByDesc('purchased')
            ->values()
            ->map(fn (array $row, int $index): array => $row + ['rank' => $index + 1]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        $purchased = round((float) $rows->sum('purchased'), 2);
        $returned = round((float) $rows->sum('returned'), 2);

        return [
            'suppliers' => $rows->count(),
            'purchased' => $purchased,
            'returned' => $returned,
            'return_rate' => $purchased > 0 ? round($returned / $purchased * 100, 2) : 0.0,
            'paid' => round((float) $rows->sum('paid'), 2),
            'outstanding' => round((float) $rows->sum('outstanding'), 2),
        ];
    }
}
