<?php

namespace App\Http\Controllers;

use App\Models\PurchaseLine;
use App\Models\QualityInspection;
use App\Models\QualityInspectionLine;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Quality inspection on receiving: per received purchase line how much was accepted and how much rejected. The rejected
 * quantity is kept on the line (`quantity_rejected`) and stock counts only what was accepted; the rejected goods are then
 * returned through an RMA / purchase return. An inspection can be deleted, which puts the line right again.
 */
class QualityInspectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/qualityinspection');

        $rows = QualityInspection::query()
            ->visibleToCurrentUser()
            ->withSum('lines as accepted_total', 'accepted_qty')
            ->withSum('lines as rejected_total', 'rejected_qty')
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('transaction_id'), fn ($q) => $q->where('transaction_id', $request->integer('transaction_id')))
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 25), 100));

        return response()->json(['data' => $rows]);
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/qualityinspection');

        return response()->json(QualityInspection::query()->visibleToCurrentUser()->with('lines')->findOrFail($id));
    }

    /**
     * The received lines of a purchase with what is already inspected, for the inspection form.
     */
    public function lines(int $transactionId): JsonResponse
    {
        $this->authorizeMenuPermission('/qualityinspection');

        $purchase = Transaction::query()->visibleToCurrentUser()->where('type', Transaction::TYPE_PURCHASE)->findOrFail($transactionId);

        $lines = PurchaseLine::query()
            ->where('transaction_id', $purchase->id)
            ->join('products', 'products.id', '=', 'purchase_lines.product_id')
            ->get(['purchase_lines.id', 'products.name as product_name', 'purchase_lines.quantity', 'purchase_lines.quantity_received', 'purchase_lines.quantity_rejected']);

        return response()->json(['data' => $lines]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/qualityinspection/add');

        $data = $request->validate([
            'transaction_id' => 'required|integer',
            'inspected_on' => 'required|date_format:Y-m-d',
            'note' => 'nullable|string|max:1000',
            'lines' => 'required|array|min:1|max:200',
            'lines.*.purchase_line_id' => 'required|integer',
            'lines.*.accepted_qty' => 'required|numeric|min:0',
            'lines.*.rejected_qty' => 'required|numeric|min:0',
            'lines.*.reason' => 'nullable|string|max:255',
        ]);

        $purchase = Transaction::query()->visibleToCurrentUser()->where('type', Transaction::TYPE_PURCHASE)->findOrFail($data['transaction_id']);
        $lines = PurchaseLine::query()->where('transaction_id', $purchase->id)->get()->keyBy('id');
        $seen = [];

        foreach ($data['lines'] as $index => $row) {
            $line = $lines->get($row['purchase_line_id']);

            if ($line === null) {
                throw ValidationException::withMessages(["lines.{$index}.purchase_line_id" => ['A line does not belong to this purchase.']]);
            }

            if (isset($seen[$line->id])) {
                throw ValidationException::withMessages(["lines.{$index}.purchase_line_id" => ['A line is listed twice.']]);
            }

            $seen[$line->id] = true;
            $already = (float) QualityInspectionLine::query()->where('purchase_line_id', $line->id)->sum('rejected_qty');
            $inspected = (float) $row['accepted_qty'] + (float) $row['rejected_qty'];

            if ((float) $line->quantity_received <= 0 || $inspected > (float) $line->quantity_received + 0.000001) {
                throw ValidationException::withMessages(["lines.{$index}.accepted_qty" => ['Accepted plus rejected cannot be more than what was received ('.(float) $line->quantity_received.').']]);
            }

            if ((float) $row['rejected_qty'] > 0 && $already + (float) $row['rejected_qty'] > (float) $line->quantity_received + 0.000001) {
                throw ValidationException::withMessages(["lines.{$index}.rejected_qty" => ['More has been rejected on this line than was received.']]);
            }
        }

        $inspection = DB::transaction(function () use ($data, $purchase): QualityInspection {
            $accepted = collect($data['lines'])->sum('accepted_qty');
            $rejected = collect($data['lines'])->sum('rejected_qty');

            $inspection = QualityInspection::query()->create([
                'company_id' => $purchase->company_id,
                'branch_id' => $purchase->branch_id,
                'transaction_id' => $purchase->id,
                'inspection_no' => 'TMP',
                'inspected_on' => $data['inspected_on'],
                'result' => $rejected <= 0 ? 'passed' : ($accepted <= 0 ? 'failed' : 'partial'),
                'note' => $data['note'] ?? null,
                'inspected_by' => Auth::id(),
            ]);
            $inspection->forceFill(['inspection_no' => 'QI-'.str_pad((string) $inspection->id, 6, '0', STR_PAD_LEFT)])->saveQuietly();

            foreach ($data['lines'] as $row) {
                $inspection->lines()->create($row);
            }

            $this->syncRejected(array_column($data['lines'], 'purchase_line_id'));

            return $inspection;
        });

        return response()->json(['message' => 'Successfully Saved', 'data' => $inspection->load('lines')], 201);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/qualityinspection/delete');

        $inspection = QualityInspection::query()->visibleToCurrentUser()->with('lines')->findOrFail($id);
        $lineIds = $inspection->lines->pluck('purchase_line_id')->all();

        DB::transaction(function () use ($inspection, $lineIds): void {
            $inspection->lines()->delete();
            $inspection->delete();
            $this->syncRejected($lineIds);
        });

        return response()->json(['message' => 'Successfully Deleted']);
    }

    /**
     * Puts each line's rejected quantity right: the sum of what every inspection rejected on it.
     *
     * @param  list<int>  $lineIds
     */
    private function syncRejected(array $lineIds): void
    {
        foreach (array_unique($lineIds) as $lineId) {
            $rejected = (float) QualityInspectionLine::query()->where('purchase_line_id', $lineId)->sum('rejected_qty');

            PurchaseLine::query()->whereKey($lineId)->update(['quantity_rejected' => round($rejected, 4)]);
        }
    }
}
