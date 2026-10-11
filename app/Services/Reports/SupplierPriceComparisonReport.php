<?php

namespace App\Services\Reports;

use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Supplier Price Comparison: for each product bought in the period (default the last 180 days), what each supplier charged:
 * the latest rate, the average rate (weighted by quantity), the lowest rate, and how many purchases. The cheapest supplier by
 * average rate is flagged and every other supplier shows how far above it they are. Rates are per base unit (the line rate
 * over its packing), from purchases that are not drafts. Read-only.
 */
class SupplierPriceComparisonReport
{
    public const SORTABLE = [
        'product_name' => 'product_name',
        'supplier_name' => 'supplier_name',
        'last_rate' => 'last_rate',
        'avg_rate' => 'avg_rate',
        'min_rate' => 'min_rate',
        'purchases' => 'purchases',
        'above_best_percent' => 'above_best_percent',
    ];

    public const DEFAULT_SORT = 'product_name';

    public const DEFAULT_DESC = false;

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        [$from, $to] = ReportDates::range($filters, 180);
        $search = trim((string) ($filters['search'] ?? ''));
        $packing = '(case when pl.packing_qty is null or pl.packing_qty < 1 then 1 else pl.packing_qty end)';

        $lines = DB::table('purchase_lines as pl')
            ->join('transactions as t', 't.id', '=', 'pl.transaction_id')
            ->join('products as p', 'p.id', '=', 'pl.product_id')
            ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->where('t.type', Transaction::TYPE_PURCHASE)
            ->whereNull('t.deleted_at')
            ->where('t.status', '!=', 'draft')
            ->whereNotNull('t.contact_id')
            ->whereDate('t.transaction_date', '>=', $from)
            ->whereDate('t.transaction_date', '<=', $to)
            ->when($companyId !== null, fn ($q) => $q->where('t.company_id', $companyId))
            ->when($branchId !== null, fn ($q) => $q->where('t.branch_id', $branchId))
            ->when(! empty($filters['product_id']), fn ($q) => $q->where('pl.product_id', $filters['product_id']))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('p.name', 'like', "%{$search}%")->orWhere('c.business_name', 'like', "%{$search}%")))
            ->orderBy('t.transaction_date')
            ->orderBy('t.id')
            ->get([
                'pl.product_id', 'p.name as product_name', 't.contact_id', 'c.business_name as supplier_name', 't.transaction_date',
                DB::raw("pl.purchase_rate / {$packing} as unit_rate"), DB::raw("pl.quantity * {$packing} as base_qty"),
            ]);

        $rows = collect();

        foreach ($lines->groupBy('product_id') as $productId => $productLines) {
            $suppliers = $productLines->groupBy('contact_id')->map(function (Collection $group, $contactId): array {
                $quantity = (float) $group->sum('base_qty');

                return [
                    'supplier_id' => (int) $contactId,
                    'supplier_name' => (string) ($group->first()->supplier_name ?? ''),
                    'last_rate' => round((float) $group->last()->unit_rate, 4),
                    'avg_rate' => $quantity > 0 ? round($group->sum(fn (object $line): float => (float) $line->unit_rate * (float) $line->base_qty) / $quantity, 4) : round((float) $group->avg('unit_rate'), 4),
                    'min_rate' => round((float) $group->min('unit_rate'), 4),
                    'purchases' => $group->count(),
                    'last_date' => substr((string) $group->last()->transaction_date, 0, 10),
                ];
            });

            $best = (float) $suppliers->min('avg_rate');

            foreach ($suppliers as $supplier) {
                $rows->push($supplier + [
                    'id' => (int) $productId * 100000 + $supplier['supplier_id'],
                    'product_id' => (int) $productId,
                    'product_name' => (string) $productLines->first()->product_name,
                    'best' => $supplier['avg_rate'] <= $best + 0.00001 && $suppliers->count() > 1 ? 'Best price' : '',
                    'above_best_percent' => $best > 0 ? round(($supplier['avg_rate'] - $best) / $best * 100, 2) : 0.0,
                ]);
            }
        }

        return $rows->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        return [
            'products' => $rows->pluck('product_id')->unique()->count(),
            'suppliers' => $rows->pluck('supplier_id')->unique()->count(),
            'compared_products' => $rows->groupBy('product_id')->filter(fn (Collection $group): bool => $group->count() > 1)->count(),
        ];
    }
}
