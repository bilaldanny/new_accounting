<?php

namespace App\Services\Reports;

use App\Models\ChartOfAccount;
use App\Models\FinancialYear;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The ledger figures the financial statements are built from, per account code: the stored opening
 * balances of the active financial year and the approved postings. Everything is debit positive (debit
 * less credit); the statements turn that into the side each account normally carries. Codes are read
 * across the branches, or one branch when asked.
 */
class AccountFigures
{
    public function financialYear(int $companyId): ?FinancialYear
    {
        return FinancialYear::query()->where('company_id', $companyId)->where('status', true)->orderByDesc('id')->first();
    }

    /**
     * The range a report covers: the given days, else the financial year up to today.
     *
     * @return array{0: string, 1: string} from and to, `Y-m-d`
     */
    public function range(?string $start, ?string $end, ?FinancialYear $year): array
    {
        $start = trim((string) $start);
        $end = trim((string) $end);

        return [
            $start !== '' ? $start : ($year?->start_date?->toDateString() ?? '2000-01-01'),
            $end !== '' ? $end : Carbon::today()->toDateString(),
        ];
    }

    /**
     * Stored opening balances of the active financial year by account code, debit positive.
     *
     * @return Collection<string, float>
     */
    public function stored(int $companyId, ?int $branchId, ?FinancialYear $year): Collection
    {
        if ($year === null) {
            return collect();
        }

        return DB::table('account_balances as ab')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'ab.coa_id')
            ->where('ab.company_id', $companyId)
            ->where('ab.financial_id', $year->id)
            ->when($branchId !== null, fn (Builder $query) => $query->where('ab.branch_id', $branchId))
            ->groupBy('coa.code')
            ->selectRaw("coa.code as code, coalesce(sum(case when ab.acc_nature = 'cr' then -ab.opening_balance else ab.opening_balance end), 0) as opening")
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->code => (float) $row->opening]);
    }

    /**
     * Net posting (debit less credit) by account code from `$from` (the beginning when null) up to `$to`,
     * which is included unless `$exclusiveEnd`.
     *
     * @return Collection<string, float>
     */
    public function net(int $companyId, ?int $branchId, ?string $from, string $to, bool $exclusiveEnd = false): Collection
    {
        return $this->details($companyId, $branchId)
            ->when($from !== null, fn (Builder $query) => $query->whereDate('a.voucher_date', '>=', $from))
            ->whereDate('a.voucher_date', $exclusiveEnd ? '<' : '<=', $to)
            ->groupBy('d.account_code')
            ->selectRaw('d.account_code as code, coalesce(sum(d.debit), 0) - coalesce(sum(d.credit), 0) as net')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->code => (float) $row->net]);
    }

    /**
     * Debit and credit totals by account code inside the range, both days included.
     *
     * @return Collection<string, array{debit: float, credit: float}>
     */
    public function period(int $companyId, ?int $branchId, string $from, string $to): Collection
    {
        return $this->details($companyId, $branchId)
            ->whereDate('a.voucher_date', '>=', $from)
            ->whereDate('a.voucher_date', '<=', $to)
            ->groupBy('d.account_code')
            ->selectRaw('d.account_code as code, coalesce(sum(d.debit), 0) as debit, coalesce(sum(d.credit), 0) as credit')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->code => ['debit' => (float) $row->debit, 'credit' => (float) $row->credit]]);
    }

    /**
     * @param  iterable<int|string>  $codes
     * @return Collection<string, string>
     */
    public function names(int $companyId, iterable $codes): Collection
    {
        return ChartOfAccount::query()
            ->where('company_id', $companyId)
            ->whereIn('code', collect($codes)->map(fn (mixed $code): string => (string) $code)->all())
            ->orderBy('id')
            ->pluck('name', 'code');
    }

    private function details(int $companyId, ?int $branchId): Builder
    {
        return DB::table('t_account_details as d')
            ->join('t_accounts as a', 'a.id', '=', 'd.t_account_id')
            ->where('a.company_id', $companyId)
            ->where('a.status', 'approved')
            ->when($branchId !== null, fn (Builder $query) => $query->where('a.branch_id', $branchId));
    }
}
