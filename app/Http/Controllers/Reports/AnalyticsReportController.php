<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Concerns\PaginatesReportRows;
use App\Http\Controllers\Concerns\ResolvesReportScope;
use App\Http\Controllers\Controller;
use App\Services\Reports\ActivitySummaryReport;
use App\Services\Reports\BackorderReport;
use App\Services\Reports\BatchExpiryReport;
use App\Services\Reports\BudgetVsActualReport;
use App\Services\Reports\CashCollectionReport;
use App\Services\Reports\CashFlowReport;
use App\Services\Reports\ChangeHistoryReport;
use App\Services\Reports\ConsolidatedBranchReport;
use App\Services\Reports\CostCenterAnalysisReport;
use App\Services\Reports\FinancialRatiosReport;
use App\Services\Reports\FsnReport;
use App\Services\Reports\PaymentAccountReport;
use App\Services\Reports\PaymentAgeReport;
use App\Services\Reports\PriceHistoryReport;
use App\Services\Reports\PurchasePriceTrendReport;
use App\Services\Reports\RegisterReport;
use App\Services\Reports\SalesDiscountReport;
use App\Services\Reports\SerialTraceabilityReport;
use App\Services\Reports\StockMovementHistoryReport;
use App\Services\Reports\SupplierPerformanceReport;
use App\Services\Reports\WarehouseStockReport;
use App\Services\Reports\WarehouseUsageReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The analytics reports (Reports & Analytics Phase 2). Each is a service with `rows()`, `summary()` and
 * SORTABLE, answered in the usual report shape (PaginatesReportRows); the routes (routes/api.php) pass the
 * report as a route default and each has its own menu permission, its page path.
 */
class AnalyticsReportController extends Controller
{
    use PaginatesReportRows;
    use ResolvesReportScope;

    /**
     * Menu permission per report: the page path of the report.
     *
     * @var array<string, string>
     */
    public const PERMISSIONS = [
        'sales-discount' => '/report/sales-discount',
        'purchase-price-trend' => '/report/purchase-price-trend',
        'supplier-performance' => '/report/supplier-performance',
        'backorder' => '/report/backorder',
        'fsn' => '/report/fsn',
        'cash-collection' => '/report/cash-collection',
        'payment-account' => '/report/payment-account',
        'payment-age' => '/report/payment-age',
        'consolidated-branch' => '/report/consolidated-branch',
        'financial-ratios' => '/report/financial-ratios',
        'cash-flow' => '/report/cash-flow',
        'register' => '/report/register',
        'activity-summary' => '/report/activity-summary',
        'change-history' => '/report/change-history',
        'price-history' => '/report/price-history',
        'cost-center-analysis' => '/report/cost-center-analysis',
        'budget-vs-actual' => '/report/budget-vs-actual',
        'warehouse-stock' => '/report/warehouse-stock',
        'stock-movement-history' => '/report/stock-movement-history',
        'warehouse-usage' => '/report/warehouse-usage',
        'serial-traceability' => '/report/serial-traceability',
        'batch-expiry' => '/report/batch-expiry',
    ];

    /**
     * @var array<string, class-string>
     */
    public const SERVICES = [
        'sales-discount' => SalesDiscountReport::class,
        'purchase-price-trend' => PurchasePriceTrendReport::class,
        'supplier-performance' => SupplierPerformanceReport::class,
        'backorder' => BackorderReport::class,
        'fsn' => FsnReport::class,
        'cash-collection' => CashCollectionReport::class,
        'payment-account' => PaymentAccountReport::class,
        'payment-age' => PaymentAgeReport::class,
        'consolidated-branch' => ConsolidatedBranchReport::class,
        'financial-ratios' => FinancialRatiosReport::class,
        'cash-flow' => CashFlowReport::class,
        'register' => RegisterReport::class,
        'activity-summary' => ActivitySummaryReport::class,
        'change-history' => ChangeHistoryReport::class,
        'price-history' => PriceHistoryReport::class,
        'cost-center-analysis' => CostCenterAnalysisReport::class,
        'budget-vs-actual' => BudgetVsActualReport::class,
        'warehouse-stock' => WarehouseStockReport::class,
        'stock-movement-history' => StockMovementHistoryReport::class,
        'warehouse-usage' => WarehouseUsageReport::class,
        'serial-traceability' => SerialTraceabilityReport::class,
        'batch-expiry' => BatchExpiryReport::class,
    ];

    /**
     * The reports that work from one company's books and so need a company chosen.
     *
     * @var list<string>
     */
    private const NEEDS_COMPANY = ['consolidated-branch', 'financial-ratios', 'cash-flow', 'cost-center-analysis', 'budget-vs-actual'];

    public function index(Request $request, string $report): JsonResponse
    {
        abort_unless(array_key_exists($report, self::PERMISSIONS), 404);

        $this->authorizeMenuPermission(self::PERMISSIONS[$report]);

        $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'product_id' => 'nullable|integer',
            'warehouse_id' => 'nullable|integer|min:0',
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

        if ($companyId === null && in_array($report, self::NEEDS_COMPANY, true)) {
            return response()->json(['data' => $this->paginateRows(collect(), 'id', true, $request), 'summary' => [], 'trash_count' => 0, 'needs_company' => true]);
        }

        $filters = $request->only(['product_id', 'warehouse_id', 'status', 'start_date', 'end_date', 'search']);
        $service = app(self::SERVICES[$report]);
        $rows = $service->rows($companyId, $branchId, $filters);

        return $this->reportResponse($request, $rows, $service->summary($rows, $companyId, $branchId, $filters), $service::SORTABLE, $service::DEFAULT_SORT, ! defined($service::class.'::DEFAULT_DESC') || $service::DEFAULT_DESC);
    }
}
