<?php

namespace App\Services\Saas;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-feature on / off switches of a subscription plan. A plan with no feature list (the default, and every plan that exists
 * today) includes every feature; a plan with a list includes only those, and the API of a feature that is off answers 403
 * `plan_feature_off`. Generic package settings (`plan->settings`) are read with {@see setting()}.
 */
class PlanFeatures
{
    /**
     * Feature key => [label, api path prefixes it covers].
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    public const CATALOG = [
        'crm' => ['CRM (leads, opportunities, activities)', ['api/leads', 'api/lead-sources', 'api/opportunities', 'api/activities', 'api/pipeline', 'api/crm']],
        'customer_subscriptions' => ['Customer subscriptions', ['api/customer-subscription', 'api/customer-subscriptions', 'api/customer-subscription-plans', 'api/customer-subscription-billing']],
        'fixed_assets' => ['Fixed assets and depreciation', ['api/fixed-assets', 'api/asset-categories', 'api/depreciation']],
        'budgeting' => ['Budgets and cost centers', ['api/budgets', 'api/cost-centers']],
        'multi_currency' => ['Foreign currency vouchers', ['api/foreign-currency']],
        'offline_pos' => ['Offline POS', ['api/pos/offline']],
        'approval_center' => ['Approval workflows', ['api/approval-center']],
        'journal_schedules' => ['Journal schedules', ['api/journal-schedules']],
        'projects' => ['Project costing', ['api/projects']],
        'ecommerce' => ['E-commerce sync', ['api/ecommerce-connections']],
        'api_access' => ['API keys and webhooks', ['api/api-keys', 'api/webhooks']],
        'hrm' => ['HR and payroll', ['api/hrm', 'api/leave-requests', 'api/payroll-runs']],
        'manufacturing' => ['Manufacturing', ['api/manufacturing', 'api/boms', 'api/work-orders']],
        'manufacturing' => ['Manufacturing', ['api/manufacturing']],
        'reports_builder' => ['Custom report builder', ['api/report-builder', 'api/report-schedules']],
    ];

    public static function available(): bool
    {
        return Schema::hasColumn('subscription_plans', 'features');
    }

    /**
     * The feature the path belongs to, or null when it is not a switchable one.
     */
    public static function featureForPath(string $path): ?string
    {
        foreach (self::CATALOG as $key => [, $prefixes]) {
            foreach ($prefixes as $prefix) {
                if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * Whether the company's plan includes the feature (true when it has no plan, or the plan lists none).
     */
    public static function enabled(int $companyId, string $feature): bool
    {
        if (! self::available()) {
            return true;
        }

        $features = DB::table('companies as c')->join('subscription_plans as p', 'p.id', '=', 'c.subscription_plan_id')->where('c.id', $companyId)->value('p.features');

        if ($features === null) {
            return true;
        }

        return in_array($feature, (array) json_decode((string) $features, true), true);
    }

    public static function setting(Company|int $company, string $key, mixed $default = null): mixed
    {
        if (! self::available()) {
            return $default;
        }

        $id = $company instanceof Company ? $company->id : $company;
        $settings = DB::table('companies as c')->join('subscription_plans as p', 'p.id', '=', 'c.subscription_plan_id')->where('c.id', $id)->value('p.settings');

        return data_get((array) json_decode((string) $settings, true), $key, $default);
    }
}
