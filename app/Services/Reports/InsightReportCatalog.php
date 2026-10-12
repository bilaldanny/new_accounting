<?php

namespace App\Services\Reports;

use Illuminate\Support\Collection;

/**
 * The reports of the analytics wave under one key each: what they are called, which menu permission opens them, whether they need a
 * single company's books, and how to run them into rows + summary. The report controller serves them over the API and the report
 * scheduler and builder export run them through the same `run()`, so a scheduled report is exactly what the screen shows.
 */
class InsightReportCatalog
{
    /**
     * Report key => [title, menu permission path, needs one company].
     *
     * @var array<string, array{0: string, 1: string, 2: bool}>
     */
    public const REPORTS = [
        'profit-loss-comparative' => ['Comparative Profit & Loss', '/report/profit-loss-comparative', true],
        'balance-sheet-comparative' => ['Comparative Balance Sheet', '/report/balance-sheet-comparative', true],
        'cash-flow-indirect' => ['Cash Flow Statement (Indirect)', '/report/cash-flow-indirect', true],
        'sales-by-salesperson' => ['Sales by Salesperson', '/report/sales-by-salesperson', false],
        'sales-by-warehouse' => ['Sales by Warehouse', '/report/sales-by-warehouse', false],
        'sales-by-payment-method' => ['Sales by Payment Method', '/report/sales-by-payment-method', false],
        'profit-by-customer' => ['Profit by Customer', '/report/profit-by-customer', false],
        'profit-by-branch' => ['Profit by Branch', '/report/profit-by-branch', false],
        'profit-by-salesperson' => ['Profit by Salesperson', '/report/profit-by-salesperson', false],
        'profit-by-warehouse' => ['Profit by Warehouse', '/report/profit-by-warehouse', false],
        'commission-report' => ['Commission Report', '/report/commission-report', true],
        'target-achievement' => ['Target Achievement', '/report/target-achievement', true],
        'sales-forecast' => ['Sales Forecast', '/report/sales-forecast', false],
        'stock-take' => ['Stock Take Variance', '/report/stock-take', false],
        'advance' => ['Advances Held', '/report/advance', false],
        'withholding-tax' => ['Withholding Tax', '/report/withholding-tax', false],
        'tax-exemption' => ['Tax Exemptions Used', '/report/tax-exemption', false],
        'tax-liability' => ['Tax Liability Reconciliation', '/report/tax-liability', true],
        'fbr-audit-trail' => ['FBR Submission Audit Trail', '/report/fbr-audit-trail', false],
    ];

    /**
     * Rows, summary and the sort fields of a report.
     *
     * @param  array<string, mixed>  $filters
     * @return array{rows: Collection<int, array<string, mixed>>, summary: array<string, mixed>, sortable: array<string, string>, default_sort: string, descending: bool}
     */
    public function run(string $report, ?int $companyId, ?int $branchId, array $filters): array
    {
        $result = function (object $service, ?Collection $rows = null, ?array $summary = null) use ($companyId, $branchId, $filters): array {
            $rows ??= $service->rows($companyId, $branchId, $filters);

            return [
                'rows' => $rows,
                'summary' => $summary ?? $service->summary($rows, $companyId, $branchId, $filters),
                'sortable' => $service::SORTABLE,
                'default_sort' => $service::DEFAULT_SORT,
                'descending' => ! defined($service::class.'::DEFAULT_DESC') || $service::DEFAULT_DESC,
            ];
        };

        switch ($report) {
            case 'profit-loss-comparative':
            case 'balance-sheet-comparative':
                $service = app(ComparativeStatements::class);
                $built = $report === 'profit-loss-comparative'
                    ? $service->profitLoss((int) $companyId, $branchId, $filters)
                    : $service->balanceSheet((int) $companyId, $branchId, $filters);

                return ['rows' => $built['rows'], 'summary' => $built['summary'], 'sortable' => ComparativeStatements::SORTABLE, 'default_sort' => 'id', 'descending' => false];
            case 'cash-flow-indirect':
                return $result(app(IndirectCashFlowReport::class));
            case 'sales-by-salesperson':
            case 'sales-by-warehouse':
            case 'sales-by-payment-method':
                return $result(SalesByDimensionReport::for(substr($report, 9)));
            case 'profit-by-customer':
            case 'profit-by-branch':
            case 'profit-by-salesperson':
            case 'profit-by-warehouse':
                return $result(app(ProfitByDimensionReport::class)->forDimension(substr($report, 10)));
            case 'commission-report':
                return $result(app(CommissionTargetReport::class)->forMode('commission'));
            case 'target-achievement':
                return $result(app(CommissionTargetReport::class)->forMode('target'));
            case 'sales-forecast':
                return $result(app(SalesForecastReport::class));
            case 'stock-take':
                return $result(app(StockTakeReport::class));
            case 'advance':
                return $result(app(AdvanceReport::class));
            case 'withholding-tax':
                return $result(app(WithholdingTaxReport::class));
            case 'tax-exemption':
                return $result(app(TaxExemptionReport::class));
            case 'tax-liability':
                return $result(app(TaxLiabilityReport::class));
            case 'fbr-audit-trail':
                return $result(app(FbrAuditTrailReport::class));
        }

        abort(404);
    }
}
