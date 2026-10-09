<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * A billing-cycle invoice for a {@see CustomerSubscription}. Manual-payment model, same shape as Module
 * 17's `SubscriptionInvoice` (copied, not shared — see that class's own docblock for why): `markPaid()`
 * is the admin's own "I received this payment" action, no gateway charge happens here.
 */
class CustomerSubscriptionInvoice extends Model
{
    public const STATUSES = ['unpaid', 'paid', 'overdue', 'cancelled', 'refunded'];

    /**
     * @var list<string>
     */
    public const SORTABLE = ['invoice_no', 'period_start', 'due_date', 'total_amount', 'status', 'created_at'];

    protected $fillable = [
        'company_id',
        'contact_id',
        'customer_subscription_id',
        'invoice_no',
        'period_start',
        'period_end',
        'amount',
        'setup_fee_amount',
        'metered_amount',
        'discount_amount',
        'total_amount',
        'status',
        'due_date',
        'payment_method',
        'payment_reference',
        'paid_amount',
        'paid_at',
        'document',
        'refunded_amount',
        'refunded_at',
        'marked_paid_by',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date:Y-m-d',
            'period_end' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'amount' => 'decimal:2',
            'setup_fee_amount' => 'decimal:2',
            'metered_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'refunded_amount' => 'decimal:2',
            'refunded_at' => 'datetime',
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
     * @return BelongsTo<CustomerSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CustomerSubscription::class, 'customer_subscription_id');
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

    public function scopeVisibleToPortalContact(Builder $query, int $contactId): Builder
    {
        return $query->where('contact_id', $contactId);
    }

    public static function nextInvoiceNo(): string
    {
        return DB::transaction(function (): string {
            $period = Carbon::now()->format('Y-m');
            $pattern = 'CSUB'.$period.'-';

            $last = self::query()
                ->where('invoice_no', 'like', $pattern.'%')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->value('invoice_no');

            $next = 1;

            if (is_string($last) && preg_match('/(\d+)$/', $last, $matches) === 1) {
                $next = (int) $matches[1] + 1;
            }

            do {
                $invoiceNo = $pattern.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
                $next++;
            } while (self::query()->where('invoice_no', $invoiceNo)->lockForUpdate()->exists());

            return $invoiceNo;
        });
    }

    /**
     * Generates the next cycle's invoice for a customer subscription whose period is due. Idempotent
     * for a given period. Includes the plan's setup fee only on the subscription's very first invoice,
     * and a metered overage charge when the plan is metered and usage exceeded its included units.
     */
    public static function generateForSubscription(CustomerSubscription $subscription): ?self
    {
        if (! in_array($subscription->status, ['trial', 'active'], true)) {
            return null;
        }

        $plan = $subscription->plan;

        if ($plan === null) {
            return null;
        }

        $periodStart = Carbon::parse($subscription->current_period_starts_at);
        $periodEnd = Carbon::parse($subscription->current_period_ends_at);

        $exists = self::query()
            ->where('customer_subscription_id', $subscription->id)
            ->whereDate('period_start', $periodStart->toDateString())
            ->exists();

        if ($exists) {
            return null;
        }

        $isFirstInvoice = ! self::query()->where('customer_subscription_id', $subscription->id)->exists();
        $amount = round((float) $plan->price, 2);
        $setupFee = $isFirstInvoice ? round((float) $plan->setup_fee, 2) : 0.0;

        $meteredAmount = 0.0;

        if ($plan->is_metered) {
            $used = CustomerSubscriptionUsage::totalForCurrentPeriod($subscription);
            $overageUnits = max($used - (float) ($plan->included_units ?? 0), 0);
            $meteredAmount = round($overageUnits * (float) ($plan->overage_rate ?? 0), 2);
        }

        $total = round($amount + $setupFee + $meteredAmount, 2);

        $invoice = new self;
        $invoice->company_id = $subscription->company_id;
        $invoice->contact_id = $subscription->contact_id;
        $invoice->customer_subscription_id = $subscription->id;
        $invoice->invoice_no = self::nextInvoiceNo();
        $invoice->period_start = $periodStart->toDateString();
        $invoice->period_end = $periodEnd->toDateString();
        $invoice->amount = $amount;
        $invoice->setup_fee_amount = $setupFee;
        $invoice->metered_amount = $meteredAmount;
        $invoice->discount_amount = 0;
        $invoice->total_amount = $total;
        $invoice->status = 'unpaid';
        $invoice->due_date = $periodEnd->toDateString();
        $invoice->save();

        if ($subscription->status === 'trial' && $periodStart->isPast()) {
            $subscription->status = 'active';
        }

        $subscription->current_period_starts_at = $periodStart->toDateString();
        $subscription->current_period_ends_at = $periodEnd->toDateString();
        $subscription->save();

        return $invoice;
    }

    /**
     * Opens the subscription's NEXT billing period and, if it still auto-renews, generates that
     * period's invoice straight away — the "Auto-Renewal" this module's scope asks for, still
     * manual-payment (the invoice is never auto-charged, only auto-generated).
     */
    public static function renew(CustomerSubscription $subscription): ?self
    {
        if (! $subscription->auto_renew || $subscription->status === 'cancelled') {
            return null;
        }

        $plan = $subscription->plan;

        if ($plan === null) {
            return null;
        }

        $nextStart = Carbon::parse($subscription->current_period_ends_at);
        $nextEnd = $nextStart->copy()->addDays($plan->cycleDays());

        $subscription->current_period_starts_at = $nextStart->toDateString();
        $subscription->current_period_ends_at = $nextEnd->toDateString();
        $subscription->status = 'active';
        $subscription->save();

        return self::generateForSubscription($subscription);
    }

    public function markPaid(string $method, ?string $reference, float $paidAmount, ?string $document, ?int $markedPaidBy): void
    {
        $this->status = 'paid';
        $this->payment_method = $method;
        $this->payment_reference = $reference;
        $this->paid_amount = $paidAmount;
        $this->paid_at = now();
        $this->document = $document;
        $this->marked_paid_by = $markedPaidBy;
        $this->save();
    }

    public function cancel(): void
    {
        $this->status = 'cancelled';
        $this->save();
    }

    /**
     * A pro-rated (or full) refund against an already-paid invoice. Manual, like the payment itself:
     * records that money was given back, does not move any money.
     */
    public function refund(float $amount): void
    {
        $this->status = 'refunded';
        $this->refunded_amount = round($amount, 2);
        $this->refunded_at = now();
        $this->save();
    }

    public static function flagOverdue(): int
    {
        return self::query()
            ->where('status', 'unpaid')
            ->whereDate('due_date', '<', now()->toDateString())
            ->update(['status' => 'overdue']);
    }
}
