<?php

namespace App\Services;

use App\Models\AccountBalance;
use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\ChartOfAccountMapping;
use App\Models\FinancialYear;
use App\Services\Reports\AccountFigures;
use App\Support\AccountClass;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Carries a closed financial year into the next one as opening balances (`account_balances` rows of the new year).
 *
 * The statements read an account as "stored opening balance of the active year + approved postings since its start", so a new
 * year with no opening rows starts every balance sheet account from zero. This builds those rows from the closing year, per branch,
 * with the same figures the Balance Sheet shows on the last day of that year (AccountFigures):
 *
 * - balance sheet accounts (equity, assets, liabilities): closing balance becomes the new opening balance;
 * - profit and loss accounts (expenses, revenue, cost of goods sold): start the new year at zero, and their net result is added to
 *   the branch's retained earnings account (the account mapped as "Profit And Loss").
 *
 * It does not post a closing voucher: reports already take the profit from the date range, so nothing in the ledger changes.
 * It runs only once the closing year is locked through its last day, writes nothing when the retained earnings account of a
 * branch is not mapped, and can be run again (after an override or reopen let a late entry in) to recompute the same rows.
 */
class YearRollover
{
    public const RETAINED_EARNINGS_KEY = 'pnl';

    public function __construct(private readonly AccountFigures $figures, private readonly PeriodLock $lock) {}

    /**
     * What a rollover would write, without writing it.
     *
     * @return array<string, mixed>
     */
    public function preview(int $companyId, int $fromYearId, int $toYearId): array
    {
        [$from, $to] = $this->years($companyId, $fromYearId, $toYearId);

        $blockers = [];
        $through = $this->lock->lockedThrough($companyId);

        if ($through === null || $through < $from->end_date->toDateString()) {
            $blockers[] = 'Lock the books through '.$from->end_date->toDateString().' (the last day of '.$from->name.') before rolling the year over.';
        }

        $branches = [];

        foreach ($this->branchIds($companyId, $from) as $branchId) {
            $branch = $this->branch($companyId, $branchId, $from, $to);
            $blockers = array_merge($blockers, $branch['blockers']);
            unset($branch['blockers']);
            $branches[] = $branch;
        }

        return [
            'from' => ['id' => $from->id, 'name' => $from->name, 'start_date' => $from->start_date->toDateString(), 'end_date' => $from->end_date->toDateString()],
            'to' => ['id' => $to->id, 'name' => $to->name, 'start_date' => $to->start_date->toDateString(), 'end_date' => $to->end_date->toDateString()],
            'locked_through' => $through,
            'branches' => $branches,
            'blockers' => array_values(array_unique($blockers)),
            'can_run' => $blockers === [],
            'accounts' => array_sum(array_map(fn (array $branch): int => count($branch['rows']), $branches)),
            'note' => 'Activate the new financial year afterwards: the reports read the opening balances of the active year.',
        ];
    }

    /**
     * Writes the opening balances of the new year. Nothing is written unless the preview has no blocker.
     *
     * @return array<string, mixed> the preview that was applied, plus `written`
     *
     * @throws ValidationException
     */
    public function run(int $companyId, int $fromYearId, int $toYearId): array
    {
        $preview = $this->preview($companyId, $fromYearId, $toYearId);

        if ($preview['can_run'] !== true) {
            throw ValidationException::withMessages(['rollover' => $preview['blockers']]);
        }

        $written = 0;

        DB::transaction(function () use ($preview, $companyId, $toYearId, &$written): void {
            foreach ($preview['branches'] as $branch) {
                foreach ($branch['rows'] as $row) {
                    $balance = AccountBalance::query()->updateOrCreate(
                        ['company_id' => $companyId, 'branch_id' => $branch['branch_id'], 'financial_id' => $toYearId, 'coa_id' => $row['coa_id']],
                        ['opening_balance' => $row['opening'], 'acc_nature' => $row['nature']],
                    );

                    $written += $balance->wasRecentlyCreated || $balance->wasChanged() ? 1 : 0;
                }
            }

            AuditLog::query()->create([
                'company_id' => $companyId,
                'branch_id' => null,
                'user_id' => auth()->id(),
                'event' => 'year_rollover',
                'auditable_type' => FinancialYear::class,
                'auditable_id' => $toYearId,
                'old_values' => null,
                'new_values' => [
                    'from_year' => $preview['from']['name'],
                    'to_year' => $preview['to']['name'],
                    'accounts' => $preview['accounts'],
                    'rows_changed' => $written,
                    'net_profit' => collect($preview['branches'])->mapWithKeys(fn (array $branch): array => [(string) $branch['branch_id'] => $branch['net_profit']])->all(),
                ],
                'ip_address' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 255) ?: null,
            ]);
        });

