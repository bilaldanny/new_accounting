<?php

namespace App\Services\Reports;

use App\Services\StockMovements;
use App\Models\Transaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Stock transfers between branches, one row per transfer document.
 *
 * Every status is listed until one is picked (`pending` or `completed`); only completed transfers move
 * stock (StockMovements), so the summary splits the value by status. The range is on the transfer date,
 * inclusive at both ends. A branch user sees the transfers they sent and the ones sent to them; the
 * from and to filters narrow it further. The old report tested the wrong request field for the from
 * branch and added a day to the end date; neither is carried over.
 */
class StockTransferReport
{
    public const STATUSES = ['completed', 'pending'];

    public const SORTABLE = [
        'transaction_date' => 'transaction_date',
        'invoice_no' => 'invoice_no',
        'from_branch' => 'from_branch',
        'to_branch' => 'to_branch',
        'status' => 'status',
        'total_item' => 'total_item',
        'final_amount' => 'final_amount',
        'created_by_name' => 'created_by_name',
    ];

    /**
     * @param  array{from_branch_id?: mixed, to_branch_id?: mixed, status?: ?string, start_date?: ?string, end_date?: ?string, search?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters = []): Collection
    {
        $fromBranch = $filters['from_branch_id'] ?? null;
        $toBranch = $filters['to_branch_id'] ?? null;
        $status = trim((string) ($filters['status'] ?? ''));
        $startDate = trim((string) ($filters['start_date'] ?? ''));
        $endDate = trim((string) ($filters['end_date'] ?? ''));
        $search = trim((string) ($filters['search'] ?? ''));

        return DB::table('transactions as t')
            ->leftJoin('branches as fb', 'fb.id', '=', 't.branch_id')
            ->leftJoin('branches as tb', 'tb.id', '=', 't.tobranch_id')
            ->leftJoin('users as u', 'u.id', '=', 't.created_by')
            ->where('t.type', Transaction::TYPE_TRANSFER)
            ->whereNull('t.deleted_at')
            ->when($companyId !== null, fn (Builder $q) => $q->where('t.company_id', $companyId))
            ->when($branchId !== null, fn (Builder $q) => $q->where(fn (Builder $mine) => $mine
                ->where('t.branch_id', $branchId)
                ->orWhere('t.tobranch_id', $branchId)))
            ->when(! empty($fromBranch), fn (Builder $q) => $q->where('t.branch_id', $fromBranch))
            ->when(! empty($toBranch), fn (Builder $q) => $q->where('t.tobranch_id', $toBranch))
            ->when($status !== '' && $status !== 'all', fn (Builder $q) => $q->where('t.status', $status))
            ->when($startDate !== '', fn (Builder $q) => $q->whereDate('t.transaction_date', '>=', $startDate))
            ->when($endDate !== '', fn (Builder $q) => $q->whereDate('t.transaction_date', '<=', $endDate))
            ->when($search !== '', fn (Builder $q) => $q->where(function (Builder $sub) use ($search): void {
                $sub->where('t.invoice_no', 'like', "%{$search}%")->orWhere('t.additional_note', 'like', "%{$search}%");
            }))
            ->select([
                't.id',
                't.transaction_date',
                't.invoice_no',
                't.status',
                't.total_item',
                't.final_amount',
                't.additional_note',
                'fb.name as from_branch',
                'tb.name as to_branch',
                'u.first_name as created_by_first_name',
                'u.last_name as created_by_last_name',
            ])
            ->get()
            ->map(fn (stdClass $row): array => [
                'id' => (int) $row->id,
                'transaction_date' => substr((string) $row->transaction_date, 0, 10),
                'invoice_no' => $row->invoice_no,
                'from_branch' => (string) ($row->from_branch ?? ''),
                'to_branch' => (string) ($row->to_branch ?? ''),
                'status' => (string) $row->status,
                'total_item' => (int) $row->total_item,
                'final_amount' => round((float) $row->final_amount, 2),
                'additional_note' => $row->additional_note,
                'created_by_name' => StockAdjustmentReport::createdByName($row),
            ])
            ->sortBy([['transaction_date', 'desc'], ['id', 'desc']])
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{count: int, total: float, completed: float, pending: float}
     */
    public function summary(Collection $rows): array
    {
        $byStatus = fn (string $status): float => round((float) $rows->where('status', $status)->sum('final_amount'), 2);

        return [
            'count' => $rows->count(),
            'total' => round((float) $rows->sum('final_amount'), 2),
            'completed' => $byStatus('completed'),
            'pending' => $byStatus('pending'),
        ];
    }
}
