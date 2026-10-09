<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * A tenant's own subscription product (gym membership, maintenance contract, their own SaaS, ...) that
 * THEIR customers subscribe to — company-scoped, unlike {@see SubscriptionPlan} (Module 17's global,
 * platform-owner plan catalog).
 */
class CustomerSubscriptionPlan extends Model
{
    use SoftDeletes;

    public const BILLING_CYCLES = ['monthly', 'quarterly', 'annual'];

    /**
     * @var list<string>
     */
    public const SORTABLE = ['name', 'billing_cycle', 'price', 'is_active', 'sort_order', 'created_at'];

    protected $fillable = [
        'company_id',
        'name',
        'code',
        'billing_cycle',
        'price',
        'setup_fee',
        'trial_days',
        'is_metered',
        'unit_label',
        'included_units',
        'overage_rate',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'setup_fee' => 'decimal:2',
            'overage_rate' => 'decimal:4',
            'is_metered' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<CustomerSubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(CustomerSubscription::class);
    }

    public function cycleDays(): int
    {
        return match ($this->billing_cycle) {
            'quarterly' => 90,
            'annual' => 365,
            default => 30,
        };
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

    public static function resolveScopedId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'undefined') {
            return null;
        }

        return (int) $value;
    }

    private static function scopedCompanyId(object $request, ?int $current = null): ?int
    {
        $user = Auth::user();

        if ($user?->hasRole('superadmin')) {
            return self::resolveScopedId($request->company_id) ?? $current;
        }

        return $user?->company_id ? (int) $user->company_id : $current;
    }

    public static function createPlan(object $request): self
    {
        $plan = new self;
        $plan->company_id = self::scopedCompanyId($request);
        self::fillFromRequest($plan, $request);
        $plan->save();

        return $plan;
    }

    public static function updatePlan(object $request, int|string $id): self
    {
        $plan = self::query()->visibleToCurrentUser()->findOrFail($id);
        self::fillFromRequest($plan, $request);
        $plan->save();

        return $plan;
    }

    public static function deletePlan(int|string $id): void
    {
        self::query()->visibleToCurrentUser()->find($id)?->delete();
    }

    private static function fillFromRequest(self $plan, object $request): void
    {
        $plan->name = trim((string) $request->name);
        $plan->code = trim((string) $request->code);
        $plan->billing_cycle = $request->billing_cycle;
        $plan->price = round((float) $request->price, 2);
        $plan->setup_fee = round((float) ($request->setup_fee ?? 0), 2);
        $plan->trial_days = (int) ($request->trial_days ?? 0);
        $plan->is_metered = $request->has('is_metered') ? (bool) $request->boolean('is_metered') : false;
        $plan->unit_label = $plan->is_metered ? $request->unit_label : null;
        $plan->included_units = $plan->is_metered ? ($request->filled('included_units') ? (int) $request->included_units : 0) : null;
        $plan->overage_rate = $plan->is_metered ? round((float) ($request->overage_rate ?? 0), 4) : null;
        $plan->sort_order = (int) ($request->sort_order ?? 0);
        $plan->is_active = $request->has('is_active') ? (bool) $request->boolean('is_active') : true;
    }
}
