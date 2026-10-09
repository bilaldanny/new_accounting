<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * A customer's contract against one of the tenant's own subscription products
 * ({@see CustomerSubscriptionPlan}) — gym membership, maintenance contract, the tenant's own SaaS, etc.
 * Not to be confused with {@see Company}'s own platform subscription (Module 17): this is a tenant's
 * CUSTOMER subscribing to something the tenant sells.
 */
class CustomerSubscription extends Model
{
    /**
     * @var list<string>
     */
    public const STATUSES = ['trial', 'active', 'paused', 'cancelled', 'expired'];

    protected $fillable = [
        'company_id',
        'contact_id',
        'customer_subscription_plan_id',
        'status',
        'start_date',
        'trial_ends_at',
        'current_period_starts_at',
        'current_period_ends_at',
        'auto_renew',
        'paused_at',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'trial_ends_at' => 'datetime',
            'current_period_starts_at' => 'date:Y-m-d',
            'current_period_ends_at' => 'date:Y-m-d',
            'auto_renew' => 'boolean',
            'paused_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<CustomerSubscriptionPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(CustomerSubscriptionPlan::class, 'customer_subscription_plan_id');
    }

    /**
     * @return HasMany<CustomerSubscriptionInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(CustomerSubscriptionInvoice::class);
    }

    /**
     * @return HasMany<CustomerSubscriptionUsage, $this>
     */
    public function usages(): HasMany
    {
        return $this->hasMany(CustomerSubscriptionUsage::class);
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
     * A portal (customer) account only ever sees its own contract.
     */
    public function scopeVisibleToPortalContact(Builder $query, int $contactId): Builder
    {
        return $query->where('contact_id', $contactId);
    }

    public static function subscribe(int $companyId, int $contactId, CustomerSubscriptionPlan $plan): self
    {
        $now = now();
        $trialEndsAt = $plan->trial_days > 0 ? $now->copy()->addDays($plan->trial_days) : null;
        $periodStart = $trialEndsAt ?? $now;

        $subscription = new self;
        $subscription->company_id = $companyId;
        $subscription->contact_id = $contactId;
        $subscription->customer_subscription_plan_id = $plan->id;
        $subscription->status = $trialEndsAt !== null ? 'trial' : 'active';
        $subscription->start_date = $now->toDateString();
        $subscription->trial_ends_at = $trialEndsAt;
        $subscription->current_period_starts_at = $periodStart->toDateString();
        $subscription->current_period_ends_at = $periodStart->copy()->addDays($plan->cycleDays())->toDateString();
        $subscription->auto_renew = true;
        $subscription->save();

        return $subscription;
    }

    /**
     * Upgrade/Downgrade: swaps the plan. The new plan's price applies from the NEXT invoice; the
     * current period already billed is left alone (no dangling-credit refund logic to invent).
     */
    public function changePlan(CustomerSubscriptionPlan $plan): void
    {
        $this->customer_subscription_plan_id = $plan->id;
        $this->save();
    }

    /**
     * @throws ValidationException
     */
    public function pause(): void
    {
        if ($this->status !== 'active' && $this->status !== 'trial') {
            throw ValidationException::withMessages([
                'status' => ['Only an active or trial subscription can be paused.'],
            ]);
        }

        $this->status = 'paused';
        $this->paused_at = now();
        $this->save();
    }

    /**
     * @throws ValidationException
     */
    public function resume(): void
    {
        if ($this->status !== 'paused') {
            throw ValidationException::withMessages([
                'status' => ['Only a paused subscription can be resumed.'],
            ]);
        }

        $this->status = 'active';
        $this->paused_at = null;
        $this->save();
    }

    public function cancel(?string $reason = null): void
    {
        $this->status = 'cancelled';
        $this->cancelled_at = now();
        $this->cancellation_reason = $reason;
        $this->auto_renew = false;
        $this->save();
    }

    public static function flagExpired(): int
    {
        return self::query()
            ->whereIn('status', ['trial', 'active'])
            ->where('auto_renew', false)
            ->whereDate('current_period_ends_at', '<', now()->toDateString())
            ->update(['status' => 'expired']);
    }
}
