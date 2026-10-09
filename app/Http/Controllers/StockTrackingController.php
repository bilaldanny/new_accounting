<?php

namespace App\Http\Controllers;

use App\Services\StockTracking;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The Serials & Batches page: what is in stock by serial number and by batch, the pickers the sale, receiving and transfer
 * forms read, and the manual entries (opening stock, a return, a write-off) that no document makes.
 */
class StockTrackingController extends Controller
{
    private const EXPIRING_DAYS = 30;

    public function serials(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/stocktracking');

        $companyId = $this->companyId($request);
        $states = DB::query()->fromSub(StockTracking::serialStates(), 'x')
            ->join('products as p', 'p.id', '=', 'x.product_id')
            ->leftJoin('branches as b', 'b.id', '=', 'x.branch_id')
            ->where('x.company_id', $companyId)
            ->when($request->filled('product_id'), fn (Builder $q) => $q->where('x.product_id', $request->integer('product_id')))
            ->when($request->filled('branch_id'), fn (Builder $q) => $q->where('x.branch_id', $request->integer('branch_id')))
            ->when($request->filled('search'), fn (Builder $q) => $q->where('x.serial_no', 'like', '%'.$request->input('search').'%'))
            ->select(['x.id', 'x.serial_no', 'x.product_id', 'p.name as product_name', 'x.net', 'x.last_kind', 'x.branch_id', 'b.name as branch_name', 'x.last_moved_at'])
            ->orderByDesc('x.id');

        $status = (string) $request->input('status', '');
        $rows = $states->get()->map(function (object $row): array {
            return [
                'id' => (int) $row->id, 'serial_no' => $row->serial_no, 'product_id' => (int) $row->product_id, 'product_name' => $row->product_name,
                'status' => StockTracking::serialStatus($row), 'branch_id' => $row->branch_id === null ? null : (int) $row->branch_id,
                'branch_name' => $row->branch_name, 'last_moved_at' => $row->last_moved_at,
            ];
        })->when($status !== '', fn ($c) => $c->where('status', $status))->values();

        $perPage = min((int) ($request->input('show_record') ?: 25), 200);
        $page = max((int) $request->input('page', 1), 1);

        return response()->json(['data' => [
            'data' => $rows->forPage($page, $perPage)->values(), 'total' => $rows->count(), 'per_page' => $perPage, 'current_page' => $page,
            'counts' => $rows->countBy('status'),
        ]]);
    }

    public function batches(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/stocktracking');

        $companyId = $this->companyId($request);
        $today = now()->toDateString();
        $soon = now()->addDays(self::EXPIRING_DAYS)->toDateString();
        $state = (string) $request->input('expiry', '');

        $rows = DB::query()->fromSub(StockTracking::batchStock(), 'x')
            ->join('products as p', 'p.id', '=', 'x.product_id')
            ->leftJoin('branches as b', 'b.id', '=', 'x.branch_id')
            ->where('x.company_id', $companyId)
            ->when(! $request->boolean('include_empty'), fn (Builder $q) => $q->where('x.qty', '>', 0))
            ->when($request->filled('product_id'), fn (Builder $q) => $q->where('x.product_id', $request->integer('product_id')))
            ->when($request->filled('branch_id'), fn (Builder $q) => $q->where('x.branch_id', $request->integer('branch_id')))
            ->when($request->filled('search'), fn (Builder $q) => $q->where('x.batch_no', 'like', '%'.$request->input('search').'%'))
            ->when($state === 'expired', fn (Builder $q) => $q->whereNotNull('x.expiry_date')->whereDate('x.expiry_date', '<', $today))
            ->when($state === 'expiring', fn (Builder $q) => $q->whereNotNull('x.expiry_date')->whereDate('x.expiry_date', '>=', $today)->whereDate('x.expiry_date', '<=', $soon))
            ->select(['x.batch_id', 'x.batch_no', 'x.expiry_date', 'x.product_id', 'p.name as product_name', 'x.branch_id', 'b.name as branch_name', 'x.qty'])
            ->orderByRaw('x.expiry_date is null, x.expiry_date asc')->orderBy('x.batch_id')
            ->get()
            ->map(fn (object $row): array => [
                'batch_id' => (int) $row->batch_id, 'batch_no' => $row->batch_no, 'expiry_date' => $row->expiry_date === null ? null : substr((string) $row->expiry_date, 0, 10),
                'product_id' => (int) $row->product_id, 'product_name' => $row->product_name, 'branch_id' => (int) $row->branch_id, 'branch_name' => $row->branch_name,
                'qty' => round((float) $row->qty, 4), 'expiry_state' => $this->expiryState($row->expiry_date, $today, $soon),
            ]);

        return response()->json(['data' => $rows->values()]);
    }

    /**
     * The serials of a product that are in stock in a branch, for the picker on the sale and transfer forms.
     */
    public function availableSerials(Request $request): JsonResponse
    {
        $request->validate(['product_id' => 'required|integer', 'branch_id' => 'required|integer', 'search' => 'nullable|string|max:100']);

        $rows = DB::query()->fromSub(StockTracking::serialStates(), 'x')
            ->where('x.company_id', $this->companyId($request))
            ->where('x.product_id', $request->integer('product_id'))
            ->where('x.branch_id', $request->integer('branch_id'))
            ->where('x.net', '>=', 1)
            ->whereIn('x.last_kind', StockTracking::IN_STOCK_KINDS)
            ->when($request->filled('search'), fn (Builder $q) => $q->where('x.serial_no', 'like', '%'.$request->input('search').'%'))
            ->orderBy('x.serial_no')->limit(300)->pluck('x.serial_no');

        return response()->json($rows->values());
    }

