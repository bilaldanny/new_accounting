<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesReportScope;
use App\Services\CustomerGroupReport;
use App\Services\PartyAgingReport;
use App\Services\PartyOutstandingReport;
use App\Services\PartySummaryReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The party reports under Reports: customer and supplier outstanding, the customer & supplier summary,
 * the customer group report and customer and supplier aging. The routes (routes/api.php) pass the
 * report as a route default; each report has its own menu permission (its page path).
 *
 * These figures are worked out per contact rather than read off one table, so the whole filtered set
 * is built, then sorted and cut into the requested page here. The response has the same shape as the
 * transaction reports: the page of rows (`data`, a Laravel paginator) and `summary`, the totals over
 * the whole filtered set.
 */
class PartyReportController extends Controller
{
    use ResolvesReportScope;

    /**
     * Menu permission per report: the page path of the report.
     *
     * @var array<string, string>
     */
    public const PERMISSIONS = [
        'customer-outstanding' => '/report/customer-outstanding',
        'supplier-outstanding' => '/report/supplier-outstanding',
        'customer-supplier' => '/report/customer-supplier',
        'customer-group' => '/report/customer-group',
        'customer-aging' => '/report/customer-aging',
        'supplier-aging' => '/report/supplier-aging',
    ];

    public function index(Request $request, string $report): JsonResponse
    {
        abort_unless(array_key_exists($report, self::PERMISSIONS), 404);

        $this->authorizeMenuPermission(self::PERMISSIONS[$report]);

        $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'contact_id' => 'nullable|integer',
            'customer_group_id' => 'nullable|integer',
            'contact_type' => 'nullable|in:all,customer,supplier',
            'include_zero' => 'nullable|in:0,1,true,false',
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
            'contact_id', 'customer_group_id', 'contact_type', 'include_zero', 'start_date', 'end_date', 'search',
        ]);

        [$rows, $summary, $sortable, $defaultSort] = $this->prepare($report, $companyId, $branchId, $filters);

        $sortBy = (string) $request->input('sort_by');
        $isKnownSort = array_key_exists($sortBy, $sortable);
        $field = $isKnownSort ? $sortable[$sortBy] : $sortable[$defaultSort];
        $descending = $isKnownSort ? $request->input('sort_type') !== 'asc' : true;

        return response()->json([
            'data' => $this->paginate($rows, $field, $descending, $request),
            'summary' => $summary,
            'trash_count' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: Collection<int, array<string, mixed>>, 1: array<string, mixed>, 2: array<string, string>, 3: string}
     */
    private function prepare(string $report, ?int $companyId, ?int $branchId, array $filters): array
    {
        if ($report === 'customer-outstanding' || $report === 'supplier-outstanding') {
            $service = app(PartyOutstandingReport::class);
            $rows = $service->rows(str_replace('-outstanding', '', $report), $companyId, $branchId, $filters);

            return [$rows, $service->summary($rows, $filters['end_date'] ?? null), PartyOutstandingReport::SORTABLE, 'balance'];
        }

        if ($report === 'customer-supplier') {
            $service = app(PartySummaryReport::class);
            $rows = $service->rows($companyId, $branchId, $filters);

            return [$rows, $service->summary($rows), PartySummaryReport::SORTABLE, 'sales'];
        }

        if ($report === 'customer-group') {
            $service = app(CustomerGroupReport::class);
            $rows = $service->rows($companyId, $branchId, $filters);

            return [$rows, $service->summary($rows), CustomerGroupReport::SORTABLE, 'net_sales'];
        }

        $service = app(PartyAgingReport::class);
        $rows = $service->rows(str_replace('-aging', '', $report), $companyId, $branchId, $filters);

        return [$rows, $service->summary($rows, $filters['end_date'] ?? null), PartyAgingReport::SORTABLE, 'total'];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginate(Collection $rows, string $field, bool $descending, Request $request): LengthAwarePaginator
    {
        $sorted = $rows->sort(function (array $a, array $b) use ($field, $descending): int {
            $left = $a[$field] ?? null;
            $right = $b[$field] ?? null;
            $order = is_string($left) || is_string($right)
                ? strcasecmp((string) $left, (string) $right)
                : $left <=> $right;

            return ($descending ? -$order : $order) ?: ($a['id'] <=> $b['id']);
        })->values();

        $perPage = $request->integer('show_record', 10);
        $lastPage = max((int) ceil($sorted->count() / $perPage), 1);
        $page = min(max($request->integer('cur_page', 1), 1), $lastPage);

        return new LengthAwarePaginator(
            $sorted->forPage($page, $perPage)->values(),
            $sorted->count(),
            $perPage,
            $page,
            ['path' => $request->url()],
        );
    }
}
