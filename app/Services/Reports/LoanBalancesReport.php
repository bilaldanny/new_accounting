<?php

namespace App\Services\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Employee loans and advances with what was given, what was recovered through payroll and what is still owed. `status` narrows to
 * active or closed loans (all by default).
 */
class LoanBalancesReport
{
    public const SORTABLE = ['employee_no' => 'employee_no', 'name' => 'name', 'kind' => 'kind', 'amount' => 'amount', 'recovered' => 'recovered', 'balance' => 'balance'];

    public const DEFAULT_SORT = 'balance';

    public const DEFAULT_DESC = true;

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?int $companyId, ?int $branchId, array $filters): Collection
    {
        $status = (string) ($filters['status'] ?? 'all');
        $search = trim((string) ($filters['search'] ?? ''));

        return DB::table('employee_loans as l')->join('employees as e', 'e.id', '=', 'l.employee_id')->whereNull('e.deleted_at')
            ->when($companyId !== null, fn ($q) => $q->where('l.company_id', $companyId))
            ->when($branchId !== null, fn ($q) => $q->where('e.branch_id', $branchId))
            ->when(in_array($status, ['active', 'closed'], true), fn ($q) => $q->where('l.status', $status))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('e.employee_no', 'like', "%{$search}%")->orWhere('e.first_name', 'like', "%{$search}%")->orWhere('e.last_name', 'like', "%{$search}%")))
            ->orderBy('l.id')
            ->get(['l.id', 'e.employee_no', 'e.first_name', 'e.last_name', 'l.kind', 'l.amount', 'l.installment', 'l.start_period', 'l.balance', 'l.status'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id, 'employee_no' => $row->employee_no, 'name' => trim($row->first_name.' '.$row->last_name), 'kind' => $row->kind, 'amount' => round((float) $row->amount, 2),
                'installment' => round((float) $row->installment, 2), 'start_period' => $row->start_period, 'recovered' => round((float) $row->amount - (float) $row->balance, 2),
                'balance' => round((float) $row->balance, 2), 'status' => $row->status,
            ])->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summary(Collection $rows): array
    {
        return ['loans' => $rows->count(), 'amount' => round((float) $rows->sum('amount'), 2), 'recovered' => round((float) $rows->sum('recovered'), 2), 'balance' => round((float) $rows->sum('balance'), 2)];
    }
}
