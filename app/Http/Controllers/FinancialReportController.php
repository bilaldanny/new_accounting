<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PaginatesReportRows;
use App\Http\Controllers\Concerns\ResolvesReportScope;
use App\Services\BalanceSheetReport;
use App\Services\ProfitLossReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The financial statements under Reports: the profit and loss of a period and the balance sheet on a day.
 * The routes (routes/api.php) pass the report as a route default; each report has its own menu
 * permission (its page path). The response is the usual page of rows plus `summary` (see
 * PaginatesReportRows); the rows are the lines of the statement in reading order.
 *
 * Both work from one company's chart of accounts and financial year, so a superadmin has to choose a
 * company.
 */
class FinancialReportController extends Controller
{
    use PaginatesReportRows;
    use ResolvesReportScope;

    /**
     * Menu permission per report: the page path of the report.
     *
     * @var array<string, string>
     */
    public const PERMISSIONS = [
        'profit-loss' => '/report/profit-loss',
        'balance-sheet' => '/report/balance-sheet',
    ];

    public function index(Request $request, string $report): JsonResponse
    {
        abort_unless(array_key_exists($report, self::PERMISSIONS), 404);

        $this->authorizeMenuPermission(self::PERMISSIONS[$report]);

        $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'sort_by' => 'nullable|string',
            'sort_type' => 'nullable|in:asc,desc',
            'show_record' => 'nullable|integer|min:1|max:1000',
            'cur_page' => 'nullable|integer|min:1',
        ]);

        [$companyId, $branchId] = $this->reportScope($request);

        if ($companyId === null) {
            throw ValidationException::withMessages(['company_id' => ['Choose a company first.']]);
        }

        $filters = $request->only(['start_date', 'end_date']);

        if ($report === 'profit-loss') {
            $built = app(ProfitLossReport::class)->build($companyId, $branchId, $filters);

            return $this->reportResponse($request, $built['rows'], $built['summary'], ProfitLossReport::SORTABLE, 'id', false);
        }

        $built = app(BalanceSheetReport::class)->build($companyId, $branchId, $filters);

        return $this->reportResponse($request, $built['rows'], $built['summary'], BalanceSheetReport::SORTABLE, 'id', false);
    }
}
