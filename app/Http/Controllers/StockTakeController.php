<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\StockTake;
use App\Models\StockTakeLine;
use App\Services\StockTaking;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class StockTakeController extends Controller
{
    use HandlesIndexAndBulkDelete;

    /**
     * What the counting endpoints tell the caller for each refusal reason.
     *
     * @var array<string, string>
     */
    private const REJECTIONS = [
        'not_draft' => 'This stock take is already completed.',
        'nothing_counted' => 'Enter at least one counted quantity first.',
    ];

    public function __construct(private readonly StockTaking $stockTaking) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->listQuery(StockTake::query(), $request)
            ->when($request->filled('company_id'), fn (Builder $q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('branch_id'), fn (Builder $q) => $q->where('branch_id', $request->integer('branch_id')));

        $takes = $this->paginateSorted($query, $this->withSafeSort($request));

        $takes->getCollection()->transform(fn (StockTake $take) => $this->withNames($take));

        $trash_count = StockTake::onlyTrashed()->visibleToCurrentUser()->count();

        return response()->json(['data' => $takes, 'trash_count' => $trash_count]);
    }

    /**
     * Opens a draft count sheet for a branch, freezing its system stock.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/stocktake/add');

        $user = Auth::user();
        $companyId = $user?->hasRole('superadmin')
            ? ($request->filled('company_id') ? $request->integer('company_id') : null)
            : ($user?->company_id ? (int) $user->company_id : null);

        $request->validate([
            'company_id' => $user?->hasRole('superadmin') ? 'required|integer|exists:companies,id' : 'nullable',
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'count_date' => 'required|date_format:Y-m-d',
            'note' => 'nullable|string|max:500',
            'category_id' => 'nullable|integer',
            'subcategory_id' => 'nullable|integer',
            'itemtype_id' => 'nullable|integer',
            'brand_id' => 'nullable|integer',
            'only_in_stock' => 'nullable|boolean',
        ]);

        $branchId = $request->integer('branch_id');

        if ($user?->branch_id && ! $user->hasRole('companyadmin') && ! $user->hasRole('superadmin') && (int) $user->branch_id !== $branchId) {
            throw ValidationException::withMessages(['branch_id' => ['You can only count your own branch.']]);
        }

        try {
            $take = $this->stockTaking->open(
                (int) $companyId,
                $branchId,
                $request->string('count_date')->toString(),
                filled($request->note) ? trim((string) $request->note) : null,
                $request->only(['category_id', 'subcategory_id', 'itemtype_id', 'brand_id', 'only_in_stock']),
                Auth::id(),
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved', 'id' => $take->id, 'reference' => $take->reference]);
    }

    /**
     * The sheet with its lines (name, sku, system and counted quantity, difference) and a summary.
     */
    public function show($id): JsonResponse
    {
        $take = StockTake::query()
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'branch:id,name', 'adjustment:id,invoice_no'])
            ->find($id);

        if ($take === null) {
            abort(404);
        }

        $lines = $take->lines()
            ->with(['product:id,name,sku', 'productdetail:id,product_id,name,sku,variation_name', 'unit:id,name,short_name'])
            ->orderBy('id')
            ->get()
            ->map(fn (StockTakeLine $line): array => [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'variation_id' => $line->variation_id,
                'name' => $line->productdetail?->name ?: $line->product?->name,
                'sku' => $line->productdetail?->sku ?: $line->product?->sku,
                'unit_name' => $line->unit?->short_name ?: $line->unit?->name,
                'system_qty' => $line->system_qty,
                'counted_qty' => $line->counted_qty,
                'difference' => $line->difference(),
            ])
            ->values();

        $payload = $this->withNames($take)->toArray();
        $payload['adjustment_invoice_no'] = $take->adjustment?->invoice_no;
        $payload['lines'] = $lines->all();
        $payload['summary'] = [
            'total_lines' => $lines->count(),
            'counted_lines' => $lines->whereNotNull('counted_qty')->count(),
            'differing_lines' => $lines->filter(fn (array $line): bool => $line['difference'] !== null && abs($line['difference']) >= 0.01)->count(),
        ];

        return response()->json($payload);
    }

    /**
     * Saves counted quantities: `counts` maps a line id to the quantity found (null clears it).
     */
    public function counts(Request $request, $id): JsonResponse
    {
        $this->authorizeMenuPermission('/stocktake/count');

        $take = $this->findVisible($id);

        $data = $request->validate([
            'counts' => 'required|array|min:1',
            'counts.*' => 'nullable|numeric|min:0|max:9999999.99|decimal:0,4',
        ]);

        return $this->step(function () use ($take, $data): void {
            $this->stockTaking->saveCounts($take, $data['counts']);
        });
    }

    /**
     * Closes the sheet, writing a stock adjustment for the differences.
     */
    public function complete($id): JsonResponse
    {
        $this->authorizeMenuPermission('/stocktake/complete');

        $take = $this->findVisible($id);

        $response = $this->step(function () use ($take): void {
            $this->stockTaking->complete($take);
        });

        if ($response->getStatusCode() === 200) {
            $take->refresh();

            return response()->json(['message' => 'Successfully Saved', 'adjustment_id' => $take->adjustment_id]);
        }

        return $response;
    }

    public function destroy($id): JsonResponse
    {
        if (deletepermission('/stocktake/delete')) {
            StockTake::query()->visibleToCurrentUser()->where('status', StockTake::STATUS_DRAFT)->find($id)?->delete();

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    /**
     * Only draft sheets can be deleted: a completed one is the record of an adjustment.
     */
    public function bulk_delete(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/stocktake/delete', 'Successfully Deleted', function () use ($request) {
            StockTake::query()
                ->visibleToCurrentUser()
                ->where('status', StockTake::STATUS_DRAFT)
                ->whereIn('id', (array) $request->all())
                ->delete();
        });
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/stocktake/delete', 'Successfully Deleted', function () use ($request) {
            StockTake::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->forceDelete();
        });
    }

    public function restore_records(Request $request): JsonResponse
    {
        return $this->guardedBulkAction('/stocktake/restore', 'Successfully Restored', function () use ($request) {
            StockTake::query()
                ->onlyTrashed()
                ->visibleToCurrentUser()
                ->whereIn('id', (array) $request->all())
                ->restore();
        });
    }

    public function trash(Request $request): JsonResponse
    {
        $query = $this->listQuery(StockTake::onlyTrashed(), $request);

        $takes = $this->paginateSorted($query, $this->withSafeSort($request));

        $takes->getCollection()->transform(fn (StockTake $take) => $this->withNames($take));

        return response()->json(['data' => $takes]);
    }

    /**
     * Runs a counting step and turns a refusal into a 422 with its reason; a stock adjustment that cannot
     * be applied keeps its own validation error.
     *
     * @param  callable(): void  $step
     */
    private function step(callable $step): JsonResponse
    {
        try {
            $step();
        } catch (RuntimeException $e) {
            return response()->json(['message' => self::REJECTIONS[$e->getMessage()] ?? 'The stock take could not be saved.', 'reason' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    private function findVisible(mixed $id): StockTake
    {
        $take = StockTake::query()->visibleToCurrentUser()->find($id);

        if ($take === null) {
            abort(404);
        }

        return $take;
    }

    private function withNames(StockTake $take): StockTake
    {
        $take->company_name = $take->company?->name;
        $take->branch_name = $take->branch?->name;

        return $take;
    }

    /**
     * @param  Builder<StockTake>  $query
     * @return Builder<StockTake>
     */
    private function listQuery($query, Request $request)
    {
        $status = $request->status ?? 'all';
        $search = trim((string) ($request->search ?? ''));

        return $query
            ->visibleToCurrentUser()
            ->with(['company:id,name', 'branch:id,name'])
            ->withCount([
                'lines',
                'lines as counted_lines_count' => fn (Builder $q) => $q->whereNotNull('counted_qty'),
            ])
            ->when(in_array($status, [StockTake::STATUS_DRAFT, StockTake::STATUS_COMPLETED], true), function ($q) use ($status) {
                $q->where('status', $status);
            })
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('reference', 'like', "%{$search}%")
                        ->orWhere('note', 'like', "%{$search}%");
                });
            });
    }

    /**
     * Sorting only by a real column, in a real direction, whatever the request says.
     */
    private function withSafeSort(Request $request): Request
    {
        if (! in_array($request->input('sort_by'), StockTake::SORTABLE, true)) {
            $request->merge(['sort_by' => 'created_at']);
        }

        if (! in_array($request->input('sort_type'), ['asc', 'desc'], true)) {
            $request->merge(['sort_type' => 'desc']);
        }

        return $request;
    }
}
