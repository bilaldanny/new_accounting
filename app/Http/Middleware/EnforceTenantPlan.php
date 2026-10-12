<?php

namespace App\Http\Middleware;

use App\Services\Saas\PlanFeatures;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two tenant-level gates on a signed-in company user (never the superadmin):
 *
 *  - a feature the company's plan does not include answers 403 `plan_feature_off` (a plan with no feature list includes all);
 *  - a suspended or cancelled company is shut out of everything except sign-out, its password page and its own billing page, so
 *    it can still see what is owed and pay it (a payment reactivates a company that was suspended for non-payment).
 *
 * A company with no plan, on an active or trial status, passes through untouched.
 */
class EnforceTenantPlan
{
    private const OPEN_WHEN_SUSPENDED = ['logout', 'api/logout', 'settings/password', 'api/settings/password', 'api/tenant-billing', 'api/tenant-billing/*', 'tenant-billing', 'api/support-tickets', 'api/support-tickets/*', 'api/branding'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->company_id === null || $user->hasRole('superadmin')) {
            return $next($request);
        }

        $companyId = (int) $user->company_id;
        $company = DB::table('companies')->where('id', $companyId)->first(['tenant_status', 'suspension_reason']);

        if ($company !== null && in_array($company->tenant_status, ['suspended', 'cancelled'], true) && ! $request->is(...self::OPEN_WHEN_SUSPENDED)) {
            $message = $company->tenant_status === 'cancelled'
                ? 'This subscription was cancelled. Export your data or contact support.'
                : 'This account is suspended'.($company->suspension_reason ? ': '.$company->suspension_reason : '').'. Settle the invoice to continue.';

            return $request->expectsJson()
                ? response()->json(['message' => $message, 'tenant_status' => $company->tenant_status], 402)
                : response($message, 402);
        }

        $feature = PlanFeatures::featureForPath($request->path());

        if ($feature !== null && ! PlanFeatures::enabled($companyId, $feature)) {
            return response()->json(['message' => 'Your plan does not include '.PlanFeatures::CATALOG[$feature][0].'.', 'plan_feature_off' => $feature], 403);
        }

        return $next($request);
    }
}
