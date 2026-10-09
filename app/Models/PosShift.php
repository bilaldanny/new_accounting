<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Services\PosShiftReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * One cashier's session on one branch's cash drawer (Sales & POS Phase 2). Opening it records the float;
 * closing it records the cash counted against the cash the system expects (see PosShiftReport), and freezes
 * the report (the Z report) into `summary`. While open, the same report computed live is the X report.
 */
class PosShift extends Model
{
    use Auditable;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'opening_float' => 'float',
            'expected_cash' => 'float',
            'counted_cash' => 'float',
            'variance' => 'float',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'summary' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return HasMany<PosCashMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(PosCashMovement::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function scopeVisibleToCurrentUser(Builder $query): Builder
    {
        $user = Auth::user();

        if ($user?->hasRole('superadmin')) {
            return $query;
        }

        if (! $user?->company_id) {
            return $query->whereRaw('0 = 1');
        }

        $query->where('company_id', $user->company_id);

        if ($user->branch_id && ! $user->hasRole('companyadmin')) {
            $query->where('branch_id', $user->branch_id);
        }

        return $query;
    }

    /**
     * The signed-in user's open shift, if any.
     */
    public static function currentForUser(): ?self
    {
        return self::query()->where('user_id', Auth::id())->where('status', self::STATUS_OPEN)->latest('id')->first();
    }

    /**
     * @throws ValidationException
     */
    public static function openShift(?int $companyId, ?int $branchId, float $openingFloat, ?string $note): self
    {
        $user = Auth::user();
        $companyId ??= $user?->company_id ? (int) $user->company_id : null;
        $branchId ??= $user?->branch_id ? (int) $user->branch_id : null;

        if ($companyId === null || $branchId === null) {
            throw ValidationException::withMessages(['branch_id' => ['Company and branch are required.']]);
        }

        if (self::currentForUser() !== null) {
            throw ValidationException::withMessages(['shift' => ['You already have an open shift. Close it before opening another.']]);
        }

        if (self::query()->where('branch_id', $branchId)->where('status', self::STATUS_OPEN)->exists()) {
            throw ValidationException::withMessages(['shift' => ['Another shift is already open on this branch\'s drawer.']]);
        }

        return self::query()->create([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'user_id' => Auth::id(),
            'opening_float' => $openingFloat,
            'opened_at' => now(),
            'status' => self::STATUS_OPEN,
            'note' => $note,
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function addMovement(string $type, float $amount, string $reason): PosCashMovement
    {
        if (! $this->isOpen()) {
            throw ValidationException::withMessages(['shift' => ['The shift is closed.']]);
        }

        return $this->movements()->create(['type' => $type, 'amount' => $amount, 'reason' => $reason, 'user_id' => Auth::id()]);
    }

    /**
     * @throws ValidationException
     */
    public function closeShift(float $countedCash, ?string $note): self
    {
        if (! $this->isOpen()) {
            throw ValidationException::withMessages(['shift' => ['The shift is already closed.']]);
        }

        $report = app(PosShiftReport::class)->build($this, now());

        $this->forceFill([
            'status' => self::STATUS_CLOSED,
            'closed_at' => now(),
            'closed_by' => Auth::id(),
            'expected_cash' => $report['expected_cash'],
            'counted_cash' => $countedCash,
            'variance' => round($countedCash - $report['expected_cash'], 2),
            'summary' => $report,
            'note' => $note ?: $this->note,
        ])->save();

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function presentForIndex(): array
    {
        return [
            'id' => $this->id,
            'branch_name' => $this->branch?->name,
            'cashier' => $this->user?->full_name,
            'status' => $this->status,
            'opening_float' => $this->opening_float,
            'opened_at' => $this->opened_at?->format('Y-m-d H:i'),
            'closed_at' => $this->closed_at?->format('Y-m-d H:i'),
            'expected_cash' => $this->expected_cash,
            'counted_cash' => $this->counted_cash,
            'variance' => $this->variance,
        ];
    }
}
