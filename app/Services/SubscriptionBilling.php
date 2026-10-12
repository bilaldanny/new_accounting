<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerSubscription;
use App\Models\CustomerSubscriptionInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The manual-payment equivalent of auto-charging for customer subscriptions (the app never charges a card):
 *
 *  - the next cycle's invoice is generated automatically when a period ends (CustomerSubscriptionInvoice::renew);
 *  - an unpaid invoice gets a reminder three days before it is due;
 *  - an overdue invoice gets the company's dunning level it has reached (the same three levels and templates as invoice dunning,
 *    Services\Dunning), once per level;
 *  - a cancellation can refund the unused part of the current paid period, pro rata.
 *
 * Reminders go by email to the customer (or are only recorded when there is no address), and each is kept once per invoice and
 * kind, so running it daily never repeats one.
 */
class SubscriptionBilling
{
    public function __construct(private readonly Dunning $dunning) {}

    /**
     * @return array{generated: int, upcoming: int, dunning: int, flagged_overdue: int}
     */
    public function run(?int $companyId = null): array
    {
        $generated = 0;

        CustomerSubscription::query()
            ->whereIn('status', ['trial', 'active'])
            ->where('auto_renew', true)
            ->whereDate('current_period_ends_at', '<=', now()->toDateString())
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->orderBy('id')
            ->get()
            ->each(function (CustomerSubscription $subscription) use (&$generated): void {
                if (CustomerSubscriptionInvoice::renew($subscription) !== null) {
                    $generated++;
                }
            });

        $flagged = CustomerSubscriptionInvoice::flagOverdue();
        $upcoming = 0;
        $dunning = 0;

        if (Schema::hasTable('subscription_reminders')) {
            $levelsByCompany = [];

            CustomerSubscriptionInvoice::query()
                ->whereIn('status', ['unpaid', 'overdue'])
                ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
                ->orderBy('id')
                ->get()
                ->each(function (CustomerSubscriptionInvoice $invoice) use (&$upcoming, &$dunning, &$levelsByCompany): void {
                    $days = (int) floor((now()->startOfDay()->getTimestamp() - $invoice->due_date->startOfDay()->getTimestamp()) / 86400);

                    if ($days < 0 && $days >= -3) {
                        $upcoming += $this->remind($invoice, 'upcoming', 'Payment due soon', 'Invoice {invoice} for {amount} falls due on {due_date}.');

                        return;
                    }

                    if ($days <= 0) {
                        return;
                    }

                    $levels = $levelsByCompany[$invoice->company_id] ??= $this->dunning->levels((int) $invoice->company_id)->where('is_active', true)->sortByDesc('level');
                    $level = $levels->first(fn ($candidate): bool => $candidate->days_past_due <= $days);

                    if ($level !== null) {
                        $dunning += $this->remind($invoice, 'dunning_'.$level->level, $level->subject, $level->body);
                    }
                });
        }

        return ['generated' => $generated, 'upcoming' => $upcoming, 'dunning' => $dunning, 'flagged_overdue' => $flagged];
    }

    /**
     * What cancelling now would give back: the paid invoice that covers today, the unused days of it, and the pro-rata amount.
     *
     * @return array{invoice_id: int|null, paid: float, unused_days: int, period_days: int, refund: float}
     */
    public function quote(CustomerSubscription $subscription): array
    {
        $today = now()->startOfDay();
        $invoice = CustomerSubscriptionInvoice::query()
            ->where('customer_subscription_id', $subscription->id)->where('status', 'paid')
            ->whereDate('period_start', '<=', $today->toDateString())->whereDate('period_end', '>=', $today->toDateString())
            ->orderByDesc('id')->first();

        if ($invoice === null) {
            return ['invoice_id' => null, 'paid' => 0.0, 'unused_days' => 0, 'period_days' => 0, 'refund' => 0.0];
        }

        $periodDays = max(1, (int) $invoice->period_start->startOfDay()->diffInDays($invoice->period_end->startOfDay()));
        $unused = max(0, (int) $today->diffInDays($invoice->period_end->startOfDay()));
        $paid = round((float) $invoice->paid_amount, 2);

        return [
            'invoice_id' => (int) $invoice->id, 'paid' => $paid, 'unused_days' => min($unused, $periodDays), 'period_days' => $periodDays,
            'refund' => round($paid * min($unused, $periodDays) / $periodDays, 2),
        ];
    }

    /**
     * Cancels the subscription now and, when asked, records the pro-rata refund on the invoice it was paid on.
     *
     * @return array{refund: float}
     *
     * @throws ValidationException
     */
    public function cancelWithRefund(CustomerSubscription $subscription, ?string $reason, bool $refund): array
    {
        if ($subscription->status === 'cancelled') {
            throw ValidationException::withMessages(['status' => ['This subscription is already cancelled.']]);
        }

        return DB::transaction(function () use ($subscription, $reason, $refund): array {
            $quote = $this->quote($subscription);

            if ($refund && $quote['invoice_id'] !== null && $quote['refund'] > 0) {
                CustomerSubscriptionInvoice::query()->findOrFail($quote['invoice_id'])->refund($quote['refund']);
            }

            $subscription->cancel($reason);

            return ['refund' => $refund ? $quote['refund'] : 0.0];
        });
    }

    private function remind(CustomerSubscriptionInvoice $invoice, string $kind, string $subject, string $body): int
    {
        if (DB::table('subscription_reminders')->where('customer_subscription_invoice_id', $invoice->id)->where('kind', $kind)->exists()) {
            return 0;
        }

        $contact = Contact::query()->find($invoice->contact_id);
        $company = Company::query()->find($invoice->company_id);
        $values = [
            '{customer}' => (string) ($contact?->business_name ?: trim((string) $contact?->first_name.' '.(string) $contact?->last_name)),
            '{invoice}' => (string) $invoice->invoice_no, '{amount}' => number_format((float) $invoice->total_amount, 2),
            '{due_date}' => $invoice->due_date->toDateString(),
            '{days_overdue}' => (string) max(0, (int) $invoice->due_date->startOfDay()->diffInDays(now()->startOfDay(), false)),
            '{company}' => (string) $company?->name,
        ];
        $text = strtr($body, $values);
        $status = 'logged';

        if ($contact?->email && filter_var($contact->email, FILTER_VALIDATE_EMAIL)) {
            try {
                Mail::raw($text, fn ($message) => $message->to($contact->email)->subject(strtr($subject, $values)));
                $status = 'sent';
            } catch (Throwable) {
                $status = 'failed';
            }
        }

        DB::table('subscription_reminders')->insert([
            'company_id' => $invoice->company_id, 'customer_subscription_invoice_id' => $invoice->id, 'kind' => $kind, 'channel' => 'email',
            'status' => $status, 'message' => $text, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return 1;
    }
}
