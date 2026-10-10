<?php

namespace App\Services;

use App\Exceptions\PeriodLockedException;
use App\Models\AuditLog;
use App\Models\PeriodLockEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The one rule of the period lock, asked by every ledger-writing model (see Concerns\GuardsLockedPeriods).
 *
 * - A company is locked "through" a date: a document dated on or before it cannot be created, changed, deleted or restored, and
 *   nothing can be moved into or out of that range. The lock is a company's latest `period_locks` row; with no row (or a row with
 *   no date) nothing is locked and every write behaves exactly as it did before the lock existed.
 * - Two optional per-company rules apply to a new document or a changed date: `max_backdate_days` (not older than that many days)
 *   and `allow_future_dates` (false: not dated after today). NULL means no restriction.
 * - An administrator (superadmin, or a role holding the `/period-lock/override` menu) may send one request through with the
 *   `X-Period-Lock-Override` header holding a reason. It lets that request's writes pass, never opens the lock, and each write it
 *   lets through is written to the audit log with the reason.
 */
class PeriodLock
{
    public const OVERRIDE_HEADER = 'X-Period-Lock-Override';

    public const OVERRIDE_MENU = '/period-lock/override';

    private static ?bool $tableExists = null;

    private static ?bool $settingColumnsExist = null;

    /**
     * The date (Y-m-d) the company is locked through, or null when its books are open.
     */
    public function lockedThrough(?int $companyId, ?int $branchId = null): ?string
    {
        if ($companyId === null || ! $this->available()) {
            return null;
        }

        $latest = PeriodLockEntry::query()
            ->where('company_id', $companyId)
            ->where(fn ($query) => $query->whereNull('branch_id')->when($branchId !== null, fn ($q) => $q->orWhere('branch_id', $branchId)))
            ->orderByDesc('id')
            ->get(['branch_id', 'locked_through'])
            ->unique(fn (PeriodLockEntry $entry): string => (string) $entry->branch_id);

        $dates = $latest->map(fn (PeriodLockEntry $entry): ?string => $entry->locked_through?->toDateString())->filter()->values();

        return $dates->isEmpty() ? null : (string) $dates->max();
    }

    /**
     * @return array{max_backdate_days: int|null, allow_future_dates: bool|null}
     */
    public function dateRules(?int $companyId): array
    {
        if ($companyId === null || ! $this->settingColumnsExist()) {
            return ['max_backdate_days' => null, 'allow_future_dates' => null];
        }

        $row = DB::table('company_settings')->where('company_id', $companyId)->first(['max_backdate_days', 'allow_future_dates']);

        return [
            'max_backdate_days' => $row?->max_backdate_days === null ? null : (int) $row->max_backdate_days,
            'allow_future_dates' => $row?->allow_future_dates === null ? null : (bool) $row->allow_future_dates,
        ];
    }

    /**
     * Refuses the write when it touches a locked period, or breaks the back-dating / future-dating rule, unless the request carries
     * a valid override.
     *
     * @param  iterable<string|null>  $lockDates  every date the write touches (the old one and the new one)
     * @param  iterable<string|null>  $freshDates  the new date of a new document or of a changed date (the dating rules apply to it)
     * @param  Model|string|null  $subject  what is written, for the audit log
     *
     * @throws PeriodLockedException
     */
    public function check(?int $companyId, ?int $branchId, iterable $lockDates, iterable $freshDates, Model|string|null $subject = null): void
    {
        if ($companyId === null) {
            return;
        }

        $problem = $this->problem($companyId, $branchId, $lockDates, $freshDates);

        if ($problem === null) {
            return;
        }

        $reason = $this->overrideReason();

        if ($reason !== null) {
            $this->audit($companyId, $branchId, $subject, $problem['message'], $reason, $problem['locked_through']);

            return;
        }

        throw new PeriodLockedException($problem['message'], $problem['locked_through'], $this->canOverride());
    }

    /**
     * The reason this day cannot be written to (locked, or breaks a dating rule), or null when it can - also null when the request
     * carries a valid override. For callers that must stop gracefully instead of failing a write (the depreciation run).
     */
    public function blocks(?int $companyId, ?int $branchId, string $date): ?string
    {
        if ($companyId === null || $this->overrideReason() !== null) {
            return null;
        }

        return $this->problem($companyId, $branchId, [$date], [])['message'] ?? null;
    }

    /**
     * The reason sent with a valid override, or null: no header, no reason, or a user who may not override.
     */
    public function overrideReason(): ?string
    {
        $header = request()->header(self::OVERRIDE_HEADER);
        $reason = is_string($header) ? trim($header) : '';

        if (mb_strlen($reason) < 3 || ! $this->canOverride()) {
            return null;
        }

        return mb_substr($reason, 0, 500);
    }

    public function canOverride(): bool
    {
        return Auth::check() && hasMenuPermission(self::OVERRIDE_MENU);
    }

    /**
     * @param  iterable<string|null>  $lockDates
     * @param  iterable<string|null>  $freshDates
     * @return array{message: string, locked_through: string|null}|null
     */
    private function problem(int $companyId, ?int $branchId, iterable $lockDates, iterable $freshDates): ?array
    {
        $through = $this->lockedThrough($companyId, $branchId);

        if ($through !== null) {
            foreach ($lockDates as $date) {
                $day = $this->day($date);

                if ($day !== null && $day <= $through) {
                    return [
                        'message' => "The books are locked through {$through}: a document dated {$day} cannot be created, changed, deleted or restored."
                            .($this->canOverride() ? ' Send the change with an override reason to save it once.' : ' Ask an administrator.'),
                        'locked_through' => $through,
                    ];
                }
            }
        }

        $rules = $this->dateRules($companyId);

        foreach ($freshDates as $date) {
            $day = $this->day($date);

            if ($day === null) {
                continue;
            }

            $backdateDays = $rules['max_backdate_days'];

            if ($backdateDays !== null && $day < now()->subDays($backdateDays)->toDateString()) {
                $earliest = now()->subDays($backdateDays)->toDateString();

                return ['message' => "A document cannot be dated more than {$backdateDays} days back (earliest {$earliest}).", 'locked_through' => $through];
            }

            if ($rules['allow_future_dates'] === false && $day > now()->toDateString()) {
                return ['message' => 'A document cannot be dated in the future.', 'locked_through' => $through];
            }
        }

        return null;
    }

    private function day(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse(str_replace('/', '-', (string) $value))->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function audit(int $companyId, ?int $branchId, Model|string|null $subject, string $problem, string $reason, ?string $through): void
    {
        $type = $subject instanceof Model ? $subject::class : (is_string($subject) ? $subject : 'unknown');
        $id = $subject instanceof Model ? $subject->getKey() : null;
        $key = $type.'|'.($id ?? 'bulk').'|'.$reason;

        $seen = (array) request()->attributes->get('period_lock_audited', []);

        if (isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        request()->attributes->set('period_lock_audited', $seen);

        AuditLog::query()->create([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'user_id' => Auth::id(),
            'event' => 'period_override',
            'auditable_type' => $type,
            'auditable_id' => $id ?? 0,
            'old_values' => null,
            'new_values' => ['reason' => $reason, 'locked_through' => $through, 'overrode' => $problem],
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 255) ?: null,
        ]);
    }

    private function available(): bool
    {
        return self::$tableExists ??= Schema::hasTable('period_locks');
    }

    private function settingColumnsExist(): bool
    {
        return self::$settingColumnsExist ??= Schema::hasColumn('company_settings', 'max_backdate_days');
    }
}
