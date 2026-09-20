<?php

namespace App\Http\Controllers;

use App\Services\ExpenseReport;
use App\Services\PaymentListReport;
use App\Services\StockAdjustmentReport;
use App\Services\TransactionListReport;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use stdClass;

/**
 * The transaction list reports under Reports: purchase, purchase return, sell, sell return, purchase
 * payment, sell payment, stock adjustment and expense. The routes (routes/api.php) pass the report
 * as a route default; each report has its own menu permission (its page path).
 *
 * A response carries the requested page of rows (`data`, a Laravel paginator, so the table's
 * pagination and export work as everywhere else) and `summary`, the totals over the whole filtered
 * set.
 */
class TransactionReportController extends Controller
{
    /**
     * Menu permission per report: the page path of the report.
     *
     * @var array<string, string>
     */
    public const PERMISSIONS = [
        'purchase' => '/report/purchase',
        'purchase-return' => '/report/purchase-return',
        'sell' => '/report/sell',
        'sell-return' => '/report/sell-return',
        'purchase-payment' => '/report/purchase-payment',
        'sell-payment' => '/report/sell-payment',
        'stock-adjustment' => '/report/stock-adjustment',
        'expense' => '/report/expense',
    ];

    public function index(Request $request, string $report): JsonResponse
    {
        abort_unless(array_key_exists($report, self::PERMISSIONS), 404);

        $this->authorizeMenuPermission(self::PERMISSIONS[$report]);

        $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'contact_id' => 'nullable|integer',
            'account_id' => 'nullable|integer',
            'status' => 'nullable|string|max:30',
            'payment_status' => 'nullable|string|max:30',
            'method' => 'nullable|string|max:30',
            'adjustment_type' => 'nullable|string|max:30',
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
            'search' => 'nullable|string|max:200',
            'sort_by' => 'nullable|string',
            'sort_type' => 'nullable|in:asc,desc',
            'show_record' => 'nullable|integer|min:1|max:1000',
            'cur_page' => 'nullable|integer|min:1',
        ]);

        $user = Auth::user();
        $isSuperadmin = $user->hasRole('superadmin');

        if (! $isSuperadmin && ! $user->company_id) {
            abort(403);
        }

        $companyId = $isSuperadmin
            ? ($request->integer('company_id') ?: null)
            : (int) $user->company_id;
        $branchId = $user->branch_id && ! $isSuperadmin && ! $user->hasRole('companyadmin')
            ? (int) $user->branch_id
            : ($request->integer('branch_id') ?: null);

        $filters = $request->only([
            'contact_id', 'account_id', 'status', 'payment_status', 'method', 'adjustment_type', 'start_date', 'end_date', 'search',
        ]);

        [$query, $summary, $sortable, $defaultSort, $present] = $this->prepare($report, $companyId, $branchId, $filters);

        $sortBy = (string) $request->input('sort_by');
        $isKnownSort = array_key_exists($sortBy, $sortable);
        $sortColumn = $isKnownSort ? $sortable[$sortBy] : $sortable[$defaultSort];
        $sortDirection = $isKnownSort ? ($request->input('sort_type') === 'asc' ? 'asc' : 'desc') : 'desc';

        $query->orderByRaw("{$sortColumn} {$sortDirection}")->orderByDesc($this->idColumn($report));

        $showRecord = $request->integer('show_record', 10);
        $curPage = $request->integer('cur_page', 1);

        Paginator::currentPageResolver(fn () => $curPage);
        $result = $query->paginate($showRecord);

        if ($curPage > $result->lastPage()) {
            Paginator::currentPageResolver(fn () => $result->lastPage());
            $result = $query->paginate($showRecord);
        }

        $result->getCollection()->transform($present);

        return response()->json(['data' => $result, 'summary' => $summary, 'trash_count' => 0]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: Builder, 1: array<string, mixed>, 2: array<string, string>, 3: string, 4: callable}
     */
    private function prepare(string $report, ?int $companyId, ?int $branchId, array $filters): array
    {
        if (array_key_exists($report, TransactionListReport::KINDS)) {
            $service = app(TransactionListReport::class);

            return [
                $service->query($report, $companyId, $branchId, $filters),
                $service->summary($report, $companyId, $branchId, $filters),
                TransactionListReport::SORTABLE,
                'transaction_date',
                fn (stdClass $row): array => $this->presentTransaction($row),
            ];
        }

        if (array_key_exists($report, PaymentListReport::KINDS)) {
            $service = app(PaymentListReport::class);

            return [
                $service->query($report, $companyId, $branchId, $filters),
                $service->summary($report, $companyId, $branchId, $filters),
                PaymentListReport::SORTABLE,
                'paid_on',
                fn (stdClass $row): array => $this->presentPayment($row),
            ];
        }

        if ($report === 'stock-adjustment') {
            $service = app(StockAdjustmentReport::class);

            return [
                $service->query($companyId, $branchId, $filters),
                $service->summary($companyId, $branchId, $filters),
                StockAdjustmentReport::SORTABLE,
                'transaction_date',
                fn (stdClass $row): array => $this->presentAdjustment($row),
            ];
        }

        $service = app(ExpenseReport::class);

        return [
            $service->query($companyId, $branchId, $filters),
            $service->summary($companyId, $branchId, $filters),
            ExpenseReport::SORTABLE,
            'voucher_date',
            fn (stdClass $row): array => $this->presentExpense($row),
        ];
    }

    private function idColumn(string $report): string
    {
        return match (true) {
            array_key_exists($report, PaymentListReport::KINDS) => 'p.id',
            $report === 'expense' => 'd.id',
            default => 't.id',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function presentTransaction(stdClass $row): array
    {
        return [
            'id' => $row->id,
            'transaction_date' => substr((string) $row->transaction_date, 0, 10),
            'invoice_no' => $row->invoice_no,
            'sup_ref_no' => $row->sup_ref_no,
            'parent_invoice_no' => $row->parent_invoice_no,
            'contact_name' => TransactionListReport::contactName($row),
            'branch_name' => $row->branch_name,
            'company_name' => $row->company_name,
            'status' => $row->status,
            'payment_status' => $row->payment_status,
            'total_before_tax' => $this->money($row->total_before_tax),
            'tax_amount' => $this->money($row->tax_amount),
            'discount_amount' => $this->money($row->discount_amount),
            'shipping_charges' => $this->money($row->shipping_charges),
            'final_amount' => $this->money($row->final_amount),
            'paid' => $this->money($row->paid),
            'due' => $this->money($row->due),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPayment(stdClass $row): array
    {
        return [
            'id' => $row->id,
            'paid_on' => $row->paid_on,
            'payment_ref_no' => $row->payment_ref_no,
            'invoice_no' => $row->invoice_no,
            'sup_ref_no' => $row->sup_ref_no,
            'contact_name' => TransactionListReport::contactName($row),
            'branch_name' => $row->branch_name,
            'company_name' => $row->company_name,
            'method' => $row->method,
            'cheque_number' => $row->cheque_number,
            'bank_account_number' => $row->bank_account_number,
            'note' => $row->note,
            'invoice_amount' => $this->money($row->invoice_amount),
            'payment_status' => $row->payment_status,
            'amount' => $this->money($row->amount),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAdjustment(stdClass $row): array
    {
        return [
            'id' => $row->id,
            'transaction_date' => substr((string) $row->transaction_date, 0, 10),
            'invoice_no' => $row->invoice_no,
            'adjustment_type' => $row->adjustment_type,
            'status' => $row->status,
            'total_item' => (int) $row->total_item,
            'final_amount' => $this->money($row->final_amount),
            'additional_note' => $row->additional_note,
            'created_by_name' => StockAdjustmentReport::createdByName($row),
            'branch_name' => $row->branch_name,
            'company_name' => $row->company_name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentExpense(stdClass $row): array
    {
        return [
            'id' => $row->id,
            'voucher_id' => $row->voucher_id,
            'voucher_date' => substr((string) $row->voucher_date, 0, 10),
            'voucher_no' => $row->voucher_no,
            'ref_no' => $row->ref_no,
            'account_code' => $row->account_code,
            'account_name' => $row->account_name,
            'description' => $row->description,
            'comments' => $row->comments,
            'branch_name' => $row->branch_name,
            'company_name' => $row->company_name,
            'amount' => $this->money($row->amount),
        ];
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
