<?php

namespace App\Services\Reports;

use App\Services\StockTracking;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Serial / IMEI Traceability Log: every movement of every serial - received, moved between branches, sold, registered by hand,
 * written off - one per row with its document, branch and note, in the order it happened. A movement whose document has since
 * been deleted (or is still a draft, or whose receipt was cancelled) is shown as "Reversed": it no longer counts towards where
 * the serial is. Search by serial number, product or document to follow one unit from the supplier to the customer. Read-only.
 */
class SerialTraceabilityReport
{
    public const SORTABLE = [
        'id' => 'id',
        'document_date' => 'document_date',
        'serial_no' => 'serial_no',
        'product_name' => 'product_name',
        'movement' => 'movement',
        'branch_name' => 'branch_name',
        'document_no' => 'document_no',
    ];

    public const DEFAULT_SORT = 'id';

    public const DEFAULT_DESC = true;

    private const MOVEMENTS = [
        StockTracking::KIND_RECEIVE => 'Received',
        StockTracking::KIND_SALE => 'Sold',
        StockTracking::KIND_TRANSFER_OUT => 'Transfer out',
        StockTracking::KIND_TRANSFER_IN => 'Transfer in',
        StockTracking::KIND_REGISTER => 'Registered',
        StockTracking::KIND_WRITE_OFF => 'Written off',
    ];

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        $kind = trim((string) ($filters['status'] ?? ''));
        $productId = $filters['product_id'] ?? null;
        $search = trim((string) ($filters['search'] ?? ''));
        $start = trim((string) ($filters['start_date'] ?? ''));
        $end = trim((string) ($filters['end_date'] ?? ''));

        $rows = DB::table('stock_serial_movements as m')
            ->join('stock_serials as s', 's.id', '=', 'm.serial_id')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->leftJoin('branches as b', 'b.id', '=', 'm.branch_id')
            ->leftJoin('transactions as t', 't.id', '=', 'm.transaction_id')
            ->when($companyId !== null, fn ($q) => $q->where('s.company_id', $companyId))
            ->when($branchId !== null, fn ($q) => $q->where('m.branch_id', $branchId))
            ->when(! empty($productId), fn ($q) => $q->where('s.product_id', $productId))
            ->when(array_key_exists($kind, self::MOVEMENTS), fn ($q) => $q->where('m.kind', $kind))
            ->when($start !== '', fn ($q) => $q->whereDate(DB::raw('coalesce(t.transaction_date, m.moved_at)'), '>=', $start))
            ->when($end !== '', fn ($q) => $q->whereDate(DB::raw('coalesce(t.transaction_date, m.moved_at)'), '<=', $end))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('s.serial_no', 'like', "%{$search}%")->orWhere('p.name', 'like', "%{$search}%")->orWhere('t.invoice_no', 'like', "%{$search}%")))
            ->select(['m.id', 'm.kind', 'm.direction', 'm.note', 'm.moved_at', 's.serial_no', 'p.name as product_name', 'b.name as branch_name', 't.invoice_no as document_no', 't.type as document_type', 't.transaction_date'])
            ->get();

        $counted = $rows->isEmpty() ? collect() : StockTracking::counted(DB::table('stock_serial_movements as m'))->whereIn('m.id', $rows->pluck('id')->all())->pluck('m.id')->flip();

        return $rows->map(fn (object $row): array => [
            'id' => (int) $row->id,
            'document_date' => substr((string) ($row->transaction_date ?? $row->moved_at), 0, 10),
            'serial_no' => $row->serial_no,
            'product_name' => $row->product_name,
            'movement' => self::MOVEMENTS[$row->kind] ?? $row->kind,
            'direction' => (int) $row->direction > 0 ? 'In' : 'Out',
            'branch_name' => (string) ($row->branch_name ?? ''),
            'document_no' => (string) ($row->document_no ?? ''),
            'document_type' => $row->document_type === null ? 'Manual entry' : ucfirst(str_replace('_', ' ', (string) $row->document_type)),
            'note' => (string) ($row->note ?? ''),
            'counts' => $counted->has($row->id) ? 'Yes' : 'Reversed',
        ])->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    public function summary(Collection $rows): array
    {
        return [
            'movements' => $rows->count(),
            'serials' => $rows->pluck('serial_no')->unique()->count(),
            'reversed' => $rows->where('counts', 'Reversed')->count(),
        ];
    }
}
