<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\FinancialYear;
use App\Models\YearClosing;
use App\Services\Reports\AccountFigures;
use App\Support\AccountClass;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes a financial year in one step on top of the rollover (YearRollover): it writes the next year's opening balances, records the
 * closing (the result carried to retained earnings and the accounts it was made of: the closing statement), and makes the new year
 * the active one.
 *
 * It deliberately posts no closing entries to the ledger. The statements take a year's profit from its date range and the rollover
 * puts that profit into retained earnings through the new year's opening balances; a closing voucher dated in the old year would
 * zero the revenue and expense accounts of a year that must still report them, and would count the profit a second time. The closing
 * statement is therefore a record with the same lines such a voucher would have (revenue and cost accounts brought to nil, the
 * result taken to retained earnings), kept for the books and printable, not a posting.
 */
class YearClose
{
    public function __construct(private readonly YearRollover $rollover, private readonly AccountFigures $figures) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(int $companyId, int $fromYearId, int $toYearId): array
    {
        $preview = $this->rollover->preview($companyId, $fromYearId, $toYearId);
        $preview['statement'] = $this->statement($companyId, $preview);
        $preview['closed_before'] = YearClosing::query()->where('company_id', $companyId)->where('from_year_id', $fromYearId)->exists();

        return $preview;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function close(int $companyId, int $fromYearId, int $toYearId): array
    {
        return DB::transaction(function () use ($companyId, $fromYearId, $toYearId): array {
            $result = $this->rollover->run($companyId, $fromYearId, $toYearId);
            $statement = $this->statement($companyId, $result);

            $closing = YearClosing::query()->updateOrCreate(
                ['company_id' => $companyId, 'from_year_id' => $fromYearId],
                [
                    'to_year_id' => $toYearId, 'closed_on' => now()->toDateString(), 'net_result' => $statement['net_result'],
                    'summary' => $statement + ['accounts_carried' => $result['accounts']], 'closed_by' => Auth::id(),
                ],
            );

            FinancialYear::query()->where('company_id', $companyId)->update(['status' => false]);
            FinancialYear::query()->where('company_id', $companyId)->whereKey($toYearId)->update(['status' => true]);

            AuditLog::query()->create([
                'company_id' => $companyId, 'branch_id' => null, 'user_id' => Auth::id(), 'event' => 'year_close',
                'auditable_type' => FinancialYear::class, 'auditable_id' => $fromYearId, 'old_values' => null,
                'new_values' => ['to_year_id' => $toYearId, 'net_result' => $statement['net_result'], 'activated' => $toYearId],
                'ip_address' => request()->ip(), 'user_agent' => substr((string) request()->userAgent(), 0, 255) ?: null,
            ]);

            return ['closing' => $closing, 'rollover' => $result, 'statement' => $statement];
        });
    }

    /**
     * What a closing voucher would hold: per branch the revenue / cost accounts of the year with their balance, and the result taken
     * to the retained earnings account.
     *
     * @param  array<string, mixed>  $preview
     * @return array{net_result: float, branches: list<array<string, mixed>>}
     */
    private function statement(int $companyId, array $preview): array
    {
        $from = FinancialYear::query()->where('company_id', $companyId)->find($preview['from']['id']);
        $branches = [];
        $total = 0.0;

        foreach ($preview['branches'] as $branch) {
            $stored = $this->figures->stored($companyId, (int) $branch['branch_id'], $from);
            $net = $this->figures->net($companyId, (int) $branch['branch_id'], $from->start_date->toDateString(), $from->end_date->toDateString());
            $codes = $stored->keys()->merge($net->keys())->unique();

            $balances = $codes->mapWithKeys(fn (string $code): array => [$code => round((float) ($stored[$code] ?? 0) + (float) ($net[$code] ?? 0), 2)])
                ->filter(fn (float $amount, string $code): bool => in_array(AccountClass::of($code)['digit'] ?? 0, [4, 5, 6], true) && abs($amount) > 0.004);

            $names = ChartOfAccount::query()->where('company_id', $companyId)->where('branch_id', $branch['branch_id'])->whereIn('code', $balances->keys()->all())->pluck('name', 'code');

            $lines = $balances->map(fn (float $amount, string $code): array => [
                'code' => $code, 'name' => (string) ($names[$code] ?? ''), 'balance' => $amount,
                // the closing entry reverses each balance: a credit balance (revenue) is debited and the other way round
                'closing_side' => $amount < 0 ? 'dr' : 'cr', 'closing_amount' => round(abs($amount), 2),
            ])->values()->all();

            $branches[] = [
                'branch_id' => $branch['branch_id'], 'branch_name' => $branch['branch_name'], 'retained_earnings' => $branch['retained_earnings'],
                'net_result' => $branch['net_profit'], 'lines' => $lines,
            ];
            $total += (float) $branch['net_profit'];
        }

        return ['net_result' => round($total, 2), 'branches' => $branches];
    }
}
