<?php

namespace App\Services\Reports;

use App\Services\StockTracking;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Batch Expiry Report: every batch that still has stock, per branch, with its expiry date, the days left and a status - Expired,
 * Expiring soon (within 30 days), OK or No expiry date - the earliest expiry first. The status filter narrows it and the end
 * date keeps the batches that expire on or before it. Read-only.
 */
class BatchExpiryReport
{
    public const EXPIRING_DAYS = 30;

    public const SORTABLE = [
        'id' => 'id',
        'product_name' => 'product_name',
        'batch_no' => 'batch_no',
        'branch_name' => 'branch_name',
        'expiry_date' => 'expiry_date',
        'days_left' => 'days_left',
        'qty' => 'qty',
        'status' => 'status',
    ];

    public const DEFAULT_SORT = 'expiry_date';

    public const DEFAULT_DESC = false;

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        $state = trim((string) ($filters['status'] ?? ''));
        $productId = $filters['product_id'] ?? null;
        $search = trim((string) ($filters['search'] ?? ''));
        $end = trim((string) ($filters['end_date'] ?? ''));
        $today = now()->startOfDay();

        return DB::query()->fromSub(StockTracking::batchStock(), 'x')
            ->join('products as p', 'p.id', '=', 'x.product_id')
            ->leftJoin('branches as b', 'b.id', '=', 'x.branch_id')
            ->where('x.qty', '>', 0)
            ->when($companyId !== null, fn ($q) => $q->where('x.company_id', $companyId))
            ->when($branchId !== null, fn ($q) => $q->where('x.branch_id', $branchId))
            ->when(! empty($productId), fn ($q) => $q->where('x.product_id', $productId))
            ->when($end !== '', fn ($q) => $q->whereNotNull('x.expiry_date')->whereDate('x.expiry_date', '<=', $end))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('x.batch_no', 'like', "%{$search}%")->orWhere('p.name', 'like', "%{$search}%")))
            ->select(['x.batch_id', 'x.branch_id', 'x.batch_no', 'x.expiry_date', 'p.name as product_name', 'b.name as branch_name', 'x.qty'])
            ->get()
            ->map(function (object $row) use ($today): array {
                $expiry = $row->expiry_date === null ? null : Carbon::parse(substr((string) $row->expiry_date, 0, 10))->startOfDay();
                $days = $expiry === null ? null : (int) $today->diffInDays($expiry, false);

                return [
                    'id' => (int) $row->batch_id * 1000 + (int) $row->branch_id % 1000,
                    'product_name' => $row->product_name,
                    'batch_no' => $row->batch_no,
                    'branch_name' => (string) ($row->branch_name ?? ''),
                    'expiry_date' => $expiry?->toDateString() ?? 'No expiry',
                    'days_left' => $days,
                    'qty' => round((float) $row->qty, 4),
                    'status' => match (true) {
                        $days === null => 'No expiry date',
                        $days < 0 => 'Expired',
                        $days <= self::EXPIRING_DAYS => 'Expiring soon',
                        default => 'OK',
                    },
                ];
            })
            ->when($state !== '', fn (Collection $rows) => $rows->where('status', $state))
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        return [
            'batches' => $rows->count(),
            'qty' => round((float) $rows->sum('qty'), 4),
            'expired_qty' => round((float) $rows->where('status', 'Expired')->sum('qty'), 4),
            'expiring_qty' => round((float) $rows->where('status', 'Expiring soon')->sum('qty'), 4),
        ];
    }
}
