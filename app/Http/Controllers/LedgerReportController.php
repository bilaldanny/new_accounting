<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PaginatesReportRows;
use App\Http\Controllers\Concerns\ResolvesReportScope;
use App\Services\AccountLedgerReport;
use App\Services\TrialBalanceReport;
use App\Services\VoucherReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The accounts reports under Reports: the general ledger of any account, the trial balance and the
 * receipts and payments voucher report. The routes (routes/api.php) pass the report as a route default;
 * each report has its own menu permission (its page path). The response is the usual page of rows plus
 * `summary` (see PaginatesReportRows).
 *
 * The ledger and the trial balance work from one company's financial year and chart of accounts, so a
 * superadmin has to choose a company for them.
 */
class LedgerReportController extends Controller
{
    use PaginatesReportRows;
    use ResolvesReportScope;

    /**
     * Menu permission per report: the page path of the report.
     *
     * @var array<string, string>
     */
    public const PERMISSIONS = [
        'account-ledger' => '/report/account-ledger',
        'trial-balance' => '/report/trial-balance',
        'vouchers' => '/report/vouchers',
    ];

    public function index(Request $request, string $report): JsonResponse
    {
        abort_unless(array_key_exists($report, self::PERMISSIONS), 404);

        $this->authorizeMenuPermission(self::PERMISSIONS[$report]);

        $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'contact_id' => 'nullable|integer',
            'account_code' => 'nullable|string|max:40',
            'account_group' => 'nullable|integer|between:1,6',
            'voucher_type' => 'nullable|in:all,receipt,payment',
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
            'contact_id', 'account_group', 'voucher_type', 'start_date', 'end_date', 'search',
        ]);

        if ($report === 'vouchers') {
            $service = app(VoucherReport::class);
            $rows = $service->rows($companyId, $branchId, $filters);

            return $this->reportResponse($request, $rows, $service->summary($rows), VoucherReport::SORTABLE, 'voucher_date');
        }

        if ($companyId === null) {
            throw ValidationException::withMessages(['company_id' => ['Choose a company first.']]);
        }

        if ($report === 'trial-balance') {
            $service = app(TrialBalanceReport::class);
            $rows = $service->rows($companyId, $branchId, $filters);

            return $this->reportResponse($request, $rows, $service->summary($rows), TrialBalanceReport::SORTABLE, 'code', false);
        }

        $service = app(AccountLedgerReport::class);
        $statement = $service->statement($companyId, $branchId, $request->input('account_code'), $filters['start_date'] ?? null, $filters['end_date'] ?? null);
        $search = strtolower(trim((string) ($filters['search'] ?? '')));
        $rows = $search === ''
            ? $statement['rows']
            : $statement['rows']->filter(fn (array $row): bool => str_contains(strtolower($row['voucher_no'].' '.$row['ref_no'].' '.$row['description'].' '.$row['account_code']), $search))->values();

        return $this->reportResponse($request, $rows, ['account' => $statement['account']] + $statement['summary'], AccountLedgerReport::SORTABLE, 'voucher_date', false);
    }
}
