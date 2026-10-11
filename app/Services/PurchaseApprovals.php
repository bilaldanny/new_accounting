<?php

namespace App\Services;

use App\Models\PurchaseApprovalRule;
use App\Models\PurchaseApprovalStep;
use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Rule-based, multi-level approval of a purchase. The rule that matches the purchase total (narrowest range, then priority)
 * lists the levels; each approve call is one level, by a user of that level's role, and never by the person who created the
 * purchase when the rule says so or by someone who already approved a lower level. The purchase becomes approved only when
 * the last level is done. A purchase no rule matches keeps the single step: the first approve approves it.
 */
class PurchaseApprovals
{
    private static ?bool $tracked = null;

    public static function supported(): bool
    {
        return self::$tracked ??= Schema::hasTable('purchase_approval_rules');
    }

    public static function forgetSchemaMemo(): void
    {
        self::$tracked = null;
    }

    public function ruleFor(Transaction $purchase): ?PurchaseApprovalRule
    {
        if (! self::supported() || $purchase->company_id === null) {
            return null;
        }

        $total = round((float) $purchase->final_amount, 2);

        return PurchaseApprovalRule::query()
            ->where('company_id', $purchase->company_id)
            ->where('is_active', true)
            ->where('min_amount', '<=', $total)
            ->where(fn ($q) => $q->whereNull('max_amount')->orWhere('max_amount', '>=', $total))
            ->get()
            ->sortBy([
                fn (PurchaseApprovalRule $a, PurchaseApprovalRule $b): int => ($b->min_amount <=> $a->min_amount),
                fn (PurchaseApprovalRule $a, PurchaseApprovalRule $b): int => ($b->priority <=> $a->priority),
            ])
            ->first();
    }

    /**
     * @return array{rule: ?string, required: int, done: int, next_level: ?int, next_label: ?string, next_role_id: ?int, steps: list<array<string, mixed>>}
     */
    public function progress(Transaction $purchase): array
    {
        $rule = $this->ruleFor($purchase);
        $steps = self::supported() && $purchase->exists
            ? PurchaseApprovalStep::query()->where('transaction_id', $purchase->id)->orderBy('level')->get()
            : collect();
        $levels = array_values((array) ($rule?->levels ?? []));
        $done = $steps->count();
        $next = $levels[$done] ?? null;

        return [
            'rule' => $rule?->name,
            'required' => count($levels) ?: 1,
            'done' => $done,
            'next_level' => $rule === null || $next === null ? null : $done + 1,
            'next_label' => $next['label'] ?? null,
            'next_role_id' => isset($next['role_id']) ? (int) $next['role_id'] : null,
            'steps' => $steps->map(fn (PurchaseApprovalStep $step): array => [
                'level' => $step->level, 'approved_by' => $step->approved_by, 'role_id' => $step->role_id, 'note' => $step->note, 'at' => $step->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * Records the current user's approval of the next level. Returns whether that was the last one.
     *
     * @throws ValidationException
     */
    public function advance(Transaction $purchase, ?string $note = null): bool
    {
        $rule = $this->ruleFor($purchase);

        if ($rule === null) {
            return true;
        }

        $levels = array_values((array) $rule->levels);
        $progress = $this->progress($purchase);
        $user = Auth::user();
        $level = $progress['done'] + 1;

        if ($level > count($levels)) {
            return true;
        }

        if ($rule->forbid_creator && $user !== null && (int) $purchase->created_by === (int) $user->id) {
            throw ValidationException::withMessages(['status' => ['The person who created a purchase cannot approve it: another approver is needed.']]);
        }

        $roleId = isset($levels[$level - 1]['role_id']) ? (int) $levels[$level - 1]['role_id'] : null;

        if ($roleId !== null && (int) $user?->role_id !== $roleId && ! $user?->hasRole('superadmin')) {
            throw ValidationException::withMessages(['status' => ['Level '.$level.' ('.($levels[$level - 1]['label'] ?? 'approval').') is for another role.']]);
        }

        if (PurchaseApprovalStep::query()->where('transaction_id', $purchase->id)->where('approved_by', $user?->id)->exists()) {
            throw ValidationException::withMessages(['status' => ['You approved an earlier level of this purchase: the next level needs another approver.']]);
        }

        PurchaseApprovalStep::query()->create([
            'transaction_id' => $purchase->id, 'level' => $level, 'approved_by' => $user?->id, 'role_id' => $roleId, 'note' => $note,
        ]);

        return $level >= count($levels);
    }
}
