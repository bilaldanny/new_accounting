<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A metered-billing usage record for one customer subscription's period — the simplest common shape
 * ("quantity used in this period"), billed as (total quantity - plan.included_units) * overage_rate
 * when positive. See {@see CustomerSubscriptionInvoice::generateForSubscription()}.
 */
class CustomerSubscriptionUsage extends Model
{
    protected $fillable = [
        'customer_subscription_id',
        'period_start',
        'period_end',
        'quantity',
        'note',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'quantity' => 'decimal:2',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CustomerSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CustomerSubscription::class, 'customer_subscription_id');
    }

    public static function record(CustomerSubscription $subscription, float $quantity, ?string $note = null): self
    {
        $usage = new self;
        $usage->customer_subscription_id = $subscription->id;
        $usage->period_start = $subscription->current_period_starts_at;
        $usage->period_end = $subscription->current_period_ends_at;
        $usage->quantity = $quantity;
        $usage->note = $note;
        $usage->recorded_at = now();
        $usage->save();

        return $usage;
    }

    /**
     * Total quantity recorded for the subscription's CURRENT period.
     */
    public static function totalForCurrentPeriod(CustomerSubscription $subscription): float
    {
        return (float) self::query()
            ->where('customer_subscription_id', $subscription->id)
            ->whereDate('period_start', $subscription->current_period_starts_at)
            ->sum('quantity');
    }
}