        return $preview + ['written' => $written];
    }

    /**
     * @return array{0: FinancialYear, 1: FinancialYear}
     *
     * @throws ValidationException
     */
    private function years(int $companyId, int $fromYearId, int $toYearId): array
    {
        $from = FinancialYear::query()->where('company_id', $companyId)->find($fromYearId);
        $to = FinancialYear::query()->where('company_id', $companyId)->find($toYearId);

        if ($from === null || $to === null) {
            throw ValidationException::withMessages(['financial_year' => ['Choose the closing year and the new year of this company.']]);
        }

        if ($to->start_date->lte($from->end_date)) {
            throw ValidationException::withMessages(['financial_year' => ['The new year must start after the closing year ends.']]);
        }

        return [$from, $to];
    }

    /**
     * @return list<int>
     */
    private function branchIds(int $companyId, FinancialYear $from): array
    {
        $stored = DB::table('account_balances')->where('company_id', $companyId)->where('financial_id', $from->id)->pluck('branch_id');
        $posted = DB::table('t_accounts')->where('company_id', $companyId)->where('status', 'approved')
            ->whereDate('voucher_date', '>=', $from->start_date->toDateString())->whereDate('voucher_date', '<=', $from->end_date->toDateString())
            ->distinct()->pluck('branch_id');

        return $stored->merge($posted)->filter()->map(fn (mixed $id): int => (int) $id)->unique()->sort()->values()->all();
    }

    /**
     * @return array{branch_id: int, branch_name: string|null, retained_earnings: array{code: string, name: string}|null, net_profit: float, rows: list<array<string, mixed>>, warnings: list<string>, blockers: list<string>}
     */
    private function branch(int $companyId, int $branchId, FinancialYear $from, FinancialYear $to): array
    {
        $branchName = DB::table('branches')->where('id', $branchId)->value('name');
        $stored = $this->figures->stored($companyId, $branchId, $from);
        $net = $this->figures->net($companyId, $branchId, $from->start_date->toDateString(), $from->end_date->toDateString());
        $codes = $stored->keys()->merge($net->keys())->unique()->values();
        $closing = $codes->mapWithKeys(fn (string $code): array => [$code => round((float) ($stored[$code] ?? 0) + (float) ($net[$code] ?? 0), 2)]);

        $blockers = [];
        $warnings = [];
        $retained = $this->retainedEarnings($companyId, $branchId);

        if ($retained === null) {
            $blockers[] = 'Branch '.($branchName ?? $branchId).': map the "Profit And Loss" (retained earnings) account in Link Accounts before rolling the year over.';
        }

        $netProfitAndLoss = round((float) $closing
            ->filter(fn (float $amount, string $code): bool => in_array(AccountClass::of($code)['digit'] ?? 0, [4, 5, 6], true))
            ->sum(), 2);

        $accounts = ChartOfAccount::query()->where('company_id', $companyId)->where('branch_id', $branchId)->whereIn('code', $codes->all())->get()->keyBy('code');
        $existing = AccountBalance::query()->where('company_id', $companyId)->where('branch_id', $branchId)->where('financial_id', $to->id)->get()->keyBy('coa_id');
        $rows = [];

        foreach ($closing as $code => $balance) {
            $code = (string) $code;
            $digit = AccountClass::of($code)['digit'] ?? 0;
            $account = $accounts->get($code);

            if ($account === null) {
                if (abs($balance) > 0.004) {
                    $warnings[] = "Account {$code} has a balance of {$balance} but is not in this branch's chart of accounts; it is not carried over.";
                }

                continue;
            }

            if ($digit === 0) {
                $warnings[] = "Account {$code} is outside the six account classes; its closing balance is carried over as it stands.";
            }

            if (in_array($digit, [4, 5, 6], true)) {
                $signed = 0.0;
            } else {
                $signed = $balance;

                if ($retained !== null && (int) $retained['coa_id'] === (int) $account->id) {
                    $signed = round($balance + $netProfitAndLoss, 2);
                }
            }

            $rows[$account->id] = $this->row($account, $signed, $existing->get($account->id));
        }

        if ($retained !== null && ! isset($rows[$retained['coa_id']])) {
            $account = ChartOfAccount::query()->find($retained['coa_id']);
            $rows[$account->id] = $this->row($account, $netProfitAndLoss, $existing->get($account->id));
        }

        $rows = collect($rows)->filter(fn (array $row): bool => $row['opening'] != 0.0 || $row['current'] !== null)->sortBy('code')->values()->all();
        $difference = round((float) collect($rows)->sum(fn (array $row): float => $row['nature'] === 'cr' ? -$row['opening'] : $row['opening']), 2);

        if ($difference != 0.0) {
            $warnings[] = "The opening balances of this branch are out of balance by {$difference}: the closing year's books were already unbalanced.";
        }

        return [
            'branch_id' => $branchId,
            'branch_name' => $branchName,
            'retained_earnings' => $retained === null ? null : ['code' => $retained['code'], 'name' => $retained['name']],
            'net_profit' => round(-$netProfitAndLoss, 2),
            'rows' => $rows,
            'difference' => $difference,
            'warnings' => $warnings,
            'blockers' => $blockers,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ChartOfAccount $account, float $signed, ?AccountBalance $existing): array
    {
        return [
            'coa_id' => (int) $account->id,
            'code' => (string) $account->code,
            'name' => (string) $account->name,
            'class' => AccountClass::label((string) $account->code),
            'opening' => round(abs($signed), 2),
            'nature' => $signed < 0 ? 'cr' : 'dr',
            'current' => $existing === null ? null : ['opening' => (float) $existing->opening_balance, 'nature' => (string) $existing->acc_nature],
        ];
    }

    /**
     * @return array{coa_id: int, code: string, name: string}|null
     */
    private function retainedEarnings(int $companyId, int $branchId): ?array
    {
        $value = ChartOfAccountMapping::query()->where('company_id', $companyId)->where('branch_id', $branchId)
            ->where('key', self::RETAINED_EARNINGS_KEY)->whereNotNull('value')->where('value', '!=', '')->value('value');

        $account = $value === null ? null : ChartOfAccount::query()->where('company_id', $companyId)->where('branch_id', $branchId)->find((int) $value);

        return $account === null ? null : ['coa_id' => (int) $account->id, 'code' => (string) $account->code, 'name' => (string) $account->name];
    }
}
