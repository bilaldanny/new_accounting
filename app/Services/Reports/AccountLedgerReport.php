<?php

namespace App\Services\Reports;

use App\Services\ContactLedger;
use App\Models\ChartOfAccount;
use App\Models\FinancialYear;
use App\Support\AccountClass;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The general ledger: the statement of any chart-of-accounts account, a posting account or a group.
 * A group (a control account) is the sum of every posting account below it, each line still naming its
 * own account. This is not the party ledger (ContactLedger), which is a customer's or supplier's
 * statement.
 *
 * Only approved vouchers are read, from the account's code in every branch (or one branch when asked),
 * the way the chart of accounts and the party ledger count them. The balance runs on the side the
 * account normally carries, decided from the first digit of its code (AccountClass) rather than from the
 * unreliable stored nature; a balance on the other side is negative. The opening balance is the stored
 * opening balance of the active financial year plus the postings from its start up to the day before the
 * range, as the party ledger does; with no active financial year it is the postings before the range.
 * The range is inclusive at both ends and defaults to the financial year up to today.
 */
class AccountLedgerReport
{
    public const SORTABLE = [
        'voucher_date' => 'voucher_date',
        'voucher_no' => 'voucher_no',
        'ref_no' => 'ref_no',
        'account_code' => 'account_code',
        'description' => 'description',
        'debit' => 'debit',
        'credit' => 'credit',
        'balance' => 'balance',
    ];

    /**
     * @return array{account: array<string, mixed>|null, rows: Collection<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function statement(int $companyId, ?int $branchId, ?string $code, ?string $start, ?string $end): array
    {
        $code = trim((string) $code);
        $accounts = $code === '' ? collect() : ChartOfAccount::query()->where('company_id', $companyId)->where('code', $code)->get();

        if ($accounts->isEmpty()) {
            return ['account' => null, 'rows' => collect(), 'summary' => $this->summary(0.0, collect(), 'dr')];
        }

        $first = $accounts->first();
        $nature = AccountClass::nature($code, (string) ($first->acc_nature ?: 'dr'));
        $codes = $this->postingCodes($companyId, $accounts);
        $names = $this->names($companyId, $codes);

        $financialYear = FinancialYear::query()->where('company_id', $companyId)->where('status', true)->orderByDesc('id')->first();
        $yearStart = $financialYear?->start_date?->toDateString();
        $from = $start !== null && $start !== '' ? $start : ($yearStart ?? '2000-01-01');
        $to = $end !== null && $end !== '' ? $end : Carbon::today()->toDateString();

        $opening = $this->opening($companyId, $branchId, $codes, $financialYear, $yearStart, $from, $nature);

        $balance = $opening;
        $rows = $this->lines($companyId, $branchId, $codes, $from, $to)
            ->get()
            ->map(function (object $line) use (&$balance, $nature, $names): array {
                $debit = round((float) $line->debit, 2);
                $credit = round((float) $line->credit, 2);
                $balance = round($balance + ($nature === 'dr' ? $debit - $credit : $credit - $debit), 2);

                return [
                    'id' => (int) $line->id,
                    'voucher_date' => substr((string) $line->voucher_date, 0, 10),
                    'voucher_no' => $line->voucher_no,
                    'ref_no' => (string) ($line->ref_no ?? ''),
                    'cheque_no' => (string) ($line->cheque_no ?? ''),
                    'account_code' => (string) $line->account_code,
                    'account_name' => (string) ($names[(string) $line->account_code] ?? ''),
                    'description' => (string) ($line->description ?? ''),
                    'branch_name' => (string) ($line->branch_name ?? ''),
                    'debit' => $debit,
                    'credit' => $credit,
                    'balance' => $balance,
                ];
            })
            ->values();

        return [
            'account' => [
                'code' => $code,
                'name' => (string) $first->name,
                'class' => AccountClass::label($code),
                'nature' => $nature,
                'is_group' => $first->acc_type === 'c',
                'from' => $from,
                'to' => $to,
            ],
            'rows' => $rows,
            'summary' => $this->summary($opening, $rows, $nature),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function summary(float $opening, Collection $rows, string $nature): array
    {
        $debit = round((float) $rows->sum('debit'), 2);
        $credit = round((float) $rows->sum('credit'), 2);
        $movement = $nature === 'dr' ? $debit - $credit : $credit - $debit;

        return [
            'count' => $rows->count(),
            'nature' => $nature,
            'opening' => round($opening, 2),
            'debit' => $debit,
            'credit' => $credit,
            'closing' => round($opening + $movement, 2),
        ];
    }

    /**
     * The posting account codes an account stands for: itself, or every posting account below a group.
     *
     * @param  Collection<int, ChartOfAccount>  $accounts  the rows of the code (one per branch)
     * @return list<string>
     */
    private function postingCodes(int $companyId, Collection $accounts): array
    {
        $codes = $accounts->where('acc_type', 't')->pluck('code')->all();
        $parents = $accounts->where('acc_type', 'c')->pluck('id')->all();
        $seen = [];

        while ($parents !== []) {
            $seen = [...$seen, ...$parents];
            $children = ChartOfAccount::query()
                ->where('company_id', $companyId)
                ->whereIn('parent_id', $parents)
                ->get(['id', 'code', 'acc_type']);

            $codes = [...$codes, ...$children->where('acc_type', 't')->pluck('code')->all()];
            $parents = $children->where('acc_type', 'c')->pluck('id')->diff($seen)->values()->all();
        }

        return array_values(array_unique(array_map('strval', $codes)));
    }

