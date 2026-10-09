<?php

namespace App\Models;

use App\Services\WebhookDispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A tenant's bill for one billing-cycle period. Payment is manual-only (this build's scope — no gateway
 * yet): `markPaid()` is the admin's own "I received this payment" action, the same shape (method,
 * reference, attachment) {@see Payment} already uses for sell/purchase payments, kept directly on this
 * table rather than forced through `payments` (which is built around a sell/purchase `Transaction` +
 * `Contact` ledger posting that a subscription invoice has neither of).
 */
class SubscriptionInvoice extends Model
{
    public const STATUSES = ['unpaid', 'paid', 'overdue', 'cancelled'];

    /**
     * @var list<string>
     */
    public const SORTABLE = ['invoice_no', 'period_start', 'due_date', 'total_amount', 'status', 'created_at'];

    protected $fillable = [
        'company_id',
        'subscription_plan_id',
        'coupon_id',
        'invoice_no',
        'period_start',
        'period_end',
        'amount',
        'discount_amount',
        'total_amount',
        'status',
        'due_date',
        'payment_method',
        'payment_reference',
        'paid_amount',
        'paid_at',
        'document',
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
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'paid_at' => 'datetime',
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
     * @return BelongsTo<SubscriptionPlan, $this>
     */
    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    /**
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
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

    public static function nextInvoiceNo(): string
    {
        return DB::transaction(function (): string {
            $period = Carbon::now()->format('Y-m');
            $pattern = 'SUB'.$period.'-';

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
     * Generates the next cycle's invoice for a company on a plan, covering the period right after its
     * current one (or starting now, for a company's very first invoice). Idempotent for a given period:
     * returns null instead of a duplicate if an invoice for that period already exists.
     */
    public static function generateForCompany(Company $company): ?self
    {
        $plan = $company->subscriptionPlan;

        if ($plan === null) {
            return null;
        }

        $periodStart = $company->current_period_starts_at?->copy() ?? now();
        $periodEnd = $company->current_period_ends_at?->copy() ?? $periodStart->copy()->addDays($plan->cycleDays());

        $exists = self::query()
            ->where('company_id', $company->id)
            ->whereDate('period_start', $periodStart->toDateString())
            ->exists();

        if ($exists) {
            return null;
        }

        $isRenewal = self::query()->where('company_id', $company->id)->exists();
        $amount = round((float) $plan->price, 2);

        $invoice = new self;
        $invoice->company_id = $company->id;
        $invoice->subscription_plan_id = $plan->id;
        $invoice->invoice_no = self::nextInvoiceNo();
        $invoice->period_start = $periodStart->toDateString();
        $invoice->period_end = $periodEnd->toDateString();
        $invoice->amount = $amount;
        $invoice->discount_amount = 0;
        $invoice->total_amount = $amount;
        $invoice->status = 'unpaid';
        $invoice->due_date = $periodEnd->toDateString();
        $invoice->save();

        $company->current_period_starts_at = $periodStart;
        $company->current_period_ends_at = $periodEnd;
        $company->save();

        $eventPayload = [
            'invoice_no' => $invoice->invoice_no,
            'company_id' => $company->id,
            'period_start' => $invoice->period_start->toDateString(),
            'period_end' => $invoice->period_end->toDateString(),
            'total_amount' => (float) $invoice->total_amount,
            'due_date' => $invoice->due_date->toDateString(),
        ];

        WebhookDispatcher::fire($company->id, 'invoice.created', $eventPayload);

        if ($isRenewal) {
            WebhookDispatcher::fire($company->id, 'subscription.renewed', $eventPayload);
        }

        return $invoice;
    }

    /**
     * @throws ValidationException
     */
    public function applyCoupon(string $code): void
    {
        $coupon = Coupon::assertRedeemable($code);

        $this->coupon_id = $coupon->id;
        $this->discount_amount = $coupon->discountFor((float) $this->amount);
        $this->total_amount = round((float) $this->amount - (float) $this->discount_amount, 2);
        $this->save();

        $coupon->redeem();
    }

    /**
     * The admin's manual "mark as paid" action — no gateway charge happens here, only the record of a
     * payment someone already made out-of-band (bank transfer, cash, ...).
     */
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
     * Flags every unpaid invoice whose due date has passed as overdue. Pure status tracking — no
     * auto-charge/retry, per this build's manual-payment scope.
     */
    public static function flagOverdue(): int
    {
        return self::query()
            ->where('status', 'unpaid')
            ->whereDate('due_date', '<', now()->toDateString())
            ->update(['status' => 'overdue']);
    }
}
