<?php

namespace App\Services\Valuation;

use App\Models\CostRecalculationRun;
use App\Models\SellLineCost;
use App\Services\InventoryClose;
use App\Services\PeriodLock;
use App\Services\Reports\ItemProfitReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * The COGS recalculation engine. For a date range it works out the cost every sold line carries under the company's method on
 * the line's day, shows it beside what the reports use now (preview, nothing written), and on commit locks that cost on each
 * sale line (`sell_line_costs`). Nothing is posted to the ledger. A range that touches a locked period (period lock or inventory
 * close) is refused as a whole; an override header does not apply, a locked period's cost is not rewritten.
 */
class CostRecalculation
{
    public function __construct(
        private readonly ItemProfitReport $report,
        private readonly PeriodLock $periodLock,
        private readonly InventoryClose $inventoryClose,
        private readonly ValuationMethod $methods,
    ) {}

    /**
     * @return array{method: string, from: string, to: string, locked_through: string|null, blocked: bool, lines: list<array<string, mixed>>, summary: array<string, float|int>}
     */
    public function preview(int $companyId, string $from, string $to, int $limit = 500): array
    {
        $lockedThrough = $this->lockedThrough($companyId);
        $filters = ['start_date' => $from, 'end_date' => $to];
        $current = $this->report->costedLines($companyId, null, $filters, true)->keyBy('id');
        $fresh = $this->report->costedLines($companyId, null, $filters, false);
        $lines = [];
        $before = 0.0;
        $after = 0.0;
        $changed = 0;
        $uncosted = 0;

        foreach ($fresh as $row) {
            $netBase = $this->netBase($row);
            $old = $current->get($row->id);
            $oldUnit = $this->baselineUnit($old);
            $newUnit = $row->average_cost === null ? null : round((float) $row->average_cost, 4);

            if ($newUnit === null) {
                $uncosted++;
            }

            $oldCost = $oldUnit === null ? null : round($oldUnit * $netBase, 2);
            $newCost = $newUnit === null ? null : round($newUnit * $netBase, 2);
            $before += (float) $oldCost;
            $after += (float) $newCost;

            if ($oldCost !== $newCost) {
                $changed++;
            }

            if (count($lines) < $limit) {
                $lines[] = [
                    'sell_line_id' => (int) $row->id, 'transaction_id' => (int) $row->txn_id, 'invoice_no' => $row->invoice_no,
                    'date' => substr((string) $row->transaction_date, 0, 10), 'product' => (string) ($row->product_name ?? ''),
                    'base_quantity' => $netBase, 'current_unit_cost' => $oldUnit, 'new_unit_cost' => $newUnit,
                    'current_cost' => $oldCost, 'new_cost' => $newCost,
                    'difference' => $oldCost === null || $newCost === null ? null : round($newCost - $oldCost, 2),
                ];
            }
        }

        return [
            'method' => $this->methods->methodOn($companyId, $to)['method'],
            'from' => $from,
            'to' => $to,
            'locked_through' => $lockedThrough,
            'blocked' => $lockedThrough !== null && $from <= $lockedThrough,
            'lines' => $lines,
            'summary' => [
                'lines' => $fresh->count(), 'changed' => $changed, 'uncosted' => $uncosted,
                'cost_before' => round($before, 2), 'cost_after' => round($after, 2), 'difference' => round($after - $before, 2),
            ],
        ];
    }

    /**
     * @return array{run_id: int, lines: int, cost_before: float, cost_after: float}
     *
     * @throws ValidationException
     */
    public function commit(int $companyId, string $from, string $to, ?int $userId): array
    {
        $lockedThrough = $this->lockedThrough($companyId);

        if ($lockedThrough !== null && $from <= $lockedThrough) {
            throw ValidationException::withMessages(['from' => ["The books are locked through {$lockedThrough}; costs of a locked period are not recalculated. Start after that day."]]);
        }

        if (! Schema::hasTable('sell_line_costs')) {
            throw ValidationException::withMessages(['from' => ['The valuation tables are not installed yet.']]);
        }

        $method = $this->methods->methodOn($companyId, $to)['method'];
        $rows = $this->report->costedLines($companyId, null, ['start_date' => $from, 'end_date' => $to], false);
        $current = $this->report->costedLines($companyId, null, ['start_date' => $from, 'end_date' => $to], true)->keyBy('id');

        return DB::transaction(function () use ($rows, $current, $companyId, $from, $to, $method, $userId): array {
            $before = 0.0;
            $after = 0.0;
            $count = 0;

            foreach ($rows as $row) {
                if ($row->average_cost === null) {
                    continue;
                }

                $netBase = $this->netBase($row);
                $unit = round((float) $row->average_cost, 4);
                $old = $current->get($row->id);
                $baseline = $this->baselineUnit($old);
                $before += $baseline === null ? 0.0 : round($baseline * $netBase, 2);
                $after += round($unit * $netBase, 2);
                $count++;

                SellLineCost::query()->updateOrCreate(['sell_line_id' => (int) $row->id], [
                    'company_id' => $companyId, 'transaction_id' => (int) $row->txn_id, 'variation_id' => (int) $row->variation_id,
                    'method' => $method, 'cost_date' => substr((string) $row->transaction_date, 0, 10), 'base_quantity' => $netBase,
                    'unit_cost' => $unit, 'cost' => round($unit * $netBase, 2), 'locked_by' => $userId, 'locked_at' => now(),
                ]);
            }

            $run = CostRecalculationRun::query()->create([
                'company_id' => $companyId, 'from_date' => $from, 'to_date' => $to, 'method' => $method, 'lines' => $count,
                'cost_before' => round($before, 2), 'cost_after' => round($after, 2), 'run_by' => $userId,
            ]);

            return ['run_id' => (int) $run->id, 'lines' => $count, 'cost_before' => round($before, 2), 'cost_after' => round($after, 2)];
        });
    }

    /**
     * What a line is compared against: the cost a recalculation locked on it, or the weighted average the reports used before
     * any method was chosen.
     */
    private function baselineUnit(?stdClass $row): ?float
    {
        if ($row === null) {
            return null;
        }

        $unit = $row->cost_locked ? $row->average_cost : $row->weighted_average_cost;

        return $unit === null ? null : round((float) $unit, 4);
    }

    private function lockedThrough(int $companyId): ?string
    {
        $dates = array_filter([
            $this->periodLock->lockedThrough($companyId),
            $this->inventoryClose->lockedThrough($companyId, null),
        ]);

        return $dates === [] ? null : (string) max($dates);
    }

    private function netBase(stdClass $row): float
    {
        $packing = $row->packing_qty !== null && (float) $row->packing_qty >= 1 ? (float) $row->packing_qty : 1.0;

        return round(((float) $row->quantity - (float) $row->quantity_returned) * $packing, 4);
    }
}
