<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Concerns\PaginatesReportRows;
use App\Http\Controllers\Concerns\ResolvesReportScope;
use App\Http\Controllers\Controller;
use App\Services\Reports\InsightReportCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The analytics wave of reports (comparative statements, indirect cash flow, sales and profit by salesperson / warehouse / customer /
 * branch / payment method, commission and target, forecast, stock take, advances, withholding, exemptions, tax liability, FBR audit
 * trail). One route per report (routes/api.php) passes the key as a route default; every report has its own menu permission, its
 * page path. Rows and totals come from InsightReportCatalog, the same call the report scheduler and the report builder export use.
 * Company and branch come from the signed-in user (ResolvesReportScope), never from a stored record.
 */
class InsightReportController extends Controller
{
    use PaginatesReportRows;
    use ResolvesReportScope;

    public function index(Request $request, string $report): JsonResponse
    {
        abort_unless(array_key_exists($report, InsightReportCatalog::REPORTS), 404);

        $this->authorizeMenuPermission(InsightReportCatalog::REPORTS[$report][1]);

        $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'compare_start' => 'nullable|date_format:Y-m-d',
            'compare_end' => 'nullable|date_format:Y-m-d',
            'period' => 'nullable|date_format:Y-m',
            'subject' => 'nullable|in:agent,user,cashier',
            'contact_type' => 'nullable|in:all,customer,supplier',
            'tax_side' => 'nullable|in:all,input,output,receivable,payable',
            'status' => 'nullable|string|max:30',
            'search' => 'nullable|string|max:200',
            'sort_by' => 'nullable|string',
            'sort_type' => 'nullable|in:asc,desc',
            'show_record' => 'nullable|integer|min:1|max:1000',
            'cur_page' => 'nullable|integer|min:1',
        ]);

        [$companyId, $branchId] = $this->reportScope($request);

        if ($companyId === null && InsightReportCatalog::REPORTS[$report][2]) {
            return response()->json(['data' => $this->paginateRows(collect(), 'id', true, $request), 'summary' => [], 'trash_count' => 0, 'needs_company' => true]);
        }

        $filters = $request->only(['start_date', 'end_date', 'compare_start', 'compare_end', 'period', 'subject', 'contact_type', 'tax_side', 'status', 'search']);
        $built = app(InsightReportCatalog::class)->run($report, $companyId, $branchId, $filters);

        return $this->reportResponse($request, $built['rows'], $built['summary'], $built['sortable'], $built['default_sort'], $built['descending']);
    }
}
