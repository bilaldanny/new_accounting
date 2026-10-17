<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\CustomerSubscriptionAnalyticsSnapshot as Snapshot;
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

        return $this->snapshot($request, 'summary', fn (?int $company) => CustomerSubscriptionReport::summary($company));
    }

    public function churn(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/customersubscriptionanalytics');

        $days = (int) $request->integer('days', 30);

        return $days === 30
            ? $this->snapshot($request, 'churn', fn (?int $company) => CustomerSubscriptionReport::churn($company, 30))
            : response()->json(CustomerSubscriptionReport::churn($this->scopedCompanyId($request), $days));
    }

    public function ltv(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/customersubscriptionanalytics');

        return $this->snapshot($request, 'ltv', fn (?int $company) => CustomerSubscriptionReport::ltv($company));
    }

    public function revenue(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/customersubscriptionanalytics');

        $months = (int) $request->integer('months', 6);

        return $months === 6
            ? $this->snapshot($request, 'revenue', fn (?int $company) => ['data' => CustomerSubscriptionReport::revenueByMonth($company, 6)])
            : response()->json(['data' => CustomerSubscriptionReport::revenueByMonth($this->scopedCompanyId($request), $months)]);
    }

    public function renewalDue(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/customersubscriptionanalytics');

        return response()->json(['data' => CustomerSubscriptionReport::renewalDue($this->scopedCompanyId($request), (int) $request->integer('days', 7))]);
    }

    /**
     * The default figures come from the day's snapshot (made here the first time, or by the daily command); the header says when.
     *
     * @param  \Closure(?int): mixed  $compute
     */
    private function snapshot(Request $request, string $kind, \Closure $compute): JsonResponse
    {
        $company = $this->scopedCompanyId($request);
        [$payload, $at] = Snapshot::remember($company, $kind, fn () => $compute($company));

        return response()->json($payload)->header('X-Snapshot-At', (string) $at);
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