    /**
     * @param  list<string>  $codes
     * @return array<string, string>
     */
    private function names(int $companyId, array $codes): array
    {
        return ChartOfAccount::query()
            ->where('company_id', $companyId)
            ->whereIn('code', $codes)
            ->orderBy('id')
            ->pluck('name', 'code')
            ->all();
    }

    /**
     * @param  list<string>  $codes
     */
    private function opening(int $companyId, ?int $branchId, array $codes, ?FinancialYear $year, ?string $yearStart, string $from, string $nature): float
    {
        $sign = $nature === 'dr' ? 1 : -1;
        $debitPositive = 0.0;

        if ($year !== null && $codes !== []) {
            $debitPositive += (float) DB::table('account_balances as ab')
                ->join('chart_of_accounts as coa', 'coa.id', '=', 'ab.coa_id')
                ->where('ab.company_id', $companyId)
                ->where('ab.financial_id', $year->id)
                ->whereIn('coa.code', $codes)
                ->when($branchId !== null, fn (Builder $query) => $query->where('ab.branch_id', $branchId))
                ->selectRaw("coalesce(sum(case when ab.acc_nature = 'cr' then -ab.opening_balance else ab.opening_balance end), 0) as opening")
                ->value('opening');
        }

        $priorFrom = $yearStart;

        if ($codes !== [] && ($priorFrom === null || $from > $priorFrom)) {
            $prior = $this->details($companyId, $branchId, $codes)
                ->when($priorFrom !== null, fn (Builder $query) => $query->whereDate('a.voucher_date', '>=', $priorFrom))
                ->whereDate('a.voucher_date', '<', $from)
                ->selectRaw('coalesce(sum(d.debit), 0) as debit, coalesce(sum(d.credit), 0) as credit')
                ->first();

            $debitPositive += (float) $prior->debit - (float) $prior->credit;
        }

        return round($debitPositive * $sign, 2);
    }

    /**
     * @param  list<string>  $codes
     */
    private function lines(int $companyId, ?int $branchId, array $codes, string $from, string $to): Builder
    {
        return $this->details($companyId, $branchId, $codes)
            ->leftJoin('branches as b', 'b.id', '=', 'a.branch_id')
            ->whereDate('a.voucher_date', '>=', $from)
            ->whereDate('a.voucher_date', '<=', $to)
            ->orderBy('a.voucher_date')
            ->orderBy('d.id')
            ->select([
                'd.id',
                'a.voucher_date',
                'a.voucher_no',
                'a.ref_no',
                'a.cheque_no',
                'd.account_code',
                'd.description',
                'd.debit',
                'd.credit',
                'b.name as branch_name',
            ]);
    }

    /**
     * @param  list<string>  $codes
     */
    private function details(int $companyId, ?int $branchId, array $codes): Builder
    {
        return DB::table('t_account_details as d')
            ->join('t_accounts as a', 'a.id', '=', 'd.t_account_id')
            ->where('a.company_id', $companyId)
            ->where('a.status', 'approved')
            ->whereIn('d.account_code', $codes)
            ->when($branchId !== null, fn (Builder $query) => $query->where('a.branch_id', $branchId));
    }
}
