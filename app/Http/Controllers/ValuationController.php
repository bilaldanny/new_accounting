<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\CostRecalculationRun;
use App\Models\ProductDetail;
use App\Models\StandardCost;
use App\Services\InventoryClose;
use App\Services\PeriodLock;
use App\Services\Reports\AverageCost;
use App\Services\Reports\ProfitLossReport;
use App\Services\Reports\StockReport;
use App\Services\Reports\StockValuation;
use App\Services\StockMovements;
use App\Services\Valuation\CostRecalculation;
use App\Services\Valuation\CostSource;
use App\Services\Valuation\ValuationMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Inventory valuation settings and the COGS recalculation engine (see Services\Valuation). Reading is the page permission; each
 * change has its own. Nothing here posts to the ledger.
 */
class ValuationController extends Controller
{
    use ScopesToCompany;

    public function __construct(
        private readonly ValuationMethod $methods,
        private readonly CostRecalculation $recalculation,
        private readonly CostSource $costs,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/valuation');

        $companyId = $this->scopeCompanyId($request);

        return response()->json([
            'current' => $this->methods->methodOn($companyId),
            'history' => $this->methods->changes($companyId)->map(fn ($change): array => [
                'id' => $change->id, 'method' => $change->method, 'effective_from' => $change->effective_from->toDateString(), 'note' => $change->note,
            ])->values(),
            'methods' => ValuationMethod::METHODS,
            'lifo' => ValuationMethod::LIFO_NOTE,
            'standard_costs' => Schema::hasTable('standard_costs') ? StandardCost::query()->where('company_id', $companyId)->count() : 0,
            'runs' => Schema::hasTable('cost_recalculation_runs')
                ? CostRecalculationRun::query()->where('company_id', $companyId)->orderByDesc('id')->limit(20)->get()
                : [],
        ]);
    }

    public function setMethod(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/valuation/method');

        $data = $request->validate([
            'method' => 'required|string|max:12',
            'effective_from' => 'required|date_format:Y-m-d',
            'note' => 'nullable|string|max:255',
        ]);

        $companyId = $this->scopeCompanyId($request);
        $locked = array_filter([
            app(PeriodLock::class)->lockedThrough($companyId),
            app(InventoryClose::class)->lockedThrough($companyId, null),
        ]);

        $change = $this->methods->record($companyId, $data['method'], $data['effective_from'], $data['note'] ?? null, Auth::id(), $locked === [] ? null : (string) max($locked));

        return response()->json(['message' => 'Successfully Saved', 'data' => $change], 201);
    }

    /**
     * Sets standard costs from a day forward: `items` is a list of variation id and cost per base unit.
     */
    public function setStandardCosts(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/valuation/standard');

        $data = $request->validate([
            'effective_from' => 'required|date_format:Y-m-d',
            'items' => 'required|array|min:1|max:500',
            'items.*.variation_id' => 'required|integer',
            'items.*.cost' => 'required|numeric|min:0',
        ]);

        $companyId = $this->scopeCompanyId($request);

        if (! Schema::hasTable('standard_costs')) {
            throw ValidationException::withMessages(['items' => ['The valuation tables are not installed yet.']]);
        }

        $ids = array_map(fn (array $item): int => (int) $item['variation_id'], $data['items']);
        $owned = ProductDetail::query()
            ->join('products', 'products.id', '=', 'product_details.product_id')
            ->where('products.company_id', $companyId)
            ->whereIn('product_details.id', $ids)
            ->pluck('product_details.id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach ($ids as $index => $id) {
            if (! in_array($id, $owned, true)) {
                throw ValidationException::withMessages(["items.{$index}.variation_id" => ['This product variation is not part of the company.']]);
            }
        }

        DB::transaction(function () use ($data, $companyId): void {
            foreach ($data['items'] as $item) {
                StandardCost::query()->updateOrCreate(
                    ['company_id' => $companyId, 'variation_id' => (int) $item['variation_id'], 'effective_from' => $data['effective_from']],
                    ['cost' => round((float) $item['cost'], 4), 'created_by' => Auth::id()],
                );
            }
        });

        return response()->json(['message' => 'Successfully Saved', 'count' => count($data['items'])], 201);
    }

    /**
     * Stock at standard against stock at the actual weighted average cost, per variation.
     */
    public function variance(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/valuation');

        $data = $request->validate(['as_of' => 'nullable|date_format:Y-m-d']);
        $companyId = $this->scopeCompanyId($request);
        $asOf = $data['as_of'] ?? now()->toDateString();
        $standard = $this->costs->standardCosts($companyId, $asOf);
        $average = AverageCost::byVariation($companyId, $asOf);

        $stock = DB::query()
            ->fromSub(StockMovements::query(['as_of' => $asOf]), 'm')
            ->join('products as p', 'p.id', '=', 'm.product_id')
            ->where('p.company_id', $companyId)
            ->whereNull('p.deleted_at')
            ->whereIn('m.variation_id', array_keys($standard) ?: [0])
            ->groupBy('m.variation_id')
            ->selectRaw('m.variation_id, sum(m.qty) as stock')
            ->pluck('stock', 'variation_id');

        $rows = [];

        foreach ($standard as $variationId => $cost) {
            $quantity = round((float) ($stock[$variationId] ?? 0), 4);
            $actual = $average[$variationId] ?? null;
            $rows[] = [
                'variation_id' => $variationId, 'stock' => $quantity, 'standard_cost' => $cost, 'average_cost' => $actual === null ? null : round($actual, 4),
                'standard_value' => round($quantity * $cost, 2), 'actual_value' => $actual === null ? null : round($quantity * $actual, 2),
                'variance' => $actual === null ? null : round($quantity * ($cost - $actual), 2),
            ];
        }

        return response()->json(['data' => $rows, 'total_variance' => round((float) collect($rows)->sum('variance'), 2)]);
    }

    /**
     * The stock figure every report shows for a day, side by side: they all come from one cost source, so `matches` is true.
     */
    public function reconciliation(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/valuation');

        $data = $request->validate(['as_of' => 'nullable|date_format:Y-m-d']);
        $companyId = $this->scopeCompanyId($request);
        $asOf = $data['as_of'] ?? now()->toDateString();

        $valuation = app(StockValuation::class)->at($companyId, null, $asOf)['value'];
        $stockReport = app(StockReport::class);
        $report = $stockReport->summary($stockReport->rows($companyId, null, ['end_date' => $asOf]))['stock_value'];
        $profit = app(ProfitLossReport::class)->build($companyId, null, ['start_date' => $asOf, 'end_date' => $asOf])['summary']['closing_stock'];

        return response()->json([
            'as_of' => $asOf,
            'method' => $this->methods->methodOn($companyId, $asOf)['method'],
            'stock_valuation' => $valuation,
            'stock_report' => $report,
            'profit_loss_closing_stock' => $profit,
            'matches' => abs($valuation - $report) < 0.005 && abs($valuation - (float) $profit) < 0.005,
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/valuation');

        $data = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);

        return response()->json($this->recalculation->preview($this->scopeCompanyId($request), $data['from'], $data['to']));
    }

    public function recalculate(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/valuation/recalculate');

        $data = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);

        $result = $this->recalculation->commit($this->scopeCompanyId($request), $data['from'], $data['to'], Auth::id());

        return response()->json(['message' => 'Costs recalculated', 'data' => $result], 201);
    }
}
