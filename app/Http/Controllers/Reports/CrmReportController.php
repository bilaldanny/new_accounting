<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\CrmAnalyticsReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * CRM Analytics: the one master-list menu item that bundles five angles (Conversion, Funnel,
 * Pipeline, Sales Activity, Salesperson Performance) — Funnel and Pipeline are one stage-ordered
 * count/value breakdown here (the same shape the Kanban board already groups by), since the docx names
 * them as sub-angles of a single menu item, not separate pages. Routed under the CRM menu
 * (`/crmanalytics`), not the generic `/report/*` family: those reports are transaction-list-shaped
 * (paginated rows + a summary strip) and driven by the shared `report/index.vue` + `report.ts` (965
 * lines, 29 existing report keys, built around party/product/stock filters); funnel and leaderboard
 * data doesn't fit that paginated-row-list model, and shoehorning it in risked regressions across
 * every existing report. This still follows the Reports module's *backend* convention (a Service
 * class per computation, JSON straight out), just with its own small frontend page instead.
 */
class CrmReportController extends Controller
{
    public function conversion(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/crmanalytics');

        return response()->json(CrmAnalyticsReport::conversion($this->scopedCompanyId($request)));
    }

    public function pipeline(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/crmanalytics');

        return response()->json(CrmAnalyticsReport::pipeline($this->scopedCompanyId($request)));
    }

    public function salesActivity(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/crmanalytics');

        $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        return response()->json(CrmAnalyticsReport::salesActivity(
            $this->scopedCompanyId($request),
            $request->string('start_date')->toString() ?: null,
            $request->string('end_date')->toString() ?: null,
        ));
    }

    public function salespersonPerformance(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/crmanalytics');

        return response()->json(['data' => CrmAnalyticsReport::salespersonPerformance($this->scopedCompanyId($request))]);
    }

    /**
     * The superadmin may pick a company (or see all); everyone else is locked to their own.
     */
    private function scopedCompanyId(Request $request): ?int
    {
        $user = Auth::user();

        if ($user?->hasRole('superadmin')) {
            return $request->filled('company_id') ? $request->integer('company_id') : null;
        }

        return $user?->company_id ? (int) $user->company_id : null;
    }
}
