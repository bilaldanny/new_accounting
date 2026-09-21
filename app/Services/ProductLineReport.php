<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

/**
 * Product purchase and product sell reports: one row per document line.
 *
 * A line counts like its document: every purchase line except those of a draft, every sell line except
 * those of a draft or quotation (the ledger / StockMovements rule, not the old received / issue only
 * rule). The date range is on the document date, inclusive at both ends.
 *
 * Amounts follow the forms: a purchase line is `purchase_rate x quantity x packing_qty` (the rate is per
 * base unit, `packing_qty` the base units in the chosen unit), a sell line is its stored `subtotal`.
 * A return on the line takes its share off: the net quantity and net amount are what is left.
 */
class ProductLineReport
{
    public const KINDS = [
        'purchase' => ['type' => Transaction::TYPE_PURCHASE, 'excluded' => ['draft'], 'lines' => 'purchase_lines'],
        'sell' => ['type' => Transaction::TYPE_SELL, 'excluded' => Transaction::UNPOSTED_SELL_STATUSES, 'lines' => 'sell_lines'],
    ];

    public const SORTABLE = [
        'transaction_date' => 'transaction_date',
        'invoice_no' => 'invoice_no',
        'contact_name' => 'contact_name',
        'product_name' => 'product_name',
        'sku' => 'sku',
        'brand_name' => 'brand_name',
        'branch_name' => 'branch_name',
        'quantity' => 'quantity',
        'net_quantity' => 'net_quantity',
        'unit_price' => 'unit_price',
        'discount_percent' => 'discount_percent',
        'amount' => 'amount',
        'net_amount' => 'net_amount',
    ];

    /**
     * @param  array{product_id?: mixed, brand_id?: mixed, category_id?: mixed, itemtype_id?: mixed, contact_id?: mixed, start_date?: ?string, end_date?: ?string, search?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(string $kind, ?int $companyId, ?int $branchId, array $filters = []): Collection
    {
        self::KINDS[$kind] ?? throw new InvalidArgumentException("Unknown product report [{$kind}].");

        return $this->query($kind, $companyId, $branchId, $filters)
            ->get()
            ->map(fn (stdClass $row): array => $this->present($row, $kind))
            ->sortBy([['transaction_date', 'desc'], ['id', 'desc']])
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{count: int, quantity: float, net_quantity: float, amount: float, net_amount: float}
     */
    public function summary(Collection $rows): array
    {
        return [
            'count' => $rows->count(),
            'quantity' => round((float) $rows->sum('base_quantity'), 4),
            'net_quantity' => round((float) $rows->sum('net_base_quantity'), 4),
            'amount' => round((float) $rows->sum('amount'), 2),
            'net_amount' => round((float) $rows->sum('net_amount'), 2),
        ];
    }

