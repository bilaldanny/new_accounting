<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\ConsignmentSettlement;
use App\Models\ConsignmentSettlementLine;
use App\Models\Contact;
use App\Models\Transaction;
use App\Services\StockMovements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Consignment stock: products a supplier owns that the company holds and sells. The stock report shows what is on hand
 * and what has been sold since the last settlement; a settlement turns the sales of a period into the amount owed to
 * the supplier, at the variation's purchase price. It is a statement: the supplier bill is entered through the normal
 * purchase / payment screens and named on the settlement as its reference.
 */
class ConsignmentController extends Controller
{
    use ScopesToCompany;

    public function stock(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/consignment');

        $companyId = $this->readCompanyId($request);
        $supplierId = $request->integer('supplier_id') ?: null;
        $branchId = $this->scopeBranchId($request, $companyId);

        $products = DB::table('products as p')
            ->join('contacts as s', 's.id', '=', 'p.consignment_supplier_id')
            ->where('p.company_id', $companyId)
            ->where('p.ownership', 'consignment')
            ->whereNull('p.deleted_at')
            ->when($supplierId !== null, fn ($q) => $q->where('p.consignment_supplier_id', $supplierId))
            ->orderBy('s.business_name')
            ->orderBy('p.name')
            ->get(['p.id', 'p.name', 'p.sku', 'p.consignment_supplier_id', 's.business_name as supplier_name']);

        if ($products->isEmpty()) {
            return response()->json(['data' => [], 'total_owed' => 0]);
        }

        $lastSettled = ConsignmentSettlement::query()
            ->where('company_id', $companyId)->whereIn('status', ['draft', 'settled'])
            ->selectRaw('supplier_id, max(period_to) as period_to')->groupBy('supplier_id')->pluck('period_to', 'supplier_id');

        $rows = [];
        $totalOwed = 0.0;

        foreach ($products as $product) {
            $onHand = (float) DB::query()->fromSub(StockMovements::query(['product_id' => (int) $product->id, 'branch_id' => $branchId]), 'm')->sum('m.qty');
            $from = $lastSettled[$product->consignment_supplier_id] ?? null;
            $from = $from === null ? null : date('Y-m-d', strtotime($from.' +1 day'));

            $sold = $this->soldLines($companyId, $branchId, (int) $product->id, $from, null);
            $quantity = round((float) $sold->sum('quantity_sold'), 4);
            $amount = round((float) $sold->sum('amount'), 2);
            $totalOwed += $amount;

            $rows[] = [
                'product_id' => (int) $product->id, 'name' => $product->name, 'sku' => $product->sku,
                'supplier_id' => (int) $product->consignment_supplier_id, 'supplier_name' => $product->supplier_name,
                'on_hand' => round($onHand, 4), 'sold_unsettled' => $quantity, 'owed' => $amount, 'unsettled_from' => $from,
            ];
        }

        return response()->json(['data' => $rows, 'total_owed' => round($totalOwed, 2)]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/consignment');

        $rows = ConsignmentSettlement::query()
            ->visibleToCurrentUser()
            ->with('supplier:id,business_name,first_name')
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 25), 100));

        return response()->json(['data' => $rows]);
    }

    public function show(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/consignment');

        $settlement = ConsignmentSettlement::query()->visibleToCurrentUser()->with(['lines', 'supplier:id,business_name,first_name'])->findOrFail($id);
        $names = DB::table('products')->whereIn('id', $settlement->lines->pluck('product_id'))->pluck('name', 'id');

        return response()->json($settlement->toArray() + ['product_names' => $names]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/consignment/add');

        $companyId = $this->scopeCompanyId($request);

        $data = $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'supplier_id' => 'required|integer',
            'period_from' => 'required|date_format:Y-m-d',
            'period_to' => 'required|date_format:Y-m-d|after_or_equal:period_from',
            'note' => 'nullable|string|max:1000',
        ]);

        $supplier = Contact::query()->where('company_id', $companyId)->where('user_type', 'supplier')->find($data['supplier_id']);

        if ($supplier === null) {
            throw ValidationException::withMessages(['supplier_id' => ['Choose a supplier of this company.']]);
        }

        $branchId = $this->scopeBranchId($request, $companyId);

        $overlaps = ConsignmentSettlement::query()
            ->where('company_id', $companyId)->where('supplier_id', $supplier->id)
            ->where('period_from', '<=', $data['period_to'])->where('period_to', '>=', $data['period_from'])
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages(['period_from' => ['This period overlaps an earlier settlement for the same supplier.']]);
        }

        $lines = $this->soldLines($companyId, $branchId, null, $data['period_from'], $data['period_to'], (int) $supplier->id);

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['period_from' => ['No consignment stock of this supplier was sold in that period.']]);
        }

        $settlement = DB::transaction(function () use ($companyId, $branchId, $supplier, $data, $lines): ConsignmentSettlement {
            $settlement = ConsignmentSettlement::query()->create([
                'company_id' => $companyId, 'branch_id' => $branchId, 'supplier_id' => $supplier->id,
                'settlement_no' => $this->nextNumber($companyId), 'period_from' => $data['period_from'], 'period_to' => $data['period_to'],
                'status' => 'draft', 'total_amount' => round((float) $lines->sum('amount'), 2), 'note' => $data['note'] ?? null,
                'created_by' => Auth::id(),
            ]);

            foreach ($lines as $line) {
                ConsignmentSettlementLine::query()->create([
                    'consignment_settlement_id' => $settlement->id, 'product_id' => $line['product_id'], 'variation_id' => $line['variation_id'],
                    'quantity_sold' => $line['quantity_sold'], 'unit_cost' => $line['unit_cost'], 'amount' => $line['amount'],
                ]);
            }

            return $settlement;
        });

        return response()->json(['message' => 'Successfully Saved', 'data' => $settlement->load('lines')], 201);
    }

    public function settle(Request $request, int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/consignment/:id/edit');

        $data = $request->validate(['reference' => 'required|string|min:2|max:100', 'settled_on' => 'required|date_format:Y-m-d']);

        $settlement = ConsignmentSettlement::query()->visibleToCurrentUser()->findOrFail($id);

        if ($settlement->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ['Only a draft settlement can be settled.']]);
        }

        $settlement->update(['status' => 'settled', 'reference' => $data['reference'], 'settled_on' => $data['settled_on']]);

        return response()->json(['message' => 'Successfully Saved', 'data' => $settlement->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorizeMenuPermission('/consignment/delete');

        $settlement = ConsignmentSettlement::query()->visibleToCurrentUser()->findOrFail($id);

        if ($settlement->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ['A settled statement cannot be deleted.']]);
        }

        DB::transaction(function () use ($settlement): void {
            ConsignmentSettlementLine::query()->where('consignment_settlement_id', $settlement->id)->delete();
            $settlement->delete();
        });

        return response()->json(['message' => 'Successfully Deleted']);
    }

    /**
     * Net quantity sold (sales less returns) of consignment products per variation, at the variation purchase price.
     *
     * @return Collection<int, array{product_id: int, variation_id: int, quantity_sold: float, unit_cost: float, amount: float}>
     */
    private function soldLines(int $companyId, ?int $branchId, ?int $productId, ?string $from, ?string $to, ?int $supplierId = null)
    {
        return DB::table('sell_lines as sl')
            ->join('transactions as t', 't.id', '=', 'sl.transaction_id')
            ->join('products as p', 'p.id', '=', 'sl.product_id')
            ->join('product_details as pd', 'pd.id', '=', 'sl.variation_id')
            ->where('t.company_id', $companyId)
            ->where('t.type', Transaction::TYPE_SELL)
            ->whereNull('t.deleted_at')
            ->whereNotIn('t.status', Transaction::UNPOSTED_SELL_STATUSES)
            ->where('p.ownership', 'consignment')
            ->when($supplierId !== null, fn ($q) => $q->where('p.consignment_supplier_id', $supplierId))
            ->when($productId !== null, fn ($q) => $q->where('sl.product_id', $productId))
            ->when($branchId !== null, fn ($q) => $q->where('t.branch_id', $branchId))
            ->when($from !== null, fn ($q) => $q->whereDate('t.transaction_date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->whereDate('t.transaction_date', '<=', $to))
            ->groupBy('sl.product_id', 'sl.variation_id', 'pd.default_purchase_price')
            ->selectRaw('sl.product_id, sl.variation_id, pd.default_purchase_price as unit_cost, sum((sl.quantity - sl.quantity_returned) * (case when sl.packing_qty is null or sl.packing_qty < 1 then 1 else sl.packing_qty end)) as qty')
            ->get()
            ->filter(fn (object $row): bool => (float) $row->qty > 0)
            ->map(fn (object $row): array => [
                'product_id' => (int) $row->product_id,
                'variation_id' => (int) $row->variation_id,
                'quantity_sold' => round((float) $row->qty, 4),
                'unit_cost' => round((float) $row->unit_cost, 4),
                'amount' => round((float) $row->qty * (float) $row->unit_cost, 2),
            ])
            ->values();
    }

    private function readCompanyId(Request $request): int
    {
        return $this->scopeCompanyId($request);
    }

    private function nextNumber(int $companyId): string
    {
        $next = (int) ConsignmentSettlement::query()->where('company_id', $companyId)->max('id') + 1;

        do {
            $number = 'CS-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
            $next++;
        } while (ConsignmentSettlement::query()->where('company_id', $companyId)->where('settlement_no', $number)->exists());

        return $number;
    }
}
