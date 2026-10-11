<?php

namespace App\Services;

use App\Exceptions\PeriodLockedException;
use App\Models\AuditLog;
use App\Models\InventoryCloseEntry;
use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * The inventory closing lock: stock documents dated on or before the closed date cannot be created, changed, deleted or
 * restored, so a stock count or valuation taken at period end stays what it was. It is separate from the books lock
 * (PeriodLock) and asks the same override: a user holding `/period-lock/override` may send one save through with the
 * `X-Period-Lock-Override` reason. With no close recorded nothing is checked and every write behaves as before.
 *
 * Stock documents are purchases, receiving notes, purchase returns, sales (not drafts or quotations), sale returns,
 * issue notes, stock transfers and stock adjustments.
 */
class InventoryClose
{
    private static ?bool $tracked = null;

    public const STOCK_TYPES = [
        Transaction::TYPE_PURCHASE, Transaction::TYPE_RECEIVING_NOTE, Transaction::TYPE_PURCHASE_RETURN, Transaction::TYPE_SELL,
        Transaction::TYPE_SELL_RETURN, Transaction::TYPE_ISSUE_NOTE, Transaction::TYPE_TRANSFER, Transaction::TYPE_ADJUSTMENT,
    ];

    public static function supported(): bool
    {
        return self::$tracked ??= Schema::hasTable('inventory_closes');
    }

    public static function forgetSchemaMemo(): void
    {
        self::$tracked = null;
    }

    public function lockedThrough(?int $companyId, ?int $branchId = null): ?string
    {
        if ($companyId === null || ! self::supported()) {
            return null;
        }

        $latest = InventoryCloseEntry::query()
            ->where('company_id', $companyId)
            ->where(fn ($q) => $q->whereNull('branch_id')->when($branchId !== null, fn ($b) => $b->orWhere('branch_id', $branchId)))
            ->orderByDesc('id')
            ->get()
            ->unique(fn (InventoryCloseEntry $entry): string => (string) $entry->branch_id);

        $dates = $latest->map(fn (InventoryCloseEntry $entry): ?string => $entry->locked_through?->toDateString())->filter();

        return $dates->isEmpty() ? null : (string) $dates->max();
    }

    /**
     * @throws PeriodLockedException
     */
    public function guard(Transaction $transaction): void
    {
        if (! self::supported() || ! in_array($transaction->type, self::STOCK_TYPES, true)) {
            return;
        }

        $unposted = fn (?string $status): bool => $status === null || in_array($status, Transaction::UNPOSTED_SELL_STATUSES, true);

        if ($transaction->type === Transaction::TYPE_SELL && $unposted($transaction->status) && (! $transaction->exists || $unposted($transaction->getOriginal('status')))) {
            return;
        }

        $companyId = $transaction->company_id === null ? null : (int) $transaction->company_id;
        $branchId = $transaction->branch_id === null || $transaction->branch_id === '' ? null : (int) $transaction->branch_id;
        $original = $transaction->exists ? $transaction->getOriginal('transaction_date') : null;

        $this->guardDays($companyId, $branchId, array_filter([
            $transaction->transaction_date?->toDateString(),
            $original ? substr((string) $original, 0, 10) : null,
        ]), (string) $transaction->type, $transaction->getKey());
    }

    /**
     * The same check for rows read by a bulk query (the period lock's builder path): their company, branch, day and type.
     *
     * @param  list<string>  $days
     *
     * @throws PeriodLockedException
     */
    public function guardDays(?int $companyId, ?int $branchId, array $days, string $type, mixed $subjectId): void
    {
        if (! self::supported() || ! in_array($type, self::STOCK_TYPES, true)) {
            return;
        }

        $through = $this->lockedThrough($companyId, $branchId);

        if ($through === null) {
            return;
        }

        $touched = collect($days)->first(fn (string $day): bool => $day <= $through);

        if ($touched === null) {
            return;
        }

        $lock = app(PeriodLock::class);

        $reason = $lock->overrideReason();

        if ($reason !== null) {
            AuditLog::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'user_id' => Auth::id(),
                'event' => 'inventory_close_override',
                'auditable_type' => Transaction::class,
                'auditable_id' => (int) $subjectId,
                'old_values' => null,
                'new_values' => ['reason' => $reason, 'closed_through' => $through, 'document_date' => $touched, 'type' => $type],
                'ip_address' => request()->ip(),
                'user_agent' => substr((string) request()->userAgent(), 0, 255) ?: null,
            ]);

            return;
        }

        throw new PeriodLockedException(
            "Inventory is closed through {$through}: a stock document dated {$touched} cannot be created, changed, deleted or restored."
                .($lock->canOverride() ? ' Send the change with an override reason to save it once.' : ' Ask an administrator.'),
            $through,
            $lock->canOverride(),
        );
    }
}
