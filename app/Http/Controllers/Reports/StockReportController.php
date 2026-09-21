<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\PaginatesReportRows;
use App\Http\Controllers\Concerns\ResolvesReportScope;
use App\Services\Reports\StockReport;
use App\Services\Reports\StockTransferReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The stock reports under Reports: stock on a day per product variation, and the stock transfer report.
 * The routes (routes/api.php) pass the report as a route default; each report has its own menu
 * permission (its page path). The response is the usual page of rows plus `summary` (see
 * PaginatesReportRows).
 */
class StockReportController extends Controller
{
    use PaginatesReportRows;
    use ResolvesReportScope;

    /**
     * Menu permission per report: the page path of the report.
     *
     * @var array<string, string>
     */
    public const PERMISSIONS = [
        'stock' => '/report/stock',
        'stock-transfer' => '/report/stock-transfer',
    ];

    public function index(Request $request, string $report): JsonResponse
    {
        abort_unless(array_key_exists($report, self::PERMISSIONS), 404);

        $this->authorizeMenuPermission(self::PERMISSIONS[$report]);

        $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'from_branch_id' => 'nullable|integer',
            'to_branch_id' => 'nullable|integer',
            'product_id' => 'nullable|integer',
            'brand_id' => 'nullable|integer',
            'category_id' => 'nullable|integer',
            'itemtype_id' => 'nullable|integer',
            'unit_id' => 'nullable|integer',
            'by_branch' => 'nullable|in:0,1,true,false',
            'status' => 'nullable|string|max:30',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'search' => 'nullable|string|max:200',
            'sort_by' => 'nullable|string',
            'sort_type' => 'nullable|in:asc,desc',
            'show_record' => 'nullable|integer|min:1|max:1000',
            'cur_page' => 'nullable|integer|min:1',
        ]);

        [$companyId, $branchId] = $this->reportScope($request);

        $filters = $request->only([
            'from_branch_id', 'to_branch_id', 'product_id', 'brand_id', 'category_id', 'itemtype_id', 'unit_id', 'by_branch', 'status', 'start_date', 'end_date', 'search',
        ]);

        if ($report === 'stock') {
            $service = app(StockReport::class);
            $rows = $service->rows($companyId, $branchId, $filters);

            return $this->reportResponse($request, $rows, $service->summary($rows), StockReport::SORTABLE, 'product_name', false);
        }

        $service = app(StockTransferReport::class);
        $rows = $service->rows($companyId, $branchId, $filters);

        return $this->reportResponse($request, $rows, $service->summary($rows), StockTransferReport::SORTABLE, 'transaction_date');
    }
}
