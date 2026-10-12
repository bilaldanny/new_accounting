<?php

namespace App\Services;

use App\Models\JournalSchedule;
use App\Models\JournalScheduleRun;
use App\Models\TAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Posts the vouchers of journal schedules (recurring and reversing journals, recurring expenses, prepaid / deferred amortization,
 * accruals). The rules are the depreciation engine's, so nothing here is new behaviour for the ledger:
 *
 *  - every voucher goes through AccountsVoucher: the company's journal (or expense) approval rule, the period lock and the voucher
 *    numbering apply, and a schedule set to "draft for approval" leaves its vouchers pending whatever the setting is;
 *  - each occurrence is recorded once (unique on schedule, day and kind), so running again, or twice in a day, never doubles up;
 *  - the first occurrence that falls in a locked period stops that schedule (no gap is jumped over); the reason is kept on the
 *    schedule and the run carries on from exactly there after a reopen;
 *  - a reversal is dated the first day of the month after its original, is linked both ways (`reversal_of_id` /
 *    `reversed_by_id`), is made only once the original is approved, and is approved with it.
 */
class JournalScheduleEngine
{
    public function __construct(private readonly AccountsVoucher $vouchers) {}

    public static function available(): bool
    {
        return Schema::hasTable('journal_schedules') && Schema::hasTable('journal_schedule_runs');
    }

    /**
     * Every occurrence day of a schedule up to and including `$upTo`, in order.
     *
     * @return list<string>
     */
    public function occurrences(JournalSchedule $schedule, string $upTo): array
    {
        $upTo = CarbonImmutable::parse($upTo)->startOfDay();
        $start = CarbonImmutable::parse($schedule->start_date->toDateString())->startOfDay();
        $end = $schedule->end_date === null ? null : CarbonImmutable::parse($schedule->end_date->toDateString())->startOfDay();
        $limit = $this->limit($schedule);
        $monthEnd = $start->isSameDay($start->endOfMonth());
        $days = [];

        for ($index = 0; $limit === null || $index < $limit; $index++) {
            $day = match (true) {
                in_array($schedule->kind, ['amortization', 'accrual'], true) => $start->startOfMonth()->addMonthsNoOverflow($index)->endOfMonth()->startOfDay(),
                $schedule->frequency === 'daily' => $start->addDays($index),
                $schedule->frequency === 'weekly' => $start->addWeeks($index),
                $schedule->frequency === 'yearly' => $start->addYearsNoOverflow($index),
                default => $monthEnd ? $start->startOfMonth()->addMonthsNoOverflow($index)->endOfMonth()->startOfDay() : $start->addMonthsNoOverflow($index),
            };

            if ($day > $upTo || ($end !== null && $day > $end)) {
                break;
            }

            $days[] = $day->toDateString();
        }

        return $days;
    }

    /**
     * What a run up to `$upTo` would post, without posting.
     *
     * @return list<array<string, mixed>>
     */
    public function preview(int $companyId, string $upTo, ?int $scheduleId = null): array
    {
        if (! self::available()) {
            return [];
        }

        $rows = [];

        foreach ($this->activeSchedules($companyId, $scheduleId) as $schedule) {
            $done = $schedule->runs()->where('kind', 'post')->pluck('period_date')->map(fn ($date): string => $date->toDateString())->all();

            foreach ($this->occurrences($schedule, $upTo) as $index => $day) {
                if (in_array($day, $done, true)) {
                    continue;
                }

                $rows[] = [
                    'schedule_id' => (int) $schedule->id, 'name' => $schedule->name, 'kind' => $schedule->kind, 'date' => $day,
                    'amount' => $this->amountFor($schedule, $index), 'auto_post' => (bool) $schedule->auto_post,
                ];
            }
        }

        usort($rows, fn (array $a, array $b): int => [$a['date'], $a['schedule_id']] <=> [$b['date'], $b['schedule_id']]);

        return $rows;
    }

    /**
     * @return array{posted: int, reversed: int, total: float, vouchers: list<int>, blocked: list<array{schedule_id: int, name: string, date: string, reason: string}>}
     */
    public function run(int $companyId, string $upTo, ?int $scheduleId = null): array
    {
        $result = ['posted' => 0, 'reversed' => 0, 'total' => 0.0, 'vouchers' => [], 'blocked' => []];

        if (! self::available()) {
            return $result;
        }

        foreach ($this->activeSchedules($companyId, $scheduleId) as $schedule) {
            $blocked = null;
            $done = $schedule->runs()->where('kind', 'post')->pluck('period_date')->map(fn ($date): string => $date->toDateString())->all();

            foreach ($this->occurrences($schedule, $upTo) as $index => $day) {
                if (in_array($day, $done, true)) {
                    continue;
                }

                $reason = app(PeriodLock::class)->blocks($companyId, $this->branchOf($schedule), $day);

                if ($reason !== null) {
                    $blocked = ['schedule_id' => (int) $schedule->id, 'name' => $schedule->name, 'date' => $day, 'reason' => $reason];

                    break;
                }

                $amount = $this->amountFor($schedule, $index);
                $voucher = DB::transaction(function () use ($schedule, $day, $index, $amount): TAccount {
                    $voucher = $this->post($schedule, $day, $index);

                    JournalScheduleRun::query()->create([
                        'company_id' => $schedule->company_id, 'journal_schedule_id' => $schedule->id, 'period_date' => $day, 'kind' => 'post',
                        't_account_id' => $voucher->id, 'amount' => $amount, 'state' => $voucher->status,
                    ]);

                    return $voucher;
                });

                $result['posted']++;
                $result['total'] += $amount;
                $result['vouchers'][] = (int) $voucher->id;
            }

            $schedule->update(['blocked_reason' => $blocked === null ? null : $blocked['reason']]);

            if ($blocked !== null) {
                $result['blocked'][] = $blocked;
            }

            if ($this->isFinished($schedule, $upTo)) {
                $schedule->update(['status' => 'completed']);
            }
        }

        $this->reversals($companyId, $upTo, $scheduleId, $result);
        $result['total'] = round($result['total'], 2);

        return $result;
    }

