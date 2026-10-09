<?php

namespace App\Http\Controllers;

use App\Models\PurchaseLandedCost;
use App\Models\Transaction;
use App\Services\LandedCost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Landed Cost Calculator: the extra costs of a purchase (freight, customs duty, insurance, clearing) and how
 * they are spread over its lines. Setting them changes the average cost stock valuation and item profit use.
 */
class LandedCostController extends Controller
{
    public function __construct(private readonly LandedCost $landedCost) {}

    /**
     * Purchases (newest first) with the landed costs they carry, searchable by invoice number.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/landedcost');

        $search = trim((string) $request->input('search'));

        $purchases = Transaction::query()
            ->purchases()
            ->visibleToCurrentUser()
            ->where('status', '!=', 'draft')
            ->when($search !== '', fn ($q) => $q->where('invoice_no', 'like', "%{$search}%"))
            ->withSum('landedCosts as landed_total', 'amount')
            ->with('contact:id,business_name')
            ->orderByDesc('id')
            ->paginate(min((int) ($request->input('show_record') ?: 15), 100));

        $purchases->getCollection()->transform(fn (Transaction $purchase): array => [
            'id' => $purchase->id,
            'invoice_no' => $purchase->invoice_no,
            'supplier' => $purchase->contact?->business_name,
            'status' => $purchase->status,
            'transaction_date' => $purchase->transaction_date?->toDateString(),
            'final_amount' => (float) $purchase->final_amount,
            'landed_total' => round((float) $purchase->landed_total, 2),
        ]);

        return response()->json(['data' => $purchases]);
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/landedcost');

        return response()->json(['data' => $this->landedCost->detail($this->purchase($id))]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/landedcost/edit');

        $data = $request->validate([
            'costs' => 'present|array|max:50',
            'costs.*.type' => 'required|in:'.implode(',', PurchaseLandedCost::TYPES),
            'costs.*.amount' => 'required|numeric|min:0.01|max:999999999',
            'costs.*.allocation_basis' => 'nullable|in:'.implode(',', PurchaseLandedCost::BASES),
            'costs.*.description' => 'nullable|string|max:255',
        ]);

        $purchase = $this->purchase($id);
        $this->landedCost->replace($purchase, $data['costs']);

        return response()->json(['message' => 'Saved', 'data' => $this->landedCost->detail($purchase)]);
    }

    private function purchase(int $id): Transaction
    {
        return Transaction::query()->purchases()->visibleToCurrentUser()->findOrFail($id);
    }
}
