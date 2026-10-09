<?php

namespace App\Services\Reports;

use App\Models\CustomerSubscription;
use App\Models\CustomerSubscriptionInvoice;
use Illuminate\Support\Carbon;

/**
 * Customer Subscription Module reports: Active/Expired/Cancelled counts, Renewal Due, MRR, ARR, Churn,
 * LTV, Revenue. Scoped per company (or all companies for the superadmin), mirroring
 * {@see CrmAnalyticsReport}'s shape.
 */
class CustomerSubscriptionReport
{
    /**
     * Active/Expired/Cancelled/Trial/Paused counts, Renewal Due in the next 7 days, MRR and ARR.
     *
     * @return array<string, mixed>
     */
    public static function summary(?int $companyId): array
    {
        $base = CustomerSubscription::query()->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId));

        $counts = (clone $base)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $renewalDue = (clone $base)
            ->whereIn('status', ['trial', 'active'])
            ->whereBetween('current_period_ends_at', [now()->toDateString(), now()->addDays(7)->toDateString()])
            ->count();

        $mrr = self::mrr($companyId);

        return [
            'active' => (int) ($counts['active'] ?? 0),
            'trial' => (int) ($counts['trial'] ?? 0),
            'paused' => (int) ($counts['paused'] ?? 0),
            'cancelled' => (int) ($counts['cancelled'] ?? 0),
            'expired' => (int) ($counts['expired'] ?? 0),
            'renewal_due_7_days' => $renewalDue,
            'mrr' => $mrr,
            'arr' => round($mrr * 12, 2),
        ];
    }

    /**
     * Monthly Recurring Revenue: every active/trial subscription's plan price, normalized to a monthly
     * figure by its billing cycle.
     */
    public static function mrr(?int $companyId): float
    {
        $subscriptions = CustomerSubscription::query()
            ->whereIn('status', ['active', 'trial'])
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->with('plan:id,price,billing_cycle')
            ->get();

        $mrr = $subscriptions->sum(function (CustomerSubscription $subscription) {
            $plan = $subscription->plan;

            if ($plan === null) {
                return 0;
            }

            return match ($plan->billing_cycle) {
                'quarterly' => (float) $plan->price / 3,
                'annual' => (float) $plan->price / 12,
                default => (float) $plan->price,
            };
        });

        return round($mrr, 2);
    }

    /**
     * Churn rate over the last N days: subscriptions cancelled ÷ subscriptions active at the start of
     * the window.
     *
     * @return array<string, mixed>
     */
    public static function churn(?int $companyId, int $days = 30): array
    {
        $windowStart = now()->subDays($days);

        $activeAtStart = CustomerSubscription::query()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->where('created_at', '<=', $windowStart)
            ->where(function ($q) use ($windowStart) {
                $q->whereNull('cancelled_at')->orWhere('cancelled_at', '>', $windowStart);
            })
            ->count();

        $cancelledInWindow = CustomerSubscription::query()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->where('status', 'cancelled')
            ->where('cancelled_at', '>=', $windowStart)
            ->count();

        $rate = $activeAtStart > 0 ? round(($cancelledInWindow / $activeAtStart) * 100, 2) : 0.0;

        return [
            'days' => $days,
            'active_at_start' => $activeAtStart,
            'cancelled_in_window' => $cancelledInWindow,
            'churn_rate_percent' => $rate,
        ];
    }

    /**
     * Average Lifetime Value: average total revenue collected per subscription that has ever had a paid
     * invoice.
     *
     * @return array<string, mixed>
     */
    public static function ltv(?int $companyId): array
    {
        $paidBySubscription = CustomerSubscriptionInvoice::query()
            ->where('status', 'paid')
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->selectRaw('customer_subscription_id, sum(total_amount) as total')
            ->groupBy('customer_subscription_id')
            ->pluck('total');

        $count = $paidBySubscription->count();
        $total = round((float) $paidBySubscription->sum(), 2);
        $average = $count > 0 ? round($total / $count, 2) : 0.0;

        return [
            'subscriptions_counted' => $count,
            'total_revenue' => $total,
            'average_ltv' => $average,
        ];
    }

    /**
     * Revenue collected (paid invoices) per month over the last N months.
     *
     * @return list<array<string, mixed>>
     */
    public static function revenueByMonth(?int $companyId, int $months = 6): array
    {
        $rows = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $start = $month->copy()->startOfMonth();
            $end = $month->copy()->endOfMonth();

            $revenue = (float) CustomerSubscriptionInvoice::query()
                ->where('status', 'paid')
                ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
                ->whereBetween('paid_at', [$start, $end])
                ->sum('total_amount');

            $rows[] = [
                'month' => $start->format('Y-m'),
                'revenue' => round($revenue, 2),
            ];
        }

        return $rows;
    }

    /**
     * Renewal Due: trial/active subscriptions whose current period ends within $days.
     *
     * @return list<array<string, mixed>>
     */
    public static function renewalDue(?int $companyId, int $days = 7): array
    {
        return CustomerSubscription::query()
            ->whereIn('status', ['trial', 'active'])
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->whereBetween('current_period_ends_at', [now()->toDateString(), now()->addDays($days)->toDateString()])
            ->with(['contact:id,first_name,last_name,business_name', 'plan:id,name,price'])
            ->get()
            ->map(function (CustomerSubscription $subscription): array {
                $contact = $subscription->contact;

                return [
                    'id' => $subscription->id,
                    'customer_name' => $contact?->business_name ?: trim((string) $contact?->first_name.' '.(string) $contact?->last_name),
                    'plan_name' => $subscription->plan?->name,
                    'renews_on' => Carbon::parse($subscription->current_period_ends_at)->toDateString(),
                ];
            })
            ->all();
    }
}