    /**
     * The amount of the occurrence at `$index`: a fixed amount for most kinds, a straight-line part for amortization (the last
     * part takes the rounding).
     */
    public function amountFor(JournalSchedule $schedule, int $index): float
    {
        $meta = (array) $schedule->meta;

        if ($schedule->kind === 'amortization') {
            $months = max(1, (int) ($meta['months'] ?? 1));
            $total = round((float) ($meta['total_amount'] ?? 0), 2);
            $part = round($total / $months, 2);

            return $index === $months - 1 ? round($total - $part * ($months - 1), 2) : $part;
        }

        if (in_array($schedule->kind, ['accrual', 'expense'], true)) {
            return round((float) ($meta['amount'] ?? 0), 2);
        }

        return round((float) collect($schedule->lines ?? [])->sum('debit'), 2);
    }

    /**
     * @return Collection<int, JournalSchedule>
     */
    private function activeSchedules(int $companyId, ?int $scheduleId): Collection
    {
        return JournalSchedule::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->when($scheduleId !== null, fn ($query) => $query->whereKey($scheduleId))
            ->orderBy('id')
            ->get();
    }

    private function post(JournalSchedule $schedule, string $day, int $index): TAccount
    {
        $meta = (array) $schedule->meta;
        $amount = $this->amountFor($schedule, $index);
        $label = $schedule->name.' ('.$day.')';
        $voucherType = $schedule->kind === 'expense' ? 'EXP' : 'JV';

        $lines = match ($schedule->kind) {
            'amortization' => ($meta['direction'] ?? 'expense') === 'revenue'
                ? [
                    ['account_id' => (int) $meta['source_account_id'], 'debit' => $amount, 'cost_center_id' => $meta['cost_center_id'] ?? null, 'project_id' => $meta['project_id'] ?? null],
                    ['account_id' => (int) $meta['target_account_id'], 'credit' => $amount, 'cost_center_id' => $meta['cost_center_id'] ?? null, 'project_id' => $meta['project_id'] ?? null],
                ]
                : [
                    ['account_id' => (int) $meta['target_account_id'], 'debit' => $amount, 'cost_center_id' => $meta['cost_center_id'] ?? null, 'project_id' => $meta['project_id'] ?? null],
                    ['account_id' => (int) $meta['source_account_id'], 'credit' => $amount, 'cost_center_id' => $meta['cost_center_id'] ?? null, 'project_id' => $meta['project_id'] ?? null],
                ],
            'accrual' => [
                ['account_id' => (int) $meta['expense_account_id'], 'debit' => $amount, 'cost_center_id' => $meta['cost_center_id'] ?? null, 'project_id' => $meta['project_id'] ?? null],
                ['account_id' => (int) $meta['liability_account_id'], 'credit' => $amount, 'contact_id' => $meta['payee_contact_id'] ?? null],
            ],
            'expense' => [
                ['account_id' => (int) $meta['expense_account_id'], 'debit' => $amount, 'contact_id' => $meta['payee_contact_id'] ?? null, 'cost_center_id' => $meta['cost_center_id'] ?? null, 'project_id' => $meta['project_id'] ?? null],
                ['account_id' => (int) $meta['paid_from_account_id'], 'credit' => $amount, 'contact_id' => $meta['payee_contact_id'] ?? null],
            ],
            default => collect($schedule->lines ?? [])->map(fn (array $line): array => [
                'account_id' => (int) $line['account_id'], 'debit' => (float) ($line['debit'] ?? 0), 'credit' => (float) ($line['credit'] ?? 0),
                'contact_id' => $line['contact_id'] ?? null, 'cost_center_id' => $line['cost_center_id'] ?? null, 'project_id' => $line['project_id'] ?? null,
                'description' => $line['description'] ?? $label,
            ])->all(),
        };

        $voucher = $this->vouchers->post((int) $schedule->company_id, $this->branchOf($schedule), $day, $lines, $label, 'SCH-'.$schedule->id, $voucherType);

        if (! $schedule->auto_post && $voucher->status === TAccount::STATUS_APPROVED) {
            $voucher->forceFill(['status' => TAccount::STATUS_PENDING, 'approved_by' => null, 'approved_at' => null])->save();
        }

        return $voucher;
    }

