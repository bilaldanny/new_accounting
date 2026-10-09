<?php

namespace App\Services\Reports;

use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Backorder Report: purchase order lines that are still waiting for goods. A line is open when the order is
 * still pending, ordered or approved (not yet received) and its quantity less what was received and returned
 * is above zero. The date range, when given, is the order date.
 */
class BackorderReport
{
    public const SORTABLE = [
        'transaction_date' => 'transaction_date',
        'invoice_no' => 'invoice_no',
        'supplier_name' => 'supplier_name',
        'product_name' => 'product_name',
        'ordered' => 'ordered',
        'received' => 'received',
        'pending' => 'pending',
        'days_open' => 'days_open',
    ];

    public const DEFAULT_SORT = 'days_open';

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $today = Carbon::today();

        return DB::table('purchase_lines as l')
            ->join('transactions as t', 't.id', '=', 'l.transaction_id')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->where('t.type', Transaction::TYPE_PURCHASE)
            ->whereIn('t.status', ['pending', 'ordered', 'approved'])
            ->whereNull('t.deleted_at')
            ->when($companyId !== null, fn ($q) => $q->where('t.company_id', $companyId))
            ->when($branchId !== null, fn ($q) => $q->where('t.branch_id', $branchId))
            ->when(! empty($filters['start_date']), fn ($q) => $q->whereDate('t.transaction_date', '>=', $filters['start_date']))
            ->when(! empty($filters['end_date']), fn ($q) => $q->whereDate('t.transaction_date', '<=', $filters['end_date']))
            ->when($search !== '', fn ($q) => $q->where(fn ($s) => $s->where('t.invoice_no', 'like', "%{$search}%")->orWhere('p.name', 'like', "%{$search}%")->orWhere('c.business_name', 'like', "%{$search}%")))
            ->whereRaw('(l.quantity - l.quantity_received - l.quantity_returned) > 0')
            ->get(['l.id', 't.transaction_date', 't.invoice_no', 't.status', 'c.business_name', 'p.name as product_name', 'l.quantity', 'l.quantity_received', 'l.quantity_returned'])
            ->map(fn (object $line): array => [
                'id' => (int) $line->id,
                'transaction_date' => substr((string) $line->transaction_date, 0, 10),
                'invoice_no' => $line->invoice_no,
                'status' => $line->status,
                'supplier_name' => $line->business_name,
                'product_name' => $line->product_name,
                'ordered' => round((float) $line->quantity, 2),
                'received' => round((float) $line->quantity_received, 2),
                'pending' => round((float) $line->quantity - (float) $line->quantity_received - (float) $line->quantity_returned, 2),
                'days_open' => max((int) Carbon::parse($line->transaction_date)->startOfDay()->diffInDays($today, false), 0),
            ])
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        return [
            'lines' => $rows->count(),
            'orders' => $rows->pluck('invoice_no')->unique()->count(),
            'ordered' => round((float) $rows->sum('ordered'), 2),
            'pending' => round((float) $rows->sum('pending'), 2),
            'oldest_days' => (int) $rows->max('days_open'),
        ];
    }
}
