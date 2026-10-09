<?php

namespace App\Services\Reports;

use App\Services\StockMovements;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stock Movement History, warehouse aware: every stock movement line in the range (default the last 30 days), one per
 * row with its document, branch and warehouse, the quantity going in or out in base units, from the same single
 * definition of stock the Stock Report uses (StockMovements, one row per movement part). A warehouse can be chosen
 * (0 for the unassigned movements, those whose document names none). Movements of zero are left out. Read-only.
 */
class StockMovementHistoryReport
{
    /**
     * @var array<string, string>
     */
    private const MOVEMENTS = [
        'purchase' => 'Purchase received',
        'purchase_return' => 'Purchase return',
        'sale' => 'Sale',
        'sale_return' => 'Sale return',
        'transfer_in' => 'Transfer in',
        'transfer_out' => 'Transfer out',
        'adjustment' => 'Adjustment',
    ];

    public const SORTABLE = [
        'id' => 'id',
        'document_date' => 'document_date',
        'document_no' => 'document_no',
        'product_name' => 'product_name',
        'warehouse_name' => 'warehouse_name',
        'qty' => 'qty',
    ];

    public const DEFAULT_SORT = 'document_date';

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        [$from, $to] = ReportDates::range($filters);
        $productId = ! empty($filters['product_id']) ? (int) $filters['product_id'] : null;
        $warehouseId = isset($filters['warehouse_id']) && is_numeric($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        $search = trim((string) ($filters['search'] ?? ''));

        return DB::query()
            ->fromSub(StockMovements::query([
                'branch_id' => $branchId,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'as_of' => $to,
                'with_kinds' => true,
                'with_documents' => true,
            ]), 'm')
            ->join('products as p', 'p.id', '=', 'm.product_id')
            ->leftJoin('product_details as d', 'd.id', '=', 'm.variation_id')
            ->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
            ->leftJoin('branches as b', 'b.id', '=', 'm.branch_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->whereNull('p.deleted_at')
            ->whereDate('m.document_date', '>=', $from)
            ->when($companyId !== null, fn (Builder $q) => $q->where('p.company_id', $companyId))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $sub) => $sub->where('p.name', 'like', "%{$search}%")->orWhere('d.sku', 'like', "%{$search}%")->orWhere('m.document_no', 'like', "%{$search}%")))
            ->select([
                'm.transaction_id', 'm.document_no', 'm.document_date', 'm.kind', 'm.qty', 'm.branch_id', 'b.name as branch_name', 'm.warehouse_id', 'w.name as warehouse_name',
                'm.product_id', 'm.variation_id', 'p.name as product_name', 'd.sku', 'd.variation_name', 'u.short_name as unit_name',
            ])
            ->get()
            ->map(fn (object $row): array => [
                'transaction_id' => (int) $row->transaction_id,
                'document_no' => (string) $row->document_no,
                'document_date' => substr((string) $row->document_date, 0, 10),
                'kind' => (string) $row->kind,
                'movement' => self::MOVEMENTS[$row->kind] ?? (string) $row->kind,
                'branch_id' => (int) $row->branch_id,
                'branch_name' => (string) $row->branch_name,
                'warehouse_id' => $row->warehouse_id === null ? null : (int) $row->warehouse_id,
                'warehouse_name' => $row->warehouse_id === null ? WarehouseStockReport::UNASSIGNED : (string) $row->warehouse_name,
                'product_id' => (int) $row->product_id,
                'variation_id' => (int) $row->variation_id,
                'product_name' => (string) $row->product_name,
                'sku' => $row->sku,
                'variation_name' => $row->variation_name,
                'unit_name' => $row->unit_name,
                'qty' => round((float) $row->qty, 6),
            ])
            ->reject(fn (array $row): bool => $row['qty'] == 0.0)
            ->map(fn (array $row): array => $row + ['qty_in' => max($row['qty'], 0.0), 'qty_out' => max(-$row['qty'], 0.0)])
            ->sortBy([['document_date', 'asc'], ['transaction_id', 'asc'], ['product_name', 'asc']])
            ->values()
            ->map(fn (array $row, int $index): array => ['id' => $index + 1] + $row);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        return [
            'movements' => $rows->count(),
            'qty_in' => round((float) $rows->sum('qty_in'), 4),
            'qty_out' => round((float) $rows->sum('qty_out'), 4),
            'net' => round((float) $rows->sum('qty'), 4),
        ];
    }
}
