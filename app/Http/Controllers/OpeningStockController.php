<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Product;
use App\Models\ProductDetail;
use App\Models\Transaction;
use App\Models\Warehouse;
use App\Services\ProductCatalog;
use App\Services\StockMovements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The Opening Stock screen: the quantity (and unit cost) a branch starts with, entered or imported by variation SKU /
 * barcode. It writes an ordinary completed stock adjustment of type `opening` through Transaction::createAdjustment, so
 * stock, the warehouse tag, branch scoping and period locks behave as they do for any adjustment. The unit cost rides on
 * the line (`opening_cost`) and is read by the average cost, so opening stock is valued; nothing is posted to the ledger
 * (the books start from the opening balances).
 *
 * A variation that already has opening stock in the branch is refused: correct it with a stock adjustment instead.
 */
class OpeningStockController extends Controller
{
    use ScopesToCompany;

    public const MAX_ROWS = 2000;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/openingstock');

        $rows = Transaction::query()
            ->adjustments()
            ->visibleToCurrentUser()
            ->where('adjustment_type', 'opening')
            ->withCount('purchaselines')
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 25), 100), ['id', 'company_id', 'branch_id', 'invoice_no', 'transaction_date', 'status', 'total_item', 'additional_note']);

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/openingstock/add');

        $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'warehouse_id' => 'nullable|integer',
            'date' => 'required|date_format:Y-m-d',
            'note' => 'nullable|string|max:300',
            'dry_run' => 'nullable|boolean',
            'rows' => 'bail|required|array|min:1|max:'.self::MAX_ROWS,
            'rows.*.quantity' => 'required|numeric|gt:0',
            'rows.*.unit_cost' => 'nullable|numeric|min:0',
            'rows.*.product_id' => 'nullable|integer',
            'rows.*.variation_id' => 'nullable|integer',
            'rows.*.sku' => 'nullable|string|max:100',
        ]);

        $companyId = $this->scopeCompanyId($request);
        $branchId = $this->scopeBranchId($request, $companyId);

        if ($branchId === null) {
            throw ValidationException::withMessages(['branch_id' => ['Choose the branch this stock is opened in.']]);
        }

        $warehouseId = $request->filled('warehouse_id') ? $request->integer('warehouse_id') : null;

        if ($warehouseId !== null && ! Warehouse::query()->whereKey($warehouseId)->where('branch_id', $branchId)->exists()) {
            throw ValidationException::withMessages(['warehouse_id' => ['Choose a warehouse of that branch.']]);
        }

        [$lines, $errors] = $this->resolveRows($companyId, $branchId, (array) $request->input('rows'));

        if ($request->boolean('dry_run') || $errors !== []) {
            return response()->json([
                'message' => $errors === [] ? 'Checked '.count($lines).' rows: all can be saved.' : 'Opening stock could not be saved: fix the rows with errors.',
                'dry_run' => $request->boolean('dry_run'),
                'errors' => $errors,
                'rows' => count($lines),
            ], $errors === [] ? 200 : 422);
        }

        $payload = (object) [
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'warehouse_id' => $warehouseId,
            'transaction_date' => $request->input('date'),
            'adjustment_type' => 'opening',
            'additional_note' => trim('Opening stock'.($request->input('note') ? ': '.$request->input('note') : '')),
            'status' => 'completed',
            'total_item' => count($lines),
            'purchaselines' => $lines,
        ];

        $adjustment = DB::transaction(fn (): Transaction => Transaction::createAdjustment($payload));

        return response()->json(['message' => 'Successfully Saved', 'id' => $adjustment->id, 'invoice_no' => $adjustment->invoice_no, 'rows' => count($lines)], 201);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function resolveRows(int $companyId, int $branchId, array $rows): array
    {
        $lines = [];
        $errors = [];
        $seen = [];

        $alreadyOpened = DB::table('purchase_lines as pl')
            ->join('transactions as t', 't.id', '=', 'pl.transaction_id')
            ->where('t.type', Transaction::TYPE_ADJUSTMENT)
            ->where('t.adjustment_type', 'opening')
            ->where('t.branch_id', $branchId)
            ->whereNull('t.deleted_at')
            ->pluck('pl.variation_id')
            ->flip();

        foreach ($rows as $index => $row) {
            $number = $index + 1;
            $resolved = $this->resolveVariation($companyId, $row);

            if ($resolved === null) {
                $errors[] = "Row {$number}: product not found (use the variation SKU or barcode of this company).";

                continue;
            }

            [$product, $variationId] = $resolved;

            if (! StockMovements::productKeepsStock((int) $product->id)) {
                $errors[] = "Row {$number}: {$product->name} is not a stocked item.";

                continue;
            }

            if (isset($seen[$variationId])) {
                $errors[] = "Row {$number}: {$product->name} is listed more than once.";

                continue;
            }

            if ($alreadyOpened->has($variationId)) {
                $errors[] = "Row {$number}: {$product->name} already has opening stock in this branch; correct it with a stock adjustment.";

                continue;
            }

            $seen[$variationId] = true;
            $lines[] = [
                'product_id' => (int) $product->id,
                'variation_id' => $variationId,
                'unit_id' => (int) $product->unit_id,
                'itemtype_id' => $product->itemtype_id ?: null,
                'quantity_adjustment' => round((float) $row['quantity'], 4),
                'opening_cost' => isset($row['unit_cost']) && $row['unit_cost'] !== '' ? round((float) $row['unit_cost'], 4) : null,
            ];
        }

        return [$lines, $errors];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: Product, 1: int}|null
     */
    private function resolveVariation(int $companyId, array $row): ?array
    {
        $productId = ! empty($row['product_id']) ? (int) $row['product_id'] : null;
        $variationId = ! empty($row['variation_id']) ? (int) $row['variation_id'] : null;

        if ($productId === null && ! empty($row['sku'])) {
            $match = ProductCatalog::resolveCode($companyId, (string) $row['sku']);

            if ($match === null) {
                return null;
            }

            $productId = $match['product_id'];
            $variationId = $variationId ?? $match['variation_id'];
        }

        if ($productId === null) {
            return null;
        }

        $product = Product::query()->where('company_id', $companyId)->find($productId);

        if ($product === null) {
            return null;
        }

        $variations = ProductDetail::query()->where('product_id', $product->id)->pluck('id');

        if ($variationId === null && $variations->count() === 1) {
            $variationId = (int) $variations->first();
        }

        return $variationId !== null && $variations->contains($variationId) ? [$product, $variationId] : null;
    }
}
