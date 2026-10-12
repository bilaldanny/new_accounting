<?php

namespace App\Services\Valuation;

use App\Models\StandardCost;
use App\Services\Reports\AverageCost;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * The one place a unit cost comes from. Stock Valuation, the Stock Report, Stock Ageing, write-offs, the P&L stock figures and
 * Item Profit all ask here, so they cannot disagree. Under the weighted average (the default, and what a company that never
 * set a method has) every call is the AverageCost call it always was.
 */
class CostSource
{
    public function __construct(private readonly ValuationMethod $methods, private readonly LayerCosting $layers) {}

    /**
     * Unit cost (base unit) of every variation of a company for valuing stock on a day (today when null), by variation id.
     *
     * @return Collection<int, float>
     */
    public function stockCosts(?int $companyId, ?string $asOf = null): Collection
    {
        if ($companyId === null || $this->methods->isDefault($companyId)) {
            return AverageCost::byVariation($companyId, $asOf);
        }

        $day = ($asOf !== null && $asOf !== '') ? $asOf : now()->toDateString();
        $in = $this->methods->methodOn($companyId, $day);
        $average = AverageCost::byVariation($companyId, $asOf);

        if ($in['method'] === ValuationMethod::AVERAGE) {
            return $average;
        }

        if ($in['method'] === ValuationMethod::STANDARD) {
            foreach ($this->standardCosts($companyId, $day) as $variationId => $cost) {
                $average[$variationId] = $cost;
            }

            return $average;
        }

        foreach ($this->layers->stockUnitCosts($companyId, (string) $in['effective_from'], $day) as $variationId => $cost) {
            $average[$variationId] = $cost;
        }

        return $average;
    }

    /**
     * A function giving the unit cost a sale line carries: `fn (int $variationId, string $date, ?float $average): ?float`, where
     * `$average` is the weighted average on that day (what the report would use without this setting). Null when the company
     * is on the default method for every day, so the caller keeps its old path untouched.
     *
     * @param  list<int>  $variationIds
     */
    public function saleCostResolver(int $companyId, array $variationIds, string $to): ?Closure
    {
        if ($this->methods->isDefault($companyId)) {
            return null;
        }

        $built = [];
        $standard = [];

        return function (int $variationId, string $date, ?float $average) use ($companyId, $variationIds, $to, &$built, &$standard): ?float {
            $in = $this->methods->methodOn($companyId, $date);

            if ($in['method'] === ValuationMethod::STANDARD) {
                $standard[$date] ??= $this->standardCosts($companyId, $date);

                return $standard[$date][$variationId] ?? $average;
            }

            if ($in['method'] !== ValuationMethod::FIFO) {
                return $average;
            }

            $effective = (string) $in['effective_from'];
            $built[$effective] ??= $this->layers->build($companyId, $effective, $to, $variationIds);
            $entry = $built[$effective][$variationId] ?? null;

            return $entry === null ? $average : (LayerCosting::saleUnitCost($entry, $date) ?? $average);
        };
    }

    /**
     * The standard cost of every variation that has one on a day, by variation id.
     *
     * @return array<int, float>
     */
    public function standardCosts(int $companyId, string $asOf): array
    {
        if (! Schema::hasTable('standard_costs')) {
            return [];
        }

        $costs = [];

        foreach (StandardCost::query()->where('company_id', $companyId)->where('effective_from', '<=', $asOf)->orderBy('effective_from')->get(['variation_id', 'cost']) as $row) {
            $costs[(int) $row->variation_id] = (float) $row->cost;
        }

        return $costs;
    }
}