    /**
     * @param  array{product_id?: mixed, brand_id?: mixed, category_id?: mixed, itemtype_id?: mixed, contact_id?: mixed, start_date?: ?string, end_date?: ?string, search?: ?string}  $filters
     */
    public function query(string $kind, ?int $companyId, ?int $branchId, array $filters = []): Builder
    {
        $config = self::KINDS[$kind];
        $productId = $filters['product_id'] ?? null;
        $brandId = $filters['brand_id'] ?? null;
        $categoryId = $filters['category_id'] ?? null;
        $itemTypeId = $filters['itemtype_id'] ?? null;
        $contactId = $filters['contact_id'] ?? null;
        $startDate = trim((string) ($filters['start_date'] ?? ''));
        $endDate = trim((string) ($filters['end_date'] ?? ''));
        $search = trim((string) ($filters['search'] ?? ''));

        $query = DB::table($config['lines'].' as l')
            ->join('transactions as t', 't.id', '=', 'l.transaction_id')
            ->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('product_details as d', 'd.id', '=', 'l.variation_id')
            ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->leftJoin('units as u', 'u.id', '=', 'l.unit_id')
            ->leftJoin('units as bu', 'bu.id', '=', 'p.unit_id')
            ->leftJoin('branches as b', 'b.id', '=', 't.branch_id')
            ->leftJoin('brands as br', 'br.id', '=', 'p.brand_id')
            ->where('t.type', $config['type'])
            ->whereNull('t.deleted_at')
            ->whereNotIn('t.status', $config['excluded'])
            ->when($companyId !== null, fn (Builder $q) => $q->where('t.company_id', $companyId))
            ->when($branchId !== null, fn (Builder $q) => $q->where('t.branch_id', $branchId))
            ->when(! empty($productId), fn (Builder $q) => $q->where('l.product_id', $productId))
            ->when(! empty($brandId), fn (Builder $q) => $q->where('p.brand_id', $brandId))
            ->when(! empty($categoryId), fn (Builder $q) => $q->where('p.category_id', $categoryId))
            ->when(! empty($itemTypeId), fn (Builder $q) => $q->where('p.itemtype_id', $itemTypeId))
            ->when(! empty($contactId), fn (Builder $q) => $q->where('t.contact_id', $contactId))
            ->when($startDate !== '', fn (Builder $q) => $q->whereDate('t.transaction_date', '>=', $startDate))
            ->when($endDate !== '', fn (Builder $q) => $q->whereDate('t.transaction_date', '<=', $endDate))
            ->when($search !== '', fn (Builder $q) => $q->where(function (Builder $sub) use ($search): void {
                $sub->where('p.name', 'like', "%{$search}%")
                    ->orWhere('d.sku', 'like', "%{$search}%")
                    ->orWhere('t.invoice_no', 'like', "%{$search}%")
                    ->orWhere('c.business_name', 'like', "%{$search}%");
            }));

        $select = [
            'l.id',
            'l.product_id',
            'l.variation_id',
            'l.quantity',
            'l.quantity_returned',
            'l.packing_qty',
            't.contact_id',
            't.transaction_date',
            't.invoice_no',
            't.sup_ref_no',
            't.status',
            'p.name as product_name',
            'd.sku',
            'd.variation_name',
            'br.name as brand_name',
            'u.short_name as unit_name',
            'bu.short_name as base_unit_name',
            'b.name as branch_name',
            'c.business_name',
            'c.first_name',
            'c.last_name',
        ];

        $select = $kind === 'purchase'
            ? [...$select, 'l.purchase_rate as unit_price', 'l.discount_percent', DB::raw('(l.purchase_rate * l.quantity * (case when l.packing_qty is null or l.packing_qty < 1 then 1 else l.packing_qty end)) as amount')]
            : [...$select, 'l.unit_price', 'l.discount_percent', 'l.subtotal as amount'];

        return $query->select($select);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(stdClass $row, string $kind): array
    {
        $packing = $row->packing_qty !== null && (float) $row->packing_qty >= 1 ? (float) $row->packing_qty : 1.0;
        $quantity = (float) $row->quantity;
        $returned = (float) $row->quantity_returned;
        $amount = round((float) $row->amount, 2);
        $returnedShare = $quantity > 0 ? min($returned / $quantity, 1) : 0.0;
        $netAmount = round($amount * (1 - $returnedShare), 2);

        return [
            'id' => (int) $row->id,
            'product_id' => (int) $row->product_id,
            'variation_id' => $row->variation_id === null ? null : (int) $row->variation_id,
            'transaction_date' => substr((string) $row->transaction_date, 0, 10),
            'invoice_no' => $row->invoice_no,
            'sup_ref_no' => $kind === 'purchase' ? $row->sup_ref_no : null,
            'status' => $row->status,
            'contact_id' => $row->contact_id === null ? null : (int) $row->contact_id,
            'contact_name' => TransactionListReport::contactName($row),
            'product_name' => (string) ($row->product_name ?? '-'),
            'sku' => (string) ($row->sku ?? ''),
            'variation_name' => (string) ($row->variation_name ?? ''),
            'brand_name' => (string) ($row->brand_name ?? ''),
            'unit_name' => (string) ($row->unit_name ?? ''),
            'base_unit_name' => (string) ($row->base_unit_name ?? ''),
            'branch_name' => (string) ($row->branch_name ?? ''),
            'quantity' => round($quantity, 4),
            'returned_quantity' => round($returned, 4),
            'net_quantity' => round($quantity - $returned, 4),
            'packing_qty' => $packing,
            'base_quantity' => round($quantity * $packing, 4),
            'net_base_quantity' => round(($quantity - $returned) * $packing, 4),
            'unit_price' => round((float) $row->unit_price, 2),
            'discount_percent' => round((float) $row->discount_percent, 2),
            'amount' => $amount,
            'net_amount' => $netAmount,
        ];
    }
}
