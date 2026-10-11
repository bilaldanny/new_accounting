<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Contact;
use App\Models\Transaction;
use App\Services\ReorderSuggestions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Auto reorder suggestions (see App\Services\ReorderSuggestions) and the draft purchase orders made from them, one per
 * supplier. A draft is not posted anywhere: it is saved with status `draft` through the normal purchase path and finished
 * (rates checked, sent for approval) from the purchase screen.
 */
class ReorderSuggestionController extends Controller
{
    use ScopesToCompany;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/reordersuggestion');

        $companyId = $this->scopeCompanyId($request);
        $branchId = $this->scopeBranchId($request, $companyId);

        return response()->json(['data' => app(ReorderSuggestions::class)->rows($companyId, $branchId)->all()]);
    }

    public function createOrders(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/reordersuggestion/add');

        $data = $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'items' => 'required|array|min:1|max:200',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'nullable|numeric|gt:0',
            'items.*.supplier_id' => 'nullable|integer',
        ]);

        $companyId = $this->scopeCompanyId($request);
        $branchId = $this->scopeBranchId($request, $companyId);

        if ($branchId === null) {
            throw ValidationException::withMessages(['branch_id' => ['Choose the branch the order is for.']]);
        }

        $suggestions = app(ReorderSuggestions::class)->rows($companyId, $branchId)->keyBy('product_id');
        $bySupplier = [];

        foreach ($data['items'] as $index => $item) {
            $suggestion = $suggestions->get((int) $item['product_id']);

            if ($suggestion === null) {
                throw ValidationException::withMessages(["items.{$index}.product_id" => ['This product is not below its reorder point.']]);
            }

            $supplierId = (int) ($item['supplier_id'] ?? $suggestion['supplier_id'] ?? 0);

            if ($supplierId <= 0 || ! Contact::query()->where('company_id', $companyId)->whereKey($supplierId)->whereIn('user_type', ['supplier', 'both'])->exists()) {
                throw ValidationException::withMessages(["items.{$index}.supplier_id" => ['Choose the supplier to order '.$suggestion['name'].' from.']]);
            }

            if ($suggestion['variation_id'] === null) {
                $variationId = DB::table('product_details')->where('product_id', $suggestion['product_id'])->orderBy('id')->value('id');
                $suggestion['variation_id'] = $variationId === null ? null : (int) $variationId;
            }

            $quantity = (float) ($item['quantity'] ?? $suggestion['suggested_qty']);
            $rate = (float) ($suggestion['last_rate'] ?? 0);
            $bySupplier[$supplierId][] = [
                'product_id' => $suggestion['product_id'],
                'variation_id' => $suggestion['variation_id'],
                'itemtype_id' => $suggestion['itemtype_id'],
                'unit_id' => $suggestion['unit_id'],
                'quantity' => $quantity,
                'packing_qty' => 1,
                'pp_without_discount' => $rate,
                'discount_percent' => 0,
                'purchase_rate' => $rate,
                'profit_percent' => 0,
                'default_sell_price' => 0,
            ];
        }

        $created = DB::transaction(function () use ($bySupplier, $companyId, $branchId): array {
            $made = [];

            foreach ($bySupplier as $supplierId => $lines) {
                $total = round(array_sum(array_map(fn (array $line): float => $line['quantity'] * $line['purchase_rate'], $lines)), 2);
                $purchase = Transaction::createPurchase(new Request([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'contact_id' => $supplierId,
                    'transaction_date' => now()->toDateString(),
                    'status' => 'draft',
                    'pay_type' => 'day',
                    'discount_type' => 'none',
                    'discount_amount' => 0,
                    'shipping_charges' => 0,
                    'additional_note' => 'Reorder suggestion',
                    'final_amount' => $total,
                    'total_item' => count($lines),
                    'is_direct' => false,
                    'purchaselines' => $lines,
                ]));
                $made[] = ['id' => $purchase->id, 'invoice_no' => $purchase->invoice_no, 'supplier_id' => $supplierId, 'lines' => count($lines), 'total' => $total];
            }

            return $made;
        });

        return response()->json(['message' => count($created).' draft purchase order(s) created', 'data' => $created], 201);
    }
}
