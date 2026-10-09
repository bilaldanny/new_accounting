<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The SaaS plan catalog (Basic/Standard/Premium, ...) — global master data owned by the superadmin, not
 * company-scoped: a tenant subscribes TO a plan, it does not create its own.
 */
class SubscriptionPlan extends Model
{
    use SoftDeletes;

    public const BILLING_CYCLES = ['monthly', 'quarterly', 'annual'];

    /**
     * @var list<string>
     */
    public const SORTABLE = ['name', 'billing_cycle', 'price', 'is_active', 'sort_order', 'created_at'];

    protected $fillable = [
        'name',
        'code',
        'billing_cycle',
        'price',
        'trial_days',
        'max_users',
        'max_branches',
        'max_warehouses',
        'max_products',
        'max_invoices_per_month',
        'max_storage_mb',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Company, $this>
     */
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    /**
     * How many days a billing cycle covers — used to compute the next invoice's period.
     */
    public function cycleDays(): int
    {
        return match ($this->billing_cycle) {
            'quarterly' => 90,
            'annual' => 365,
            default => 30,
        };
    }

    public static function createPlan(object $request): self
    {
        $plan = new self;
        self::fillFromRequest($plan, $request);
        $plan->save();

        return $plan;
    }

    public static function updatePlan(object $request, int|string $id): self
    {
        $plan = self::query()->findOrFail($id);
        self::fillFromRequest($plan, $request);
        $plan->save();

        return $plan;
    }

    public static function deletePlan(int|string $id): void
    {
        self::query()->find($id)?->delete();
    }

    private static function fillFromRequest(self $plan, object $request): void
    {
        $plan->name = trim((string) $request->name);
        $plan->code = trim((string) $request->code);
        $plan->billing_cycle = $request->billing_cycle;
        $plan->price = round((float) $request->price, 2);
        $plan->trial_days = (int) ($request->trial_days ?? 14);
        $plan->max_users = (int) ($request->max_users ?? 10);
        $plan->max_branches = (int) ($request->max_branches ?? 2);
        $plan->max_warehouses = (int) ($request->max_warehouses ?? 2);
        $plan->max_products = (int) ($request->max_products ?? 500);
        $plan->max_invoices_per_month = (int) ($request->max_invoices_per_month ?? 200);
        $plan->max_storage_mb = (int) ($request->max_storage_mb ?? 500);
        $plan->sort_order = (int) ($request->sort_order ?? 0);
        $plan->is_active = $request->has('is_active') ? (bool) $request->boolean('is_active') : true;
    }
}
