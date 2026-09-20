<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Stock adjustments, one row per adjustment document.
 *
 * Every adjustment is listed, whatever its status, until `status` narrows it to one (completed or
 * pending); `all` is the same as no status. Only completed ones reach stock (StockMovements), so a
 * report that mixes statuses shows pending value that has not moved stock yet. The value is the
 * document's final amount, split by adjustment type (normal, abnormal, unboxing, opening). The old
 * report's "amount recovered" column has no counterpart in the schema and is not carried over.
 */
class StockAdjustmentReport
{
    public const TYPES = ['normal', 'abnormal', 'unboxing', 'opening'];

    public const STATUSES = ['completed', 'pending'];

    public const SORTABLE = [
        'transaction_date' => 't.transaction_date',
        'invoice_no' => 't.invoice_no',
        'adjustment_type' => 't.adjustment_type',
        'status' => 't.status',
        'total_item' => 't.total_item',
        'final_amount' => 't.final_amount',
        'branch_name' => 'b.name',
        'company_name' => 'co.name',
    ];

    /**
     * @param  array{adjustment_type?: ?string, status?: ?string, start_date?: ?string, end_date?: ?string, search?: ?string}  $filters
     */
    public function query(?int $companyId, ?int $branchId, array $filters = []): Builder
    {
        return $this->base($companyId, $branchId, $filters)->select([
            't.id',
            't.transaction_date',
            't.invoice_no',
            't.adjustment_type',
            't.status',
            't.total_item',
            't.final_amount',
            't.additional_note',
            'b.name as branch_name',
            'co.name as company_name',
            'u.first_name as created_by_first_name',
            'u.last_name as created_by_last_name',
        ]);
    }

    /**
     * @param  array{adjustment_type?: ?string, status?: ?string, start_date?: ?string, end_date?: ?string, search?: ?string}  $filters
     * @return array{count: int, total: float, by_type: array<string, float>}
     */
    public function summary(?int $companyId, ?int $branchId, array $filters = []): array
    {
        $byType = $this->base($companyId, $branchId, $filters)
            ->selectRaw('t.adjustment_type as adjustment_type')
            ->selectRaw('count(*) as adjustments')
            ->selectRaw('coalesce(sum(t.final_amount), 0) as amount')
            ->groupBy('t.adjustment_type')
            ->get();

        $types = array_fill_keys(self::TYPES, 0.0);

        foreach ($byType as $row) {
            $types[(string) $row->adjustment_type] = round((float) $row->amount, 2);
        }

        return [
            'count' => (int) $byType->sum('adjustments'),
            'total' => round((float) array_sum($types), 2),
            'by_type' => $types,
        ];
    }

    public static function createdByName(stdClass $row): string
    {
        $name = trim(trim((string) ($row->created_by_first_name ?? '')).' '.trim((string) ($row->created_by_last_name ?? '')));

        return $name !== '' ? $name : '-';
    }

    /**
     * @param  array{adjustment_type?: ?string, status?: ?string, start_date?: ?string, end_date?: ?string, search?: ?string}  $filters
     */
    private function base(?int $companyId, ?int $branchId, array $filters): Builder
    {
        $type = trim((string) ($filters['adjustment_type'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));
        $startDate = trim((string) ($filters['start_date'] ?? ''));
        $endDate = trim((string) ($filters['end_date'] ?? ''));
        $search = trim((string) ($filters['search'] ?? ''));

        return DB::table('transactions as t')
            ->leftJoin('branches as b', 'b.id', '=', 't.branch_id')
            ->leftJoin('companies as co', 'co.id', '=', 't.company_id')
            ->leftJoin('users as u', 'u.id', '=', 't.created_by')
            ->where('t.type', Transaction::TYPE_ADJUSTMENT)
            ->whereNull('t.deleted_at')
            ->when($status !== '' && $status !== 'all', fn (Builder $query) => $query->where('t.status', $status))
            ->when($companyId !== null, fn (Builder $query) => $query->where('t.company_id', $companyId))
            ->when($branchId !== null, fn (Builder $query) => $query->where('t.branch_id', $branchId))
            ->when($type !== '' && $type !== 'all', fn (Builder $query) => $query->where('t.adjustment_type', $type))
            ->when($startDate !== '', fn (Builder $query) => $query->whereDate('t.transaction_date', '>=', $startDate))
            ->when($endDate !== '', fn (Builder $query) => $query->whereDate('t.transaction_date', '<=', $endDate))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $sub) use ($search): void {
                $sub->where('t.invoice_no', 'like', "%{$search}%")
                    ->orWhere('t.additional_note', 'like', "%{$search}%");
            }));
    }
}
