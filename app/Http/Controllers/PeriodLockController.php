<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PeriodLockEntry;
use App\Services\PeriodLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The period lock of a company: its current state and history, locking or moving it forward (Accountant), moving it back or
 * clearing it and the dating rules (administrators only). The history is append-only and every change is also audited.
 */
class PeriodLockController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abortUnlessMenuPermission('/period-lock');
        $companyId = $this->companyId($request);
        $service = app(PeriodLock::class);

        $history = PeriodLockEntry::query()
            ->with('lockedBy:id,first_name,last_name')
            ->where('company_id', $companyId)->whereNull('branch_id')
            ->orderByDesc('id')->limit(30)->get();

        $current = $history->first();

        return response()->json([
            'locked_through' => $service->lockedThrough($companyId),
            'reason' => $current?->locked_through === null ? null : $current->reason,
            'locked_by' => $current?->locked_through === null ? null : $current?->lockedBy?->full_name,
            'locked_at' => $current?->locked_through === null ? null : $current?->created_at?->toDateTimeString(),
            'history' => $history->map(fn (PeriodLockEntry $entry): array => [
                'id' => $entry->id,
                'action' => $entry->action,
                'locked_through' => $entry->locked_through?->toDateString(),
                'reason' => $entry->reason,
                'by' => $entry->lockedBy?->full_name,
                'at' => $entry->created_at?->toDateTimeString(),
            ])->all(),
            'rules' => $service->dateRules($companyId),
            'can' => [
                'add' => hasMenuPermission('/period-lock/add'),
                'reopen' => hasMenuPermission('/period-lock/reopen'),
                'override' => hasMenuPermission(PeriodLock::OVERRIDE_MENU),
                'rollover' => hasMenuPermission('/period-lock/rollover'),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abortUnlessMenuPermission('/period-lock');
        $companyId = $this->companyId($request);
        $data = $request->validate([
            'locked_through' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $current = app(PeriodLock::class)->lockedThrough($companyId);
        $new = $data['locked_through'] ?? null;

        if ($new === $current) {
            throw ValidationException::withMessages(['locked_through' => ['The books are already locked through this date.']]);
        }

        $moveBack = $new === null || ($current !== null && $new < $current);
        $action = match (true) {
            $new === null => PeriodLockEntry::ACTION_CLEAR,
            $moveBack => PeriodLockEntry::ACTION_REOPEN,
            $current === null => PeriodLockEntry::ACTION_LOCK,
            default => PeriodLockEntry::ACTION_ADVANCE,
        };

        if ($moveBack) {
            abortUnlessMenuPermission('/period-lock/reopen');
        } else {
            abortUnlessMenuPermission('/period-lock/add');
        }

        $entry = DB::transaction(function () use ($companyId, $new, $action, $data, $current, $request): PeriodLockEntry {
            $entry = PeriodLockEntry::query()->create([
                'company_id' => $companyId, 'locked_through' => $new, 'action' => $action,
                'reason' => $data['reason'], 'locked_by' => $request->user()?->id,
            ]);

            AuditLog::record($entry, 'period_'.$action, ['locked_through' => $current], ['locked_through' => $new, 'reason' => $data['reason']]);

            return $entry;
        });

        return response()->json(['message' => 'Period lock saved', 'action' => $action, 'locked_through' => $entry->locked_through?->toDateString()]);
    }

    public function updateRules(Request $request): JsonResponse
    {
        abortUnlessMenuPermission('/period-lock/reopen');
        $companyId = $this->companyId($request);
        $data = $request->validate([
            'max_backdate_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'allow_future_dates' => ['nullable', 'boolean'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $old = app(PeriodLock::class)->dateRules($companyId);
        $updated = DB::table('company_settings')->where('company_id', $companyId)->update([
            'max_backdate_days' => $data['max_backdate_days'] ?? null,
            'allow_future_dates' => array_key_exists('allow_future_dates', $data) && $data['allow_future_dates'] !== null ? (bool) $data['allow_future_dates'] : null,
            'updated_at' => now(),
        ]);

        if ($updated === 0) {
            throw ValidationException::withMessages(['company_id' => ['Save the company settings once before setting dating rules.']]);
        }

        $entry = PeriodLockEntry::query()->where('company_id', $companyId)->latest('id')->first();

        $rules = app(PeriodLock::class)->dateRules($companyId);

        AuditLog::query()->create([
            'company_id' => $companyId, 'branch_id' => null, 'user_id' => $request->user()?->id, 'event' => 'period_rules_changed',
            'auditable_type' => PeriodLockEntry::class, 'auditable_id' => $entry?->id ?? 0,
            'old_values' => $old, 'new_values' => $rules + ['reason' => $data['reason']],
            'ip_address' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 255) ?: null,
        ]);

        return response()->json(['message' => 'Dating rules saved', 'rules' => $rules]);
    }

    private function companyId(Request $request): int
    {
        $user = $request->user();
        $companyId = $user?->hasRole('superadmin') ? (int) $request->input('company_id') : (int) $user?->company_id;

        if ($companyId <= 0) {
            throw ValidationException::withMessages(['company_id' => ['Choose a company.']]);
        }

        return $companyId;
    }
}
