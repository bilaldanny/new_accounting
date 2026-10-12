<?php

namespace App\Services\Valuation;

use App\Services\Reports\AverageCost;
use App\Services\StockMovements;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FIFO costing, derived on demand from the documents that already exist (no new source of truth).
 *
 * A variation's layers are the stock it held the day before the method took effect (one opening layer, at the weighted average
 * that day, so nothing before the effective date is revalued) followed by every purchase / opening-stock line from the
 * effective date on, in date order. Cost pools are per company, like the average cost they replace. Units leave the oldest layer
 * first, so what is on the shelf on a day is the newest layers covering the stock then:
 *
 *   consumed(d)  = layer units that arrived by d - company-wide stock at the end of d
 *   cost of day  = cost of the first consumed(d) units - cost of the first consumed(d - 1) units
 *
 * Every sale line of a day gets that day's unit cost, and the stock value of a day is the newest layers, so stock value,
 * item profit and cost of goods sold are three views of the same layers. Units that come back with no cost of their own (sale
 * returns, positive adjustments) reduce `consumed`, which puts them back on the oldest consumed layer.
 */
class LayerCosting
{
    /**
     * @param  list<int>|null  $variationIds  limit the work to these variations (null for all)
     * @return array<int, array{layers: VariationLayers, stock: float, days: list<array{date: string, unit: float}>}>
     */
    public function build(int $companyId, string $effectiveFrom, string $to, ?array $variationIds = null): array
    {
        $before = Carbon::parse($effectiveFrom)->subDay()->toDateString();
        $movements = DB::query()
            ->fromSub(StockMovements::query(['with_documents' => true, 'as_of' => $to]), 'm')
            ->join('products as p', 'p.id', '=', 'm.product_id')
            ->where('p.company_id', $companyId)
            ->whereNull('p.deleted_at')
            ->when($variationIds !== null, fn ($query) => $query->whereIn('m.variation_id', $variationIds))
            ->groupBy('m.variation_id', DB::raw('date(m.document_date)'))
            ->selectRaw('m.variation_id, date(m.document_date) as day, sum(m.qty) as qty')
            ->get();

        $averages = AverageCost::byVariation($companyId, $before);
        $series = [];

        // opening stock at the day before the method took effect
        foreach ($movements->where('day', '<=', $before)->groupBy('variation_id') as $variationId => $rows) {
            $opening = round((float) $rows->sum('qty'), 6);
            $series[(int) $variationId]['layers'] = new VariationLayers;

            if ($opening > 0) {
                $series[(int) $variationId]['layers']->add($before, $opening, $opening * (float) ($averages[(int) $variationId] ?? 0.0));
            }
        }

        foreach (AverageCost::layerRows($companyId, $effectiveFrom, $to)->get() as $row) {
            $variationId = (int) $row->variation_id;

            if ($variationIds !== null && ! in_array($variationId, $variationIds, true)) {
                continue;
            }

            $series[$variationId]['layers'] ??= new VariationLayers;
            $series[$variationId]['layers']->add(substr((string) $row->layer_date, 0, 10), (float) $row->qty, (float) $row->cost);
        }

        $result = [];

        foreach ($series as $variationId => $entry) {
            /** @var VariationLayers $layers */
            $layers = $entry['layers'];
            $rows = $movements->where('variation_id', $variationId);
            $stock = round((float) $rows->where('day', '<=', $before)->sum('qty'), 6);
            $previous = 0.0;
            $days = [];

            foreach ($rows->where('day', '>', $before)->sortBy('day') as $row) {
                $stock += (float) $row->qty;
                $consumed = max($layers->qtyUpTo((string) $row->day) - $stock, 0.0);
                $delta = $consumed - $previous;
                $unit = $delta > 1e-9
                    ? ($layers->cost($consumed) - $layers->cost($previous)) / $delta
                    : $layers->headUnitCost($consumed);

                if ($unit !== null) {
                    $days[] = ['date' => (string) $row->day, 'unit' => $unit];
                }

                $previous = $consumed;
            }

            $result[$variationId] = ['layers' => $layers, 'stock' => round((float) $rows->sum('qty'), 6), 'days' => $days];
        }

        return $result;
    }

    /**
     * Unit cost of what is on the shelf at the end of `$to`, by variation.
     *
     * @return array<int, float>
     */
    public function stockUnitCosts(int $companyId, string $effectiveFrom, string $to): array
    {
        $costs = [];

        foreach ($this->build($companyId, $effectiveFrom, $to) as $variationId => $entry) {
            $unit = $entry['layers']->remainingUnitCost(max($entry['stock'], 0.0));

            if ($unit !== null) {
                $costs[$variationId] = $unit;
            }
        }

        return $costs;
    }

    /**
     * The unit cost a sale of a variation on a day carries: the day's consumption cost, or the cost of the layer at the head
     * when nothing net was consumed. Null for a variation with no layers.
     *
     * @param  array{layers: VariationLayers, stock: float, days: list<array{date: string, unit: float}>}  $entry
     */
    public static function saleUnitCost(array $entry, string $date): ?float
    {
        $found = null;

        foreach ($entry['days'] as $day) {
            if ($day['date'] > $date) {
                break;
            }

            $found = $day['unit'];
        }

        return $found ?? $entry['layers']->headUnitCost(0.0);
    }
}
