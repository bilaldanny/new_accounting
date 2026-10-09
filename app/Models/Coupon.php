<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * A discount code applicable to a subscription invoice — global, superadmin-owned master data like
 * {@see SubscriptionPlan}, not company-scoped.
 */
class Coupon extends Model
{
    use SoftDeletes;

    public const TYPES = ['percent', 'fixed'];

    /**
     * @var list<string>
     */
    public const SORTABLE = ['code', 'type', 'value', 'is_active', 'created_at'];

    protected $fillable = [
        'code',
        'type',
        'value',
        'max_redemptions',
        'redemptions_count',
        'valid_from',
        'valid_until',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'valid_from' => 'date:Y-m-d',
            'valid_until' => 'date:Y-m-d',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Whether this coupon can be applied right now: active, within its date window (if any), and under
     * its redemption cap (if any).
     */
    public function isRedeemable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $today = Carbon::today();

        if ($this->valid_from !== null && $today->lt($this->valid_from)) {
            return false;
        }

        if ($this->valid_until !== null && $today->gt($this->valid_until)) {
            return false;
        }

        if ($this->max_redemptions !== null && $this->redemptions_count >= $this->max_redemptions) {
            return false;
        }

        return true;
    }

    /**
     * The discount this coupon takes off $amount, never more than the amount itself.
     */
    public function discountFor(float $amount): float
    {
        $discount = $this->type === 'percent'
            ? $amount * ((float) $this->value / 100)
            : (float) $this->value;

        return round(min($discount, $amount), 2);
    }

    public static function findRedeemableByCode(string $code): ?self
    {
        $coupon = self::query()->where('code', $code)->first();

        return $coupon !== null && $coupon->isRedeemable() ? $coupon : null;
    }

    /**
     * @throws ValidationException
     */
    public static function assertRedeemable(string $code): self
    {
        $coupon = self::findRedeemableByCode($code);

        if ($coupon === null) {
            throw ValidationException::withMessages([
                'coupon_code' => ['This coupon code is invalid or no longer redeemable.'],
            ]);
        }

        return $coupon;
    }

    public function redeem(): void
    {
        $this->increment('redemptions_count');
    }

    public static function createCoupon(object $request): self
    {
        $coupon = new self;
        self::fillFromRequest($coupon, $request);
        $coupon->save();

        return $coupon;
    }

    public static function updateCoupon(object $request, int|string $id): self
    {
        $coupon = self::query()->findOrFail($id);
        self::fillFromRequest($coupon, $request);
        $coupon->save();

        return $coupon;
    }

    public static function deleteCoupon(int|string $id): void
    {
        self::query()->find($id)?->delete();
    }

    private static function fillFromRequest(self $coupon, object $request): void
    {
        $coupon->code = strtoupper(trim((string) $request->code));
        $coupon->type = $request->type;
        $coupon->value = round((float) $request->value, 2);
        $coupon->max_redemptions = $request->filled('max_redemptions') ? (int) $request->max_redemptions : null;
        $coupon->valid_from = $request->valid_from ?: null;
        $coupon->valid_until = $request->valid_until ?: null;
        $coupon->is_active = $request->has('is_active') ? (bool) $request->boolean('is_active') : true;
    }
}
