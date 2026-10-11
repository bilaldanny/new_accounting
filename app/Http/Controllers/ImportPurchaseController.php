<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\ImportPurchaseDetail;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LC, customs clearing and foreign currency details of an import purchase. They are kept beside the purchase and shown on
 * the Import Purchases page; the purchase itself keeps posting in the local currency, and the page tells how far the
 * foreign total at the entered rate is from the local total.
 */
class ImportPurchaseController extends Controller
{
    use ScopesToCompany;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/importpurchase');

        $rows = ImportPurchaseDetail::query()
            ->visibleToCurrentUser()
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $purchases = Transaction::query()->whereIn('id', $rows->pluck('transaction_id'))->get(['id', 'invoice_no', 'final_amount', 'transaction_date', 'contact_id'])->keyBy('id');

        return response()->json(['data' => $rows->map(fn (ImportPurchaseDetail $row): array => $this->present($row, $purchases->get($row->transaction_id)))->values()]);
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/importpurchase');

        $purchase = Transaction::query()->visibleToCurrentUser()->where('type', Transaction::TYPE_PURCHASE)->findOrFail($id);
        $detail = ImportPurchaseDetail::query()->where('transaction_id', $purchase->id)->first();

        return response()->json(['data' => $detail === null ? null : $this->present($detail, $purchase), 'purchase' => ['id' => $purchase->id, 'invoice_no' => $purchase->invoice_no, 'final_amount' => (float) $purchase->final_amount]]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/importpurchase/:id/edit');

        $data = $request->validate([
            'lc_no' => 'nullable|string|max:100',
            'lc_bank' => 'nullable|string|max:150',
            'lc_amount' => 'nullable|numeric|min:0',
            'lc_opened_on' => 'nullable|date_format:Y-m-d',
            'lc_expires_on' => 'nullable|date_format:Y-m-d|after_or_equal:lc_opened_on',
            'bl_no' => 'nullable|string|max:100',
            'customs_ref' => 'nullable|string|max:100',
            'customs_cleared_on' => 'nullable|date_format:Y-m-d',
            'clearing_agent' => 'nullable|string|max:150',
            'currency_code' => 'nullable|string|max:10',
            'exchange_rate' => 'nullable|numeric|gt:0',
            'foreign_total' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:2000',
        ]);

        $purchase = Transaction::query()->visibleToCurrentUser()->where('type', Transaction::TYPE_PURCHASE)->findOrFail($id);

        $detail = ImportPurchaseDetail::query()->updateOrCreate(
            ['transaction_id' => $purchase->id],
            $data + ['company_id' => $purchase->company_id],
        );

        return response()->json(['message' => 'Successfully Saved', 'data' => $this->present($detail->fresh(), $purchase)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ImportPurchaseDetail $detail, ?Transaction $purchase): array
    {
        $local = $detail->foreign_total !== null && $detail->exchange_rate !== null ? round($detail->foreign_total * $detail->exchange_rate, 2) : null;

        return $detail->toArray() + [
            'invoice_no' => $purchase?->invoice_no,
            'purchase_total' => $purchase === null ? null : round((float) $purchase->final_amount, 2),
            'local_equivalent' => $local,
            'variance' => $local === null || $purchase === null ? null : round((float) $purchase->final_amount - $local, 2),
        ];
    }
}
