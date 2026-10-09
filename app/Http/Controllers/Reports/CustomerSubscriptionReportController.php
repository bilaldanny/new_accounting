<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\CustomerSubscriptionReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Customer Subscription Module reports: Active/Expired/Cancelled, Renewal Due, MRR, ARR, Churn, LTV,
 * Revenue — routed under its own menu (`/customersubscriptionanalytics`), the same convention
 * {@see CrmReportController} already uses for a funnel/leaderboard-shaped report that doesn't fit the
 * generic paginated-row `report/index.vue`.
 */
class CustomerSubscriptionReportController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/customersubscriptionanalytics');

        return response()->json(CustomerSubscriptionReport::summary($this->scopedCompanyId($request)));
    }

    public function churn(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/customersubscriptionanalytics');

        return response()->json(CustomerSubscriptionReport::churn($this->scopedCompanyId($request), (int) $request->integer('days', 30)));
    }

    public function ltv(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/customersubscriptionanalytics');

        return response()->json(CustomerSubscriptionReport::ltv($this->scopedCompanyId($request)));
    }

    public function revenue(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/customersubscriptionanalytics');

        return response()->json(['data' => CustomerSubscriptionReport::revenueByMonth($this->scopedCompanyId($request), (int) $request->integer('months', 6))]);
    }

    public function renewalDue(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/customersubscriptionanalytics');

        return response()->json(['data' => CustomerSubscriptionReport::renewalDue($this->scopedCompanyId($request), (int) $request->integer('days', 7))]);
    }

    private function scopedCompanyId(Request $request): ?int
    {
        $user = Auth::user();

        if ($user?->hasRole('superadmin')) {
            return $request->filled('company_id') ? $request->integer('company_id') : null;
        }

        return $user?->company_id ? (int) $user->company_id : null;
    }
}
