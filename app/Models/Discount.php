<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * A discount rule for the Sell module: a percentage or a fixed amount off a sale, optionally behind a
 * coupon code, a minimum purchase, a cap (percentage only) and a validity window (both dates inclusive).
 *
 * Master data plus a pure calculation (`discountFor`) and a lookup (`apply`): no sale reads a discount
 * yet, so nothing is deducted from an invoice by this model.
 */
class Discount extends Model
{
    use SoftDeletes;

    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPE_FIXED = 'fixed';

    /**
     * @var list<string>
     */
    public const TYPES = [self::TYPE_PERCENTAGE, self::TYPE_FIXED];

    /**
     * Columns the list may be sorted by.
     *
     * @var list<string>
     */
    public const SORTABLE = ['name', 'code', 'discount_type', 'value', 'min_purchase_amount', 'starts_at', 'expires_at', 'is_active', 'created_at'];

    protected $fillable = [
        'company_id',
        'name',
        'code',
        'discount_type',
        'value',
        'min_purchase_amount',
        'max_discount_amount',
        'starts_at',
        'expires_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'min_purchase_amount' => 'decimal:2',
            'max_discount_amount' => 'decimal:2',
            'starts_at' => 'date:Y-m-d',
            'expires_at' => 'date:Y-m-d',
            'is_active' => 'boolean',
        ];
    }

    protected function companyId(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? '' : $value,
        );
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function resolveScopedId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'undefined') {
            return null;
        }

        return (int) $value;
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

        return $query->where('company_id', $user->company_id);
    }

    /**
     * Active rules whose validity window contains the given day (today by default).
     *
     * @param  Builder<Discount>  $query
     * @return Builder<Discount>
     */
    public function scopeValidOn(Builder $query, CarbonInterface|string|null $date = null): Builder
    {
        $day = Carbon::parse($date ?? now())->toDateString();

        return $query
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $day))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', $day));
    }

    /**
     * Why this rule cannot be used on a sale of $subtotal on $date, or null when it can.
     *
     * @return 'inactive'|'not_started'|'expired'|'min_purchase'|null
     */
    public function rejectionReason(float $subtotal, CarbonInterface|string|null $date = null): ?string
    {
        $day = Carbon::parse($date ?? now())->startOfDay();

        return match (true) {
            ! $this->is_active => 'inactive',
            $this->starts_at !== null && $day->lt($this->starts_at->copy()->startOfDay()) => 'not_started',
            $this->expires_at !== null && $day->gt($this->expires_at->copy()->startOfDay()) => 'expired',
            $subtotal < (float) $this->min_purchase_amount => 'min_purchase',
            default => null,
        };
    }

    /**
     * The amount this rule takes off a sale of $subtotal, ignoring the date and the active flag (see
     * `rejectionReason`). A percentage is capped by `max_discount_amount` when one is set, and no rule
     * ever takes off more than the sale itself. Rounded to 2 decimals.
     */
    public function discountFor(float $subtotal): float
    {
        if ($subtotal <= 0 || $subtotal < (float) $this->min_purchase_amount) {
            return 0.0;
        }

        if ($this->discount_type === self::TYPE_PERCENTAGE) {
            $amount = $subtotal * (float) $this->value / 100;

            if ($this->max_discount_amount !== null) {
                $amount = min($amount, (float) $this->max_discount_amount);
            }
        } else {
            $amount = (float) $this->value;
        }

        return round(min($amount, $subtotal), 2);
    }

    /**
     * The live rule with this coupon code in the company (codes are case-insensitive).
     */
    public static function findByCode(string $code, int $companyId): ?self
    {
        return self::query()
            ->where('company_id', $companyId)
            ->where('code', strtoupper(trim($code)))
            ->first();
    }

    /**
     * The company a record is saved under: the superadmin picks one, everyone else is always their own
     * company whatever the request says.
     */
    public static function scopedCompanyId(object $request, ?int $current = null): ?int
    {
        $user = Auth::user();

        if ($user?->hasRole('superadmin')) {
            return self::resolveScopedId($request->company_id) ?? $current;
        }

        return $user?->company_id ? (int) $user->company_id : $current;
    }

    public static function createDiscount(object $request): self
    {
        $discount = new self;
        $discount->company_id = self::scopedCompanyId($request);
        $discount->fillDetails($request);
        $discount->is_active = $request->has('is_active') ? $request->boolean('is_active') : true;
        $discount->save();

        return $discount;
    }

    public static function updateDiscount(object $request, int|string $id): self
    {
        $discount = self::query()->visibleToCurrentUser()->findOrFail($id);
        $discount->company_id = self::scopedCompanyId($request, (int) $discount->company_id) ?? $discount->company_id;
        $discount->fillDetails($request);
        $discount->is_active = $request->has('is_active') ? $request->boolean('is_active') : $discount->is_active;
        $discount->save();

        return $discount;
    }

    public static function deleteDiscount(int|string $id): void
    {
        self::query()->visibleToCurrentUser()->find($id)?->delete();
    }

    /**
     * A blank field is stored as null; a code is stored upper case; the cap only means something on a
     * percentage rule, so a fixed rule never keeps one.
     */
    private function fillDetails(object $request): void
    {
        $isPercentage = $request->discount_type === self::TYPE_PERCENTAGE;
        $code = strtoupper(self::blankToNull($request->code) ?? '');

        $this->name = trim((string) $request->name);
        $this->code = $code === '' ? null : $code;
        $this->discount_type = $request->discount_type;
        $this->value = (float) $request->value;
        $this->min_purchase_amount = (float) ($request->min_purchase_amount ?: 0);
        $this->max_discount_amount = $isPercentage && self::blankToNull($request->max_discount_amount) !== null
            ? (float) $request->max_discount_amount
            : null;
        $this->starts_at = self::blankToNull($request->starts_at);
        $this->expires_at = self::blankToNull($request->expires_at);
    }

    private static function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
