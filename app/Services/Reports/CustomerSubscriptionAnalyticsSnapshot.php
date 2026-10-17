<?php

namespace App\Services\Reports;

use App\Models\Company;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The stored copy of the Subscription Analytics numbers. A snapshot is one row per scope (a company, or `all` for the superadmin)
 * and kind (summary, churn, ltv, revenue), made from {@see CustomerSubscriptionReport} itself, so the figures are the report's own.
 * It is used only for the same calendar day it was made, is dropped as soon as a subscription, invoice or plan of that company
 * changes, and is rebuilt by `customersubscriptions:refresh-analytics` (daily from the scheduler).
 */
class CustomerSubscriptionAnalyticsSnapshot
{
    public static function scope(?int $companyId): string
    {
        return $companyId === null ? 'all' : 'company:'.$companyId;
    }

    /**
     * @param  Closure(): mixed  $compute
     * @return array{0: mixed, 1: string|null} the figures and when they were made
     */
    public static function remember(?int $companyId, string $kind, Closure $compute): array
    {
        if (! Schema::hasTable('customer_subscription_analytics_snapshots')) {
            return [$compute(), null];
        }

        $row = DB::table('customer_subscription_analytics_snapshots')->where('scope', self::scope($companyId))->where('kind', $kind)->first();

        if ($row !== null && substr((string) $row->generated_at, 0, 10) === now()->toDateString()) {
            return [json_decode((string) $row->payload, true), (string) $row->generated_at];
        }

        return self::store($companyId, $kind, $compute());
    }

    /**
     * @return array{0: mixed, 1: string}
     */
    public static function store(?int $companyId, string $kind, mixed $payload): array
    {
        $at = now()->toDateTimeString();

        DB::table('customer_subscription_analytics_snapshots')->upsert(
            [['scope' => self::scope($companyId), 'kind' => $kind, 'payload' => json_encode($payload), 'generated_at' => $at]],
            ['scope', 'kind'],
            ['payload', 'generated_at'],
        );

        return [$payload, $at];
    }

    /** Drops the company's snapshots and the all-companies ones: the next read makes them again. */
    public static function forget(?int $companyId): void
    {
        if (! Schema::hasTable('customer_subscription_analytics_snapshots')) {
            return;
        }

        $query = DB::table('customer_subscription_analytics_snapshots');

        // a record that belongs to no company (a platform plan) can change every company's numbers
        $companyId === null ? $query->delete() : $query->whereIn('scope', ['all', self::scope($companyId)])->delete();
    }

    /**
     * The default report of each kind for one scope, as the screen asks for it.
     *
     * @return array<string, mixed>
     */
    public static function compute(?int $companyId): array
    {
        return [
            'summary' => CustomerSubscriptionReport::summary($companyId),
            'churn' => CustomerSubscriptionReport::churn($companyId, 30),
            'ltv' => CustomerSubscriptionReport::ltv($companyId),
            'revenue' => ['data' => CustomerSubscriptionReport::revenueByMonth($companyId, 6)],
        ];
    }

    /** Rebuilds every snapshot (each company and the all-companies one) and says how many scopes it made. */
    public static function refreshAll(?int $onlyCompany = null): int
    {
        $scopes = $onlyCompany !== null ? [$onlyCompany] : [...Company::query()->pluck('id')->map(fn ($id): int => (int) $id)->all(), null];

        foreach ($scopes as $companyId) {
            foreach (self::compute($companyId) as $kind => $payload) {
                self::store($companyId, $kind, $payload);
            }
        }

        return count($scopes);
    }
}
