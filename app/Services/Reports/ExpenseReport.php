<?php

namespace App\Services\Reports;

use App\Models\TAccount;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Expenses, one row per debit line of an approved expense voucher (EXP).
 *
 * Only approved vouchers count, the same rule the ledger and the chart of accounts use: a pending or
 * rejected expense is not an expense yet. The old system had no expense report; its profit and loss
 * matched `voucher_no LIKE '%EX%'` on every status. Each debit line is the amount charged to one
 * expense account, so one voucher split over several accounts shows one row per account and a
 * per-account filter or total is exact. The credit leg (cash or bank) is not an expense and is left
 * out.
 */
class ExpenseReport
{
    public const SORTABLE = [
        'voucher_date' => 'a.voucher_date',
        'voucher_no' => 'a.voucher_no',
        'ref_no' => 'a.ref_no',
        'account_code' => 'd.account_code',
        'account_name' => 'coa.name',
        'branch_name' => 'b.name',
        'company_name' => 'co.name',
        'amount' => 'd.debit',
    ];

    /**
     * @param  array{account_id?: mixed, start_date?: ?string, end_date?: ?string, search?: ?string}  $filters
     */
    public function query(?int $companyId, ?int $branchId, array $filters = []): Builder
    {
        return $this->base($companyId, $branchId, $filters)->select([
            'd.id',
            'a.id as voucher_id',
            'a.voucher_date',
            'a.voucher_no',
            'a.ref_no',
            'a.comments',
            'd.account_code',
            'coa.name as account_name',
            'd.description',
            'd.debit as amount',
            'b.name as branch_name',
            'co.name as company_name',
        ]);
    }

    /**
     * @param  array{account_id?: mixed, start_date?: ?string, end_date?: ?string, search?: ?string}  $filters
     * @return array{count: int, vouchers: int, total_amount: float}
     */
    public function summary(?int $companyId, ?int $branchId, array $filters = []): array
    {
        $row = $this->base($companyId, $branchId, $filters)
            ->selectRaw('count(*) as expense_lines')
            ->selectRaw('count(distinct a.id) as vouchers')
            ->selectRaw('coalesce(sum(d.debit), 0) as total')
            ->first();

        return [
            'count' => (int) $row->expense_lines,
            'vouchers' => (int) $row->vouchers,
            'total_amount' => round((float) $row->total, 2),
        ];
    }

    /**
     * @param  array{account_id?: mixed, start_date?: ?string, end_date?: ?string, search?: ?string}  $filters
     */
    private function base(?int $companyId, ?int $branchId, array $filters): Builder
    {
        $accountId = $filters['account_id'] ?? null;
        $startDate = trim((string) ($filters['start_date'] ?? ''));
        $endDate = trim((string) ($filters['end_date'] ?? ''));
        $search = trim((string) ($filters['search'] ?? ''));

        return DB::table('t_account_details as d')
            ->join('t_accounts as a', 'a.id', '=', 'd.t_account_id')
            ->leftJoin('chart_of_accounts as coa', 'coa.id', '=', 'd.coa_id')
            ->leftJoin('branches as b', 'b.id', '=', 'a.branch_id')
            ->leftJoin('companies as co', 'co.id', '=', 'a.company_id')
            ->whereNull('a.transaction_id')
            ->where('a.status', TAccount::STATUS_APPROVED)
            ->where(function (Builder $vouchers): void {
                foreach (TAccount::EXPENSE_VOUCHER_TYPES as $type) {
                    $vouchers->orWhere('a.voucher_no', 'like', "{$type}-%");
                }
            })
            ->where('d.debit', '>', 0)
            ->when($companyId !== null, fn (Builder $query) => $query->where('a.company_id', $companyId))
            ->when($branchId !== null, fn (Builder $query) => $query->where('a.branch_id', $branchId))
            ->when(! empty($accountId), fn (Builder $query) => $query->where('d.coa_id', $accountId))
            ->when($startDate !== '', fn (Builder $query) => $query->whereDate('a.voucher_date', '>=', $startDate))
            ->when($endDate !== '', fn (Builder $query) => $query->whereDate('a.voucher_date', '<=', $endDate))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $sub) use ($search): void {
                $sub->where('a.voucher_no', 'like', "%{$search}%")
                    ->orWhere('a.ref_no', 'like', "%{$search}%")
                    ->orWhere('d.description', 'like', "%{$search}%")
                    ->orWhere('coa.name', 'like', "%{$search}%")
                    ->orWhere('d.account_code', 'like', "%{$search}%");
            }));
    }
}