    /**
     * Makes the reversal of every posted occurrence of an auto-reversing schedule whose reversal day has come.
     *
     * @param  array{posted: int, reversed: int, total: float, vouchers: list<int>, blocked: list<array<string, mixed>>}  $result
     */
    private function reversals(int $companyId, string $upTo, ?int $scheduleId, array &$result): void
    {
        $reversing = JournalSchedule::query()
            ->where('company_id', $companyId)
            ->where('auto_reverse', true)
            ->whereIn('status', ['active', 'completed'])
            ->when($scheduleId !== null, fn ($query) => $query->whereKey($scheduleId))
            ->pluck('id');

        if ($reversing->isEmpty() || ! Schema::hasColumn('t_accounts', 'reversal_of_id')) {
            return;
        }

        $posted = JournalScheduleRun::query()->whereIn('journal_schedule_id', $reversing)->where('kind', 'post')->orderBy('period_date')->get();
        $reversed = JournalScheduleRun::query()->whereIn('journal_schedule_id', $reversing)->where('kind', 'reversal')->get()
            ->map(fn (JournalScheduleRun $run): string => $run->journal_schedule_id.'|'.$run->period_date->toDateString())->all();

        foreach ($posted as $run) {
            $key = $run->journal_schedule_id.'|'.$run->period_date->toDateString();

            if (in_array($key, $reversed, true)) {
                continue;
            }

            $day = CarbonImmutable::parse($run->period_date->toDateString())->addMonthNoOverflow()->startOfMonth()->toDateString();

            if ($day > $upTo) {
                continue;
            }

            $original = $run->t_account_id === null ? null : TAccount::query()->with('details')->find($run->t_account_id);
            $schedule = JournalSchedule::query()->find($run->journal_schedule_id);

            if ($original === null || $original->status === TAccount::STATUS_REJECTED) {
                $this->recordReversal($run, $day, null, 'skipped');

                continue;
            }

            if ($original->status !== TAccount::STATUS_APPROVED) {
                continue;
            }

            if (app(PeriodLock::class)->blocks($companyId, (int) $original->branch_id, $day) !== null) {
                continue;
            }

            $lines = $original->details->map(fn ($line): array => [
                'account_id' => (int) $line->coa_id, 'debit' => (float) $line->credit, 'credit' => (float) $line->debit,
                'contact_id' => $line->contact_id, 'cost_center_id' => $line->cost_center_id,
                'description' => 'Reversal of '.$original->voucher_no,
            ])->all();

            DB::transaction(function () use ($original, $day, $lines, $run, $schedule, &$result, $companyId): void {
                $voucher = $this->vouchers->post(
                    $companyId, (int) $original->branch_id, $day, $lines, 'Reversal of '.$original->voucher_no.($schedule ? ' ('.$schedule->name.')' : ''),
                    'REV-'.$original->voucher_no,
                    $schedule?->kind === 'expense' ? 'EXP' : 'JV',
                );

                if ($voucher->status !== TAccount::STATUS_APPROVED) {
                    $voucher->forceFill(['status' => TAccount::STATUS_APPROVED, 'approved_by' => auth()->id(), 'approved_at' => now()])->save();
                }

                $voucher->forceFill(['reversal_of_id' => $original->id])->save();
                $original->forceFill(['reversed_by_id' => $voucher->id])->save();
                $this->recordReversal($run, $day, $voucher, 'posted');
                $result['reversed']++;
                $result['vouchers'][] = (int) $voucher->id;
            });
        }
    }

    private function recordReversal(JournalScheduleRun $original, string $day, ?TAccount $voucher, string $state): void
    {
        JournalScheduleRun::query()->create([
            'company_id' => $original->company_id, 'journal_schedule_id' => $original->journal_schedule_id,
            'period_date' => $original->period_date->toDateString(), 'kind' => 'reversal', 't_account_id' => $voucher?->id,
            'amount' => $original->amount, 'state' => $state,
        ]);
    }

    private function limit(JournalSchedule $schedule): ?int
    {
        $meta = (array) $schedule->meta;

        if ($schedule->kind === 'amortization') {
            return max(1, (int) ($meta['months'] ?? 1));
        }

        return $schedule->max_runs === null ? null : (int) $schedule->max_runs;
    }

    private function isFinished(JournalSchedule $schedule, string $upTo): bool
    {
        $limit = $this->limit($schedule);
        $end = $schedule->end_date?->toDateString();

        if ($limit !== null && $schedule->runs()->where('kind', 'post')->count() >= $limit) {
            return true;
        }

        return $end !== null && $end <= $upTo && count($this->occurrences($schedule, $end)) === $schedule->runs()->where('kind', 'post')->count();
    }

    private function branchOf(JournalSchedule $schedule): ?int
    {
        return $schedule->branch_id === null ? null : (int) $schedule->branch_id;
    }
}
