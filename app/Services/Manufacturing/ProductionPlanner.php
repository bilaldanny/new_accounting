<?php

namespace App\Services\Manufacturing;

use App\Models\Product;
use App\Services\StockMovements;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The master production schedule and the material requirements it creates. A plan row is a quantity of a product to make in a
 * period (a week or month starting on a date). The grid compares it with the work orders already planned for the period and what
 * they produced, and `requirements()` explodes what is still to be covered through the active BOMs (sub-assemblies down to raw
 * materials) and sets it against the stock on hand: the shortfall is what to buy or make first. Nothing here moves stock.
 */
class ProductionPlanner
{
    public function __construct(private readonly BomService $boms) {}

    /**
     * @throws ValidationException
     */
    public function save(int $companyId, ?int $branchId, int $productId, string $periodStart, float $quantity, ?string $note, ?int $userId): int
    {
        if (Product::query()->where('company_id', $companyId)->whereKey($productId)->doesntExist()) {
            throw ValidationException::withMessages(['product_id' => ['Choose a product of this company.']]);
        }

        if ($quantity < 0) {
            throw ValidationException::withMessages(['planned_qty' => ['The planned quantity cannot be negative.']]);
        }

        DB::table('production_plans')->updateOrInsert(
            ['company_id' => $companyId, 'branch_id' => $branchId, 'product_id' => $productId, 'period_start' => $periodStart],
            ['planned_qty' => round($quantity, 4), 'note' => $note !== null ? mb_substr(strip_tags($note), 0, 200) : null, 'created_by' => $userId, 'updated_at' => now(), 'created_at' => now()],
        );

        return (int) DB::table('production_plans')->where('company_id', $companyId)->where('product_id', $productId)->where('period_start', $periodStart)
            ->when($branchId === null, fn ($q) => $q->whereNull('branch_id'), fn ($q) => $q->where('branch_id', $branchId))->value('id');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function grid(int $companyId, ?int $branchId, string $from, string $to): array
    {
        $names = Product::query()->where('company_id', $companyId)->pluck('name', 'id');

        return DB::table('production_plans')->where('company_id', $companyId)->whereBetween('period_start', [$from, $to])
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))->orderBy('period_start')->orderBy('product_id')->get()
            ->map(function (object $plan) use ($companyId, $names): array {
                $end = date('Y-m-d', strtotime($plan->period_start.' +6 days'));
                $orders = DB::table('work_orders')->where('company_id', $companyId)->where('product_id', $plan->product_id)->where('status', '!=', 'cancelled')
                    ->whereBetween('planned_start', [$plan->period_start, $end])->selectRaw('coalesce(sum(planned_qty), 0) as planned, coalesce(sum(produced_qty), 0) as produced')->first();

                return [
                    'id' => (int) $plan->id, 'product_id' => (int) $plan->product_id, 'product' => (string) ($names[(int) $plan->product_id] ?? ''), 'period_start' => substr((string) $plan->period_start, 0, 10),
                    'planned_qty' => (float) $plan->planned_qty, 'work_order_qty' => round((float) $orders->planned, 4), 'produced_qty' => round((float) $orders->produced, 4),
                    'to_cover' => round(max(0.0, (float) $plan->planned_qty - (float) $orders->planned), 4), 'has_bom' => $this->boms->activeFor($companyId, (int) $plan->product_id) !== null,
                ];
            })->all();
    }

    /**
     * Raw materials needed to cover what the plans of the range still ask for, against stock on hand.
     *
     * @return array{materials: list<array<string, mixed>>, without_bom: list<string>}
     */
    public function requirements(int $companyId, ?int $branchId, string $from, string $to): array
    {
        $needs = [];
        $without = [];

        foreach ($this->grid($companyId, $branchId, $from, $to) as $row) {
            if ($row['to_cover'] <= 0) {
                continue;
            }

            if (! $row['has_bom']) {
                $without[] = $row['product'];

                continue;
            }

            foreach ($this->boms->explode($companyId, (int) $row['product_id'], (float) $row['to_cover'])['materials'] as $material) {
                $key = $material['product_id'].':'.$material['variation_id'];
                $needs[$key] = isset($needs[$key]) ? ['required' => round($needs[$key]['required'] + $material['quantity'], 4)] + $needs[$key] : $material + ['required' => $material['quantity']];
            }
        }

        $materials = array_map(function (array $need) use ($branchId): array {
            $onHand = round(StockMovements::baseStock((int) $need['product_id'], (int) $need['variation_id'], $branchId), 4);

            return [
                'product_id' => $need['product_id'], 'variation_id' => $need['variation_id'], 'name' => $need['name'], 'required' => $need['required'], 'on_hand' => $onHand,
                'shortage' => round(max(0.0, $need['required'] - $onHand), 4),
            ];
        }, array_values($needs));

        return ['materials' => $materials, 'without_bom' => array_values(array_unique($without))];
    }
}
