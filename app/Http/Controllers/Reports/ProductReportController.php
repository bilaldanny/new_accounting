<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\PaginatesReportRows;
use App\Http\Controllers\Concerns\ResolvesReportScope;
use App\Services\Reports\ItemProfitReport;
use App\Services\Reports\ItemTradeReport;
use App\Services\Reports\ProductLineReport;
use App\Services\Reports\ProductSellSummaryReport;
use App\Services\Reports\PurchaseSaleSummary;
use App\Services\Reports\TaxReport;
use App\Services\Reports\TrendingProductsReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The product and tax reports under Reports: product purchase and sell lines, sell summary per product,
 * item profit and loss, the particular item purchase and sell reports, the purchase & sale summary, the
 * tax report and trending products. The routes (routes/api.php) pass the report as a route default; each
 * report has its own menu permission (its page path). The response is the usual page of rows plus
 * `summary` (see PaginatesReportRows).
 */
class ProductReportController extends Controller
{
    use PaginatesReportRows;
    use ResolvesReportScope;

    /**
     * Menu permission per report: the page path of the report.
     *
     * @var array<string, string>
     */
    public const PERMISSIONS = [
        'product-purchase' => '/report/product-purchase',
        'product-sell' => '/report/product-sell',
        'product-sell-summary' => '/report/product-sell-summary',
        'item-profit-loss' => '/report/item-profit-loss',
        'item-purchase' => '/report/item-purchase',
        'item-sell' => '/report/item-sell',
        'purchase-sale' => '/report/purchase-sale',
        'tax' => '/report/tax',
        'trending-products' => '/report/trending-products',
    ];

    public function index(Request $request, string $report): JsonResponse
    {
        abort_unless(array_key_exists($report, self::PERMISSIONS), 404);

        $this->authorizeMenuPermission(self::PERMISSIONS[$report]);

        $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'contact_id' => 'nullable|integer',
            'product_id' => 'nullable|integer',
            'brand_id' => 'nullable|integer',
            'category_id' => 'nullable|integer',
            'itemtype_id' => 'nullable|integer',
            'top' => 'nullable|integer|min:1|max:500',
            'tax_side' => 'nullable|in:all,input,output',
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
            'contact_id', 'product_id', 'brand_id', 'category_id', 'itemtype_id', 'top', 'tax_side', 'start_date', 'end_date', 'search',
        ]);

        [$rows, $summary, $sortable, $defaultSort, $descending] = $this->prepare($report, $companyId, $branchId, $filters);

        return $this->reportResponse($request, $rows, $summary, $sortable, $defaultSort, $descending);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: Collection<int, array<string, mixed>>, 1: array<string, mixed>, 2: array<string, string>, 3: string, 4: bool}
     */
    private function prepare(string $report, ?int $companyId, ?int $branchId, array $filters): array
    {
        switch ($report) {
            case 'product-purchase':
            case 'product-sell':
                $service = app(ProductLineReport::class);
                $rows = $service->rows(str_replace('product-', '', $report), $companyId, $branchId, $filters);

                return [$rows, $service->summary($rows), ProductLineReport::SORTABLE, 'transaction_date', true];

            case 'product-sell-summary':
                $service = app(ProductSellSummaryReport::class);
                $rows = $service->rows($companyId, $branchId, $filters);

                return [$rows, $service->summary($rows), ProductSellSummaryReport::SORTABLE, 'net_amount', true];

            case 'item-profit-loss':
                $service = app(ItemProfitReport::class);
                $rows = $service->rows($companyId, $branchId, $filters);

                return [$rows, $service->summary($rows), ItemProfitReport::SORTABLE, 'transaction_date', true];

            case 'item-purchase':
            case 'item-sell':
                $service = app(ItemTradeReport::class);
                $rows = $service->rows(str_replace('item-', '', $report), $companyId, $branchId, $filters);

                return [$rows, $service->summary($rows), ItemTradeReport::SORTABLE, 'amount', true];

            case 'purchase-sale':
                $service = app(PurchaseSaleSummary::class);
                $built = $service->build($companyId, $branchId, $filters);

                return [$built['rows'], ['count' => $built['rows']->count()] + $built['totals'], PurchaseSaleSummary::SORTABLE, 'id', false];

            case 'tax':
                $service = app(TaxReport::class);
                $rows = $service->rows($companyId, $branchId, $filters);

                return [$rows, $service->summary($rows), TaxReport::SORTABLE, 'transaction_date', true];

            default:
                $service = app(TrendingProductsReport::class);
                $rows = $service->rows($companyId, $branchId, $filters);

                return [$rows, $service->summary($rows), TrendingProductsReport::SORTABLE, 'rank', false];
        }
    }
}
