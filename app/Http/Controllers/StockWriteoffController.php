<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Product;
use App\Models\ProductDetail;
use App\Models\StockWriteoff;
use App\Models\Transaction;
use App\Services\Reports\AverageCost;
use App\Services\StockMovements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Damage, expired, near-expiry disposal and wastage: a dedicated screen over the ordinary stock adjustment. Saving one
 * creates a completed `abnormal` stock adjustment (so stock, period lock, inventory close and the adjustment report behave
 * as for any adjustment) and a write-off row that carries the reason and the value (average cost on the day).
 */
class StockWriteoffController extends Controller
{
    use ScopesToCompany;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/stockwriteoff');

        $base = fn () => StockWriteoff::query()
            ->visibleToCurrentUser()
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($request->filled('reason') && $request->input('reason') !== 'all', fn ($q) => $q->where('reason', $request->input('reason')))
            ->when($request->filled('start_date'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('start_date')))
            ->when($request->filled('end_date'), fn ($q) => $q->whereDate('created_at', '<=', $request->input('end_date')));

        $byReason = $base()->selectRaw('reason, sum(total_quantity) as quantity, sum(coalesce(total_value, 0)) as value, count(*) as entries')
            ->groupBy('reason')->get()->keyBy('reason');

        return response()->json([
            'data' => $base()->orderByDesc('id')->paginate(min($request->integer('per_page', 25), 100)),
            'by_reason' => collect(StockWriteoff::REASONS)->mapWithKeys(fn (string $reason): array => [$reason => [
                'entries' => (int) ($byReason[$reason]->entries ?? 0),
                'quantity' => round((float) ($byReason[$reason]->quantity ?? 0), 4),
                'value' => round((float) ($byReason[$reason]->value ?? 0), 2),
            ]]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/stockwriteoff/add');

        $data = $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'warehouse_id' => 'nullable|integer',
            'reason' => 'required|in:'.implode(',', StockWriteoff::REASONS),
            'date' => 'required|date_format:Y-m-d',
            'note' => 'nullable|string|max:300',
            'rows' => 'required|array|min:1|max:200',
            'rows.*.product_id' => 'required|integer',
            'rows.*.variation_id' => 'nullable|integer',
            'rows.*.quantity' => 'required|numeric|gt:0',
        ]);

        $companyId = $this->scopeCompanyId($request);
        $branchId = $this->scopeBranchId($request, $companyId);

        if ($branchId === null) {
            throw ValidationException::withMessages(['branch_id' => ['Choose the branch the stock is written off in.']]);
        }

        $lines = [];
        $variationIds = [];
        $quantity = 0.0;
        $seen = [];

        foreach ($data['rows'] as $index => $row) {
            $product = Product::query()->where('company_id', $companyId)->find($row['product_id']);

            if ($product === null || ! StockMovements::productKeepsStock((int) $product->id)) {
                throw ValidationException::withMessages(["rows.{$index}.product_id" => ['Choose a stocked product of this company.']]);
            }

            $variation = ! empty($row['variation_id'])
                ? ProductDetail::query()->where('product_id', $product->id)->find($row['variation_id'])
                : ProductDetail::query()->where('product_id', $product->id)->orderBy('id')->first();

            if ($variation === null) {
                throw ValidationException::withMessages(["rows.{$index}.variation_id" => ['That variation does not belong to this product.']]);
            }

            if (isset($seen[$variation->id])) {
                throw ValidationException::withMessages(["rows.{$index}.product_id" => ['A product is listed twice.']]);
            }

            $seen[$variation->id] = true;
            $variationIds[] = (int) $variation->id;
            $quantity += (float) $row['quantity'];
            $lines[] = [
                'product_id' => (int) $product->id, 'variation_id' => (int) $variation->id, 'unit_id' => (int) $product->unit_id,
                'itemtype_id' => $product->itemtype_id ?: null, 'quantity_adjustment' => -1 * (float) $row['quantity'],
            ];
        }

        $costs = AverageCost::byVariation($companyId, $data['date']);
        $value = 0.0;

        foreach ($lines as $line) {
            $value += abs($line['quantity_adjustment']) * (float) ($costs[$line['variation_id']] ?? 0);
        }

        $label = ['damage' => 'Damage', 'expired' => 'Expired', 'near_expiry' => 'Near-expiry disposal', 'wastage' => 'Wastage'][$data['reason']];

        $writeoff = DB::transaction(function () use ($companyId, $branchId, $data, $lines, $quantity, $value, $label): StockWriteoff {
            $adjustment = Transaction::createAdjustment((object) [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'transaction_date' => $data['date'],
                'adjustment_type' => 'abnormal',
                'additional_note' => trim($label.(($data['note'] ?? null) ? ': '.$data['note'] : '')),
                'status' => 'completed',
                'total_item' => count($lines),
                'purchaselines' => $lines,
            ]);

            return StockWriteoff::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'adjustment_id' => $adjustment->id,
                'reason' => $data['reason'],
                'total_quantity' => round($quantity, 4),
                'total_value' => round($value, 2),
                'note' => $data['note'] ?? null,
                'created_by' => Auth::id(),
            ]);
        });

        return response()->json(['message' => 'Successfully Saved', 'data' => $writeoff], 201);
    }
}
