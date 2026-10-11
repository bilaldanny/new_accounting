<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\PurchaseLine;
use App\Models\SellLine;
use App\Models\Transaction;
use App\Models\WarehouseTask;
use App\Models\WarehouseTaskLine;
use App\Services\StockMovements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pick, pack and put-away work lists. A pick or pack list is made from a posted sale, a put-away list from a purchase or
 * receiving note; each line is worked off quantity by quantity (and, for put-away, given a location), then the list is
 * completed. The lists record the work: stock moves through the issue and receiving notes exactly as before.
 */
class WarehouseTaskController extends Controller
{
    use ScopesToCompany;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/warehousetask');

        $rows = WarehouseTask::query()
            ->visibleToCurrentUser()
            ->withCount('lines')
            ->when($request->filled('type') && $request->input('type') !== 'all', fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('status') && $request->input('status') !== 'all', fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 25), 100));

        return response()->json(['data' => $rows]);
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/warehousetask');

        $task = WarehouseTask::query()->visibleToCurrentUser()->with(['lines.product:id,name,sku'])->findOrFail($id);

        return response()->json($task);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/warehousetask/add');

        $data = $request->validate([
            'type' => 'required|in:'.implode(',', WarehouseTask::TYPES),
            'transaction_id' => 'required|integer',
            'warehouse_id' => 'nullable|integer',
            'assigned_to' => 'nullable|integer',
            'note' => 'nullable|string|max:1000',
        ]);

        $source = Transaction::query()->visibleToCurrentUser()->findOrFail($data['transaction_id']);
        $isSale = $source->type === Transaction::TYPE_SELL && ! in_array($source->status, Transaction::UNPOSTED_SELL_STATUSES, true);
        $isReceipt = in_array($source->type, [Transaction::TYPE_PURCHASE, Transaction::TYPE_RECEIVING_NOTE], true) && $source->status !== 'draft';

        if ($data['type'] === 'putaway' ? ! $isReceipt : ! $isSale) {
            throw ValidationException::withMessages(['transaction_id' => [$data['type'] === 'putaway'
                ? 'A put-away list is made from a purchase or a receiving note.'
                : 'A pick or pack list is made from a posted sale.']]);
        }

        $lines = $data['type'] === 'putaway'
            ? PurchaseLine::query()->where('transaction_id', $source->id)->get(['product_id', 'variation_id', 'quantity', 'quantity_received', 'packing_qty'])
                ->map(fn ($line): array => ['product_id' => $line->product_id, 'variation_id' => $line->variation_id, 'quantity' => (float) ($line->quantity_received ?: $line->quantity) * max((float) $line->packing_qty, 1)])
            : SellLine::query()->where('transaction_id', $source->id)->get(['product_id', 'variation_id', 'quantity', 'quantity_returned', 'packing_qty'])
                ->map(fn ($line): array => ['product_id' => $line->product_id, 'variation_id' => $line->variation_id, 'quantity' => ((float) $line->quantity - (float) $line->quantity_returned) * max((float) $line->packing_qty, 1)]);

        $lines = $lines->filter(fn (array $line): bool => $line['quantity'] > 0 && StockMovements::productKeepsStock((int) $line['product_id']))->values();

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['transaction_id' => ['That document has no stocked lines to work on.']]);
        }

        $task = DB::transaction(function () use ($data, $source, $lines): WarehouseTask {
            $task = WarehouseTask::query()->create([
                'company_id' => $source->company_id,
                'branch_id' => $source->branch_id,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'task_no' => 'TMP',
                'type' => $data['type'],
                'transaction_id' => $source->id,
                'status' => 'open',
                'assigned_to' => $data['assigned_to'] ?? null,
                'note' => $data['note'] ?? null,
                'created_by' => Auth::id(),
            ]);
            $task->forceFill(['task_no' => strtoupper(substr($data['type'], 0, 2)).'-'.str_pad((string) $task->id, 6, '0', STR_PAD_LEFT)])->saveQuietly();

            foreach ($lines as $line) {
                $task->lines()->create($line + ['quantity_done' => 0]);
            }

            return $task;
        });

        return response()->json(['message' => 'Successfully Saved', 'data' => $task->load('lines')], 201);
    }

    /**
     * Records what was done per line (and where it was put) and moves an open list to in progress.
     */
    public function progress(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/warehousetask/:id/edit');

        $data = $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.id' => 'required|integer',
            'lines.*.quantity_done' => 'required|numeric|min:0',
            'lines.*.location_id' => 'nullable|integer',
            'lines.*.note' => 'nullable|string|max:255',
        ]);

        $task = WarehouseTask::query()->visibleToCurrentUser()->findOrFail($id);
        $this->assertWorkable($task);

        foreach ($data['lines'] as $row) {
            $line = WarehouseTaskLine::query()->where('warehouse_task_id', $task->id)->find($row['id']);

            if ($line === null) {
                throw ValidationException::withMessages(['lines' => ['A line does not belong to this list.']]);
            }

            if ((float) $row['quantity_done'] > $line->quantity + 0.000001) {
                throw ValidationException::withMessages(['lines' => ['A line cannot be done beyond its quantity.']]);
            }

            $line->update(['quantity_done' => $row['quantity_done'], 'location_id' => $row['location_id'] ?? $line->location_id, 'note' => $row['note'] ?? $line->note]);
        }

        if ($task->status === 'open') {
            $task->update(['status' => 'in_progress']);
        }

        return response()->json(['message' => 'Successfully Saved', 'data' => $task->fresh('lines')]);
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/warehousetask/:id/edit');

        $task = WarehouseTask::query()->visibleToCurrentUser()->with('lines')->findOrFail($id);
        $this->assertWorkable($task);

        $short = $task->lines->first(fn (WarehouseTaskLine $line): bool => $line->quantity_done + 0.000001 < $line->quantity);

        if ($short !== null && ! $request->boolean('allow_short')) {
            throw ValidationException::withMessages(['lines' => ['Some lines are not fully done. Finish them, or complete the list as short.']]);
        }

        $task->update(['status' => 'done', 'completed_at' => now()]);

        return response()->json(['message' => 'Successfully Saved', 'data' => $task->fresh('lines')]);
    }

    public function cancel(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/warehousetask/:id/edit');

        $task = WarehouseTask::query()->visibleToCurrentUser()->findOrFail($id);
        $this->assertWorkable($task);
        $task->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Successfully Saved', 'data' => $task->fresh()]);
    }

    private function assertWorkable(WarehouseTask $task): void
    {
        if (in_array($task->status, ['done', 'cancelled'], true)) {
            throw ValidationException::withMessages(['status' => ['This list is already '.$task->status.'.']]);
        }
    }
}