    /**
     * The batches of a product that have stock in a branch, the earliest expiry first, for the picker on the sale and transfer
     * forms.
     */
    public function availableBatches(Request $request): JsonResponse
    {
        $request->validate(['product_id' => 'required|integer', 'branch_id' => 'required|integer']);

        $today = now()->toDateString();
        $soon = now()->addDays(self::EXPIRING_DAYS)->toDateString();
        $rows = DB::query()->fromSub(StockTracking::batchStock(), 'x')
            ->where('x.company_id', $this->companyId($request))
            ->where('x.product_id', $request->integer('product_id'))
            ->where('x.branch_id', $request->integer('branch_id'))
            ->where('x.qty', '>', 0)
            ->orderByRaw('x.expiry_date is null, x.expiry_date asc')->orderBy('x.batch_id')
            ->get(['x.batch_id', 'x.batch_no', 'x.expiry_date', 'x.qty'])
            ->map(fn (object $row): array => [
                'batch_id' => (int) $row->batch_id, 'batch_no' => $row->batch_no, 'expiry_date' => $row->expiry_date === null ? null : substr((string) $row->expiry_date, 0, 10),
                'qty' => round((float) $row->qty, 4), 'expiry_state' => $this->expiryState($row->expiry_date, $today, $soon),
            ]);

        return response()->json($rows->values());
    }

    public function registerSerials(Request $request, StockTracking $tracking): JsonResponse
    {
        $this->authorizeMenuPermission('/stocktracking/add');

        $companyId = $this->companyId($request);
        $data = $request->validate([
            'product_id' => 'required|integer', 'branch_id' => 'required|integer', 'serials' => 'required', 'note' => 'nullable|string|max:255',
        ]);
        $this->assertOwned($companyId, (int) $data['product_id'], (int) $data['branch_id']);
        $list = is_array($data['serials']) ? $data['serials'] : preg_split('/[\r\n,;]+/', (string) $data['serials']);

        $made = DB::transaction(fn (): int => $tracking->registerSerials($companyId, (int) $data['product_id'], null, (int) $data['branch_id'], array_map('strval', (array) $list), $data['note'] ?? null));

        return response()->json(['message' => "{$made} serial(s) registered", 'registered' => $made]);
    }

    public function writeOffSerial(Request $request, int $id, StockTracking $tracking): JsonResponse
    {
        $this->authorizeMenuPermission('/stocktracking/edit');

        $request->validate(['note' => 'nullable|string|max:255']);
        $this->assertSerialOwned($this->companyId($request), $id);
        $tracking->writeOffSerial($id, $request->input('note'));

        return response()->json(['message' => 'Serial written off']);
    }

    public function registerBatch(Request $request, StockTracking $tracking): JsonResponse
    {
        $this->authorizeMenuPermission('/stocktracking/add');

        $companyId = $this->companyId($request);
        $data = $request->validate([
            'product_id' => 'required|integer', 'branch_id' => 'required|integer', 'batch_no' => 'required|string|max:100',
            'expiry_date' => 'nullable|date', 'qty' => 'required|numeric|gt:0', 'note' => 'nullable|string|max:255',
        ]);
        $this->assertOwned($companyId, (int) $data['product_id'], (int) $data['branch_id']);

        DB::transaction(fn () => $tracking->registerBatch($companyId, (int) $data['product_id'], null, (int) $data['branch_id'], $data['batch_no'], $data['expiry_date'] ?? null, (float) $data['qty'], $data['note'] ?? null));

        return response()->json(['message' => 'Batch registered']);
    }

    public function writeOffBatch(Request $request, int $id, StockTracking $tracking): JsonResponse
    {
        $this->authorizeMenuPermission('/stocktracking/edit');

        $companyId = $this->companyId($request);
        $data = $request->validate(['branch_id' => 'required|integer', 'qty' => 'required|numeric|gt:0', 'note' => 'nullable|string|max:255']);

        if (! DB::table('stock_batches')->where('id', $id)->where('company_id', $companyId)->exists()) {
            abort(404);
        }

        $tracking->writeOffBatch($id, (int) $data['branch_id'], (float) $data['qty'], $data['note'] ?? null);

        return response()->json(['message' => 'Batch quantity written off']);
    }

    private function expiryState(mixed $expiry, string $today, string $soon): string
    {
        if ($expiry === null) {
            return 'none';
        }

        $day = substr((string) $expiry, 0, 10);

        return $day < $today ? 'expired' : ($day <= $soon ? 'expiring' : 'ok');
    }

    private function companyId(Request $request): int
    {
        $user = $request->user();
        $companyId = (int) ($user?->hasRole('superadmin') ? $request->integer('company_id') : $user?->company_id);

        if ($companyId === 0) {
            throw ValidationException::withMessages(['company_id' => ['Choose a company.']]);
        }

        return $companyId;
    }

    private function assertOwned(int $companyId, int $productId, int $branchId): void
    {
        $product = DB::table('products')->where('id', $productId)->where('company_id', $companyId)->whereNull('deleted_at')->exists();
        $branch = DB::table('branches')->where('id', $branchId)->where('company_id', $companyId)->exists();

        if (! $product || ! $branch) {
            throw ValidationException::withMessages(['product_id' => ['Choose a product and a branch of this company.']]);
        }
    }

    private function assertSerialOwned(int $companyId, int $serialId): void
    {
        if (! DB::table('stock_serials')->where('id', $serialId)->where('company_id', $companyId)->exists()) {
            abort(404);
        }
    }
}
