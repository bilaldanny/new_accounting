<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\FinancialYear;
use App\Support\AccountClass;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The trial balance: every posting account with what it opened with, what was posted in the range and
 * where it stands, each as a debit or a credit, so the debit and credit columns can be checked against
 * each other.
 *
 * Read from approved vouchers only (the ledger's own figures), one row per account code across the
 * branches (or one branch when asked). The opening balance is the stored opening balance of the active
 * financial year plus the postings from its start up to the day before the range, as in the party
 * ledger; the range is inclusive at both ends and defaults to the financial year up to today. The class
 * of an account is its first digit (AccountClass), not the stored flags. An account with no opening
 * balance and no posting is not listed. The report always states its own check: total debits less total
 * credits (0.00 when the books balance), for the opening, the period and the closing.
 */
class TrialBalanceReport
{
    public const SORTABLE = [
        'code' => 'code',
        'name' => 'name',
        'class' => 'class',
        'opening_debit' => 'opening_debit',
        'opening_credit' => 'opening_credit',
        'debit' => 'debit',
        'credit' => 'credit',
        'closing_debit' => 'closing_debit',
        'closing_credit' => 'closing_credit',
    ];

    /**
     * @param  array{account_group?: mixed, start_date?: ?string, end_date?: ?string, search?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(int $companyId, ?int $branchId, array $filters = []): Collection
    {
        $group = (int) ($filters['account_group'] ?? 0);
        $search = strtolower(trim((string) ($filters['search'] ?? '')));

        $financialYear = FinancialYear::query()->where('company_id', $companyId)->where('status', true)->orderByDesc('id')->first();
        $yearStart = $financialYear?->start_date?->toDateString();
        $start = trim((string) ($filters['start_date'] ?? ''));
        $from = $start !== '' ? $start : ($yearStart ?? '2000-01-01');
        $end = trim((string) ($filters['end_date'] ?? ''));
        $to = $end !== '' ? $end : Carbon::today()->toDateString();

        $stored = $this->stored($companyId, $branchId, $financialYear);
        $prior = $from > ($yearStart ?? '') || $yearStart === null ? $this->net($companyId, $branchId, $yearStart, $from, exclusiveEnd: true) : collect();
        $period = $this->period($companyId, $branchId, $from, $to);

        $codes = $stored->keys()->merge($prior->keys())->merge($period->keys())->unique()->values();
        $names = ChartOfAccount::query()->where('company_id', $companyId)->whereIn('code', $codes)->orderBy('id')->pluck('name', 'code');

        return $codes
            ->map(function (string $code) use ($stored, $prior, $period, $names): array {
                $opening = round((float) ($stored[$code] ?? 0) + (float) ($prior[$code] ?? 0), 2);
                $debit = round((float) ($period[$code]['debit'] ?? 0), 2);
                $credit = round((float) ($period[$code]['credit'] ?? 0), 2);
                $closing = round($opening + $debit - $credit, 2);

                return [
                    'id' => 0,
                    'code' => $code,
                    'name' => (string) ($names[$code] ?? ''),
                    'class' => AccountClass::label($code),
                    'class_digit' => AccountClass::of($code)['digit'] ?? 0,
                    'opening_debit' => $opening > 0 ? $opening : 0.0,
                    'opening_credit' => $opening < 0 ? -$opening : 0.0,
                    'debit' => $debit,
                    'credit' => $credit,
                    'closing_debit' => $closing > 0 ? $closing : 0.0,
                    'closing_credit' => $closing < 0 ? -$closing : 0.0,
                ];
            })
            ->reject(fn (array $row): bool => $row['opening_debit'] == 0 && $row['opening_credit'] == 0 && $row['debit'] == 0 && $row['credit'] == 0)
            ->when($group >= 1 && $group <= 6, fn (Collection $rows) => $rows->where('class_digit', $group))
            ->when($search !== '', fn (Collection $rows) => $rows->filter(fn (array $row): bool => str_contains(strtolower($row['code'].' '.$row['name']), $search)))
            ->sortBy('code')
            ->values()
            ->map(fn (array $row, int $index): array => ['id' => $index + 1] + $row);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int|bool>
     */
    public function summary(Collection $rows): array
    {
        $sum = fn (string $key): float => round((float) $rows->sum($key), 2);

        $openingDifference = round($sum('opening_debit') - $sum('opening_credit'), 2);
        $periodDifference = round($sum('debit') - $sum('credit'), 2);
        $closingDifference = round($sum('closing_debit') - $sum('closing_credit'), 2);

        return [
            'count' => $rows->count(),
            'opening_debit' => $sum('opening_debit'),
            'opening_credit' => $sum('opening_credit'),
            'debit' => $sum('debit'),
            'credit' => $sum('credit'),
            'closing_debit' => $sum('closing_debit'),
            'closing_credit' => $sum('closing_credit'),
            'opening_difference' => $openingDifference,
            'period_difference' => $periodDifference,
            'closing_difference' => $closingDifference,
            'is_balanced' => $openingDifference == 0.0 && $periodDifference == 0.0 && $closingDifference == 0.0,
        ];
    }

    /**
     * Stored opening balances of the active financial year by account code, debit positive.
     *
     * @return Collection<string, float>
     */
    private function stored(int $companyId, ?int $branchId, ?FinancialYear $year): Collection
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
     * Net posting (debit less credit) by account code from `$from` up to but not including `$to`, or
     * from the beginning when `$from` is null.
     *
     * @return Collection<string, float>
     */
    private function net(int $companyId, ?int $branchId, ?string $from, string $to, bool $exclusiveEnd): Collection
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
     * @return Collection<string, array{debit: float, credit: float}>
     */
    private function period(int $companyId, ?int $branchId, string $from, string $to): Collection
    {
        return $this->details($companyId, $branchId)
            ->whereDate('a.voucher_date', '>=', $from)
            ->whereDate('a.voucher_date', '<=', $to)
            ->groupBy('d.account_code')
            ->selectRaw('d.account_code as code, coalesce(sum(d.debit), 0) as debit, coalesce(sum(d.credit), 0) as credit')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->code => ['debit' => (float) $row->debit, 'credit' => (float) $row->credit]]);
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
