<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Reports\AnalyticsReportController;
use App\Http\Controllers\Reports\FinancialReportController;
use App\Http\Controllers\Reports\LedgerReportController;
use App\Http\Controllers\Reports\PartyReportController;
use App\Http\Controllers\Reports\ProductReportController;
use App\Http\Controllers\Reports\StockReportController;
use App\Http\Controllers\Reports\TransactionReportController;
use App\Services\Reports\ReportScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Saved report filters and favourite reports: personal to the signed-in user (a row is only ever read or changed by its owner),
 * so they need no menu permission of their own; opening the report still does. Which filter fields a report keeps is the page's
 * business; only the known filter field names are stored, and nothing in them is trusted when the report runs, the report validates
 * its own input.
 */
class ReportFilterController extends Controller
{
    private const FIELDS = [
        'start_date', 'end_date', 'compare_start', 'compare_end', 'period', 'subject', 'contact_type', 'tax_side', 'status', 'search', 'contact_id',
        'customer_group_id', 'product_id', 'brand_id', 'category_id', 'warehouse_id', 'top', 'by_branch', 'include_zero', 'payment_status', 'method',
        'adjustment_type', 'account_code', 'account_group', 'voucher_type', 'from_branch_id', 'to_branch_id', 'branch_id',
    ];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['report_key' => 'nullable|string|max:80']);

        return response()->json(['data' => DB::table('report_filters')->where('user_id', Auth::id())
            ->when(isset($data['report_key']), fn ($q) => $q->where('report_key', $data['report_key']))
            ->orderByDesc('is_favourite')->orderBy('name')->get()
            ->map(fn (object $row): array => $this->present($row))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'report_key' => ['required', 'string', 'max:80', Rule::in($this->reportKeys())],
            'name' => 'required|string|min:2|max:120',
            'filters' => 'present|array',
            'is_favourite' => 'nullable|boolean',
        ]);

        $filters = array_intersect_key($data['filters'], array_flip(self::FIELDS));
        $filters = array_filter($filters, fn (mixed $value): bool => is_scalar($value) || $value === null);

        DB::table('report_filters')->updateOrInsert(
            ['user_id' => Auth::id(), 'report_key' => $data['report_key'], 'name' => $data['name']],
            ['company_id' => Auth::user()->company_id, 'filters' => json_encode($filters), 'is_favourite' => (bool) ($data['is_favourite'] ?? false), 'updated_at' => now(), 'created_at' => now()],
        );

        $row = DB::table('report_filters')->where('user_id', Auth::id())->where('report_key', $data['report_key'])->where('name', $data['name'])->first();

        return response()->json(['message' => 'Successfully Saved', 'data' => $this->present($row)], 201);
    }

    public function favourite(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['is_favourite' => 'required|boolean']);
        $row = DB::table('report_filters')->where('user_id', Auth::id())->where('id', $id)->first();
        abort_if($row === null, 404);

        DB::table('report_filters')->where('id', $id)->update(['is_favourite' => $data['is_favourite'], 'updated_at' => now()]);

        return response()->json(['message' => 'Successfully Updated']);
    }

    public function destroy(int $id): JsonResponse
    {
        $row = DB::table('report_filters')->where('user_id', Auth::id())->where('id', $id)->first();
        abort_if($row === null, 404);

        DB::table('report_filters')->where('id', $id)->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }

    /**
     * Every report key the application knows, so a filter cannot be saved under an invented report.
     *
     * @return list<string>
     */
    private function reportKeys(): array
    {
        $keys = [];

        foreach ([
            TransactionReportController::PERMISSIONS, ProductReportController::PERMISSIONS,
            StockReportController::PERMISSIONS, LedgerReportController::PERMISSIONS,
            FinancialReportController::PERMISSIONS, AnalyticsReportController::PERMISSIONS,
            PartyReportController::PERMISSIONS, array_fill_keys(array_keys(app(ReportScheduler::class)->available()), ''),
        ] as $set) {
            $keys = array_merge($keys, array_keys($set));
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id, 'report_key' => $row->report_key, 'name' => $row->name,
            'filters' => json_decode((string) $row->filters, true) ?: [], 'is_favourite' => (bool) $row->is_favourite,
        ];
    }
}
