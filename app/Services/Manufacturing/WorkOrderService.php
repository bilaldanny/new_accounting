<?php

namespace App\Services\Manufacturing;

use App\Models\Product;
use App\Models\Transaction;
use App\Services\Valuation\CostSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Work orders: planned from the active BOM, released by an approver, then material issues, direct labour and overhead, scrap and
 * quality checks, and receipts of the finished product with its by-products and co-products.
 *
 * Stock moves as completed stock adjustments (the kitting path: stock guards, branch, warehouse and the period lock apply); a material
 * issue is a negative line, a receipt a positive line carrying the production unit cost as `opening_cost`, which the average cost,
 * FIFO layers and the stock report already read, so the finished product is valued by whatever valuation method the company uses.
 *
 * Costing: the pool of a work order is what it holds in progress (materials + labour + overhead - what was absorbed by receipts -
 * abnormal scrap). A receipt absorbs the pool in proportion to the quantity received of what is still planned (the last receipt takes
 * the rest); by-products and co-products take their cost-share percent, the finished product the remainder. Normal scrap stays in
 * the cost of the good units; abnormal scrap is taken out as a loss at the average cost per planned unit.
 */
class WorkOrderService
{
    public function __construct(
        private readonly BomService $boms,
        private readonly ManufacturingSettings $settings,
        private readonly ManufacturingPosting $posting,
        private readonly CostSource $costs,
    ) {}

    /**
     * @throws ValidationException
     */
    public function create(int $companyId, int $branchId, int $productId, float $quantity, ?string $start, ?string $end, ?int $warehouseId, ?string $notes, ?int $userId): int
    {
        $bom = $this->boms->activeFor($companyId, $productId);

        if ($bom === null) {
            throw ValidationException::withMessages(['product_id' => ['This product has no active bill of materials.']]);
        }

        if ($quantity <= 0) {
            throw ValidationException::withMessages(['planned_qty' => ['Enter a quantity above zero.']]);
        }

        if ($start !== null && $end !== null && $end < $start) {
            throw ValidationException::withMessages(['planned_end' => ['The end date is before the start date.']]);
        }

        return (int) DB::transaction(function () use ($companyId, $branchId, $bom, $productId, $quantity, $start, $end, $warehouseId, $notes, $userId): int {
            $next = (int) DB::table('work_orders')->where('company_id', $companyId)->count() + 1;

            do {
                $number = 'WO-'.str_pad((string) $next++, 5, '0', STR_PAD_LEFT);
            } while (DB::table('work_orders')->where('company_id', $companyId)->where('wo_no', $number)->exists());

            $id = (int) DB::table('work_orders')->insertGetId([
                'company_id' => $companyId, 'branch_id' => $branchId, 'wo_no' => $number, 'product_id' => $productId, 'variation_id' => (int) $bom->variation_id, 'bom_id' => $bom->id,
                'bom_version' => $bom->version, 'planned_qty' => round($quantity, 4), 'status' => 'draft', 'planned_start' => $start, 'planned_end' => $end, 'warehouse_id' => $warehouseId,
                'notes' => $notes !== null ? mb_substr(strip_tags($notes), 0, 300) : null, 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $factor = $quantity / max(0.0001, (float) $bom->output_qty);

            foreach (DB::table('bom_items')->where('bom_id', $bom->id)->get() as $item) {
                DB::table('work_order_materials')->insert([
                    'company_id' => $companyId, 'work_order_id' => $id, 'product_id' => $item->component_product_id, 'variation_id' => $item->component_variation_id,
                    'required_qty' => round((float) $item->quantity * $factor * (1 + (float) $item->scrap_percent / 100), 4), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $id;
        });
    }

    public function release(int $companyId, int $id, ?int $userId): void
    {
        $order = $this->find($companyId, $id);

        if ($order->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ['Only a draft work order can be released.']]);
        }

        DB::table('work_orders')->where('id', $id)->update(['status' => 'released', 'released_by' => $userId, 'released_at' => now(), 'updated_at' => now()]);
    }

    public function cancel(int $companyId, int $id): void
    {
        $order = $this->find($companyId, $id);

        if (! in_array($order->status, ['draft', 'released'], true) || $this->hasActivity($id)) {
            throw ValidationException::withMessages(['status' => ['Only a work order with no material issued, labour, scrap or receipt can be cancelled.']]);
        }

        DB::table('work_orders')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
    }

    /**
     * Issues materials to the work order: one completed stock adjustment with a negative line each.
     *
     * @param  list<array{product_id: int, variation_id?: int|null, quantity: float|int|string}>  $lines
     *
     * @throws ValidationException
     */
    public function issue(int $companyId, int $id, array $lines, string $date, ?int $userId): void
    {
        $order = $this->openOrder($companyId, $id);
        $this->posting->assertReady($companyId);
        $settings = $this->settings->all($companyId);
        $materials = DB::table('work_order_materials')->where('work_order_id', $id)->get();
        $costs = $this->costs->stockCosts($companyId, $date);
        $rows = [];

        foreach (array_values($lines) as $index => $line) {
            $quantity = round((float) ($line['quantity'] ?? 0), 4);
            $material = $materials->first(fn (object $m): bool => (int) $m->product_id === (int) ($line['product_id'] ?? 0)
                && (! isset($line['variation_id']) || (int) $m->variation_id === (int) $line['variation_id']));

            if ($material === null || $quantity <= 0) {
                throw ValidationException::withMessages(["lines.{$index}" => ['Issue a component of this work order, with a quantity above zero.']]);
            }

            if (! $settings['allow_over_issue'] && (float) $material->issued_qty + $quantity > (float) $material->required_qty + 0.00005) {
                throw ValidationException::withMessages(["lines.{$index}" => ['This is more than the work order requires.']]);
            }

            $rows[] = ['material' => $material, 'quantity' => $quantity, 'unit_cost' => round((float) ($costs[(int) $material->variation_id] ?? 0), 4)];
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['lines' => ['Choose at least one material to issue.']]);
        }

        DB::transaction(function () use ($companyId, $order, $rows, $date, $userId): void {
            $transaction = $this->adjust($order, $date, 'Issue to', array_map(fn (array $r): array => [
                'product_id' => (int) $r['material']->product_id, 'variation_id' => (int) $r['material']->variation_id, 'quantity_adjustment' => -$r['quantity'],
            ], $rows));
            $amount = 0.0;

            foreach ($rows as $row) {
                $cost = round($row['quantity'] * $row['unit_cost'], 2);
                $amount += $cost;
                DB::table('work_order_issues')->insert([
                    'company_id' => $companyId, 'work_order_id' => $order->id, 'product_id' => $row['material']->product_id, 'variation_id' => $row['material']->variation_id,
                    'quantity' => $row['quantity'], 'unit_cost' => $row['unit_cost'], 'stock_transaction_id' => $transaction->id, 'issued_on' => $date, 'issued_by' => $userId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('work_order_materials')->where('id', $row['material']->id)->update(['issued_qty' => round((float) $row['material']->issued_qty + $row['quantity'], 4), 'updated_at' => now()]);
            }

            $voucher = $this->posting->post($companyId, (int) $order->branch_id, $date, ['wip' => $amount], ['raw_material_inventory' => $amount], 'Work order '.$order->wo_no.': materials issued', $order->wo_no);

            if ($voucher !== null) {
                DB::table('work_order_issues')->where('stock_transaction_id', $transaction->id)->update(['voucher_id' => $voucher]);
            }

            DB::table('work_orders')->where('id', $order->id)->update([
                'material_cost' => round((float) $order->material_cost + $amount, 2), 'status' => 'in_progress', 'updated_at' => now(),
            ]);
        });
    }

    /**
     * Direct labour and overhead of the work order: hours at a labour and an overhead rate.
     *
     * @throws ValidationException
     */
    public function recordLabor(int $companyId, int $id, string $operation, float $hours, float $laborRate, float $overheadRate, ?int $employeeId, string $date, ?int $userId): void
    {
        $order = $this->openOrder($companyId, $id);
        $this->posting->assertReady($companyId);

        if ($hours <= 0 || $laborRate < 0 || $overheadRate < 0) {
            throw ValidationException::withMessages(['hours' => ['Enter hours above zero and rates of zero or more.']]);
        }

        if ($employeeId !== null && ! DB::table('employees')->where('company_id', $companyId)->where('id', $employeeId)->exists()) {
            throw ValidationException::withMessages(['employee_id' => ['Choose an employee of this company.']]);
        }

        $labor = round($hours * $laborRate, 2);
        $overhead = round($hours * $overheadRate, 2);

        DB::transaction(function () use ($companyId, $order, $operation, $hours, $laborRate, $overheadRate, $employeeId, $date, $userId, $labor, $overhead): void {
            $voucher = $this->posting->post($companyId, (int) $order->branch_id, $date, ['wip' => $labor + $overhead], ['labor_applied' => $labor, 'overhead_applied' => $overhead], 'Work order '.$order->wo_no.': '.$operation, $order->wo_no);
            DB::table('work_order_labor')->insert([
                'company_id' => $companyId, 'work_order_id' => $order->id, 'employee_id' => $employeeId, 'operation' => mb_substr(strip_tags($operation), 0, 100), 'hours' => round($hours, 2),
                'labor_rate' => $laborRate, 'overhead_rate' => $overheadRate, 'labor_cost' => $labor, 'overhead_cost' => $overhead, 'voucher_id' => $voucher, 'worked_on' => $date,
                'recorded_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('work_orders')->where('id', $order->id)->update([
                'labor_cost' => round((float) $order->labor_cost + $labor, 2), 'overhead_cost' => round((float) $order->overhead_cost + $overhead, 2), 'status' => 'in_progress', 'updated_at' => now(),
            ]);
        });
    }

    /**
     * Records the BOM's own operations (their minutes and rates, in proportion to the planned quantity) as the work order's labour.
     * Allowed once per work order.
     */
    public function applyStandardLabor(int $companyId, int $id, string $date, ?int $userId): void
    {
        $order = $this->openOrder($companyId, $id);

        if (DB::table('work_order_labor')->where('work_order_id', $id)->where('operation', 'like', '[standard]%')->exists()) {
            throw ValidationException::withMessages(['labor' => ['The standard labour of the bill of materials was already applied to this work order.']]);
        }

        $bom = $this->boms->find($companyId, (int) $order->bom_id);
        $factor = (float) $order->planned_qty / max(0.0001, (float) $bom->output_qty);
        $operations = DB::table('bom_operations')->where('bom_id', $bom->id)->orderBy('sequence')->get();

        if ($operations->isEmpty()) {
            throw ValidationException::withMessages(['labor' => ['The bill of materials has no operations.']]);
        }

        foreach ($operations as $operation) {
            $this->recordLabor($companyId, $id, '[standard] '.$operation->name, round((float) $operation->minutes / 60 * $factor, 2) ?: 0.01, (float) $operation->labor_rate_per_hour, (float) $operation->overhead_rate_per_hour, null, $date, $userId);
        }
    }

    /**
     * Scrap. Normal scrap is part of the cost of the good units; abnormal scrap is taken out of the work order as a loss at the average
     * cost per planned unit of what it holds so far.
     *
     * @throws ValidationException
     */
    public function recordScrap(int $companyId, int $id, float $quantity, bool $isNormal, ?string $reason, string $date, ?int $userId): void
    {
        $order = $this->openOrder($companyId, $id);
        $this->posting->assertReady($companyId);

        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => ['Enter a scrap quantity above zero.']]);
        }

        DB::transaction(function () use ($companyId, $order, $quantity, $isNormal, $reason, $date, $userId): void {
            $cost = 0.0;
            $voucher = null;

            if (! $isNormal) {
                $pool = $this->pool($order);
                $perUnit = (float) $order->planned_qty > 0 ? ((float) $order->material_cost + (float) $order->labor_cost + (float) $order->overhead_cost) / (float) $order->planned_qty : 0.0;
                $cost = round(min($pool, $perUnit * $quantity), 2);
                $voucher = $this->posting->post($companyId, (int) $order->branch_id, $date, ['scrap_loss' => $cost], ['wip' => $cost], 'Work order '.$order->wo_no.': abnormal scrap', $order->wo_no);
            }

            DB::table('work_order_scraps')->insert([
                'company_id' => $companyId, 'work_order_id' => $order->id, 'quantity' => round($quantity, 4), 'is_normal' => $isNormal, 'reason' => $reason !== null ? mb_substr(strip_tags($reason), 0, 200) : null,
                'cost' => $cost, 'voucher_id' => $voucher, 'recorded_on' => $date, 'recorded_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('work_orders')->where('id', $order->id)->update([
                'scrap_qty' => round((float) $order->scrap_qty + $quantity, 4), 'scrap_loss' => round((float) $order->scrap_loss + $cost, 2), 'status' => 'in_progress', 'updated_at' => now(),
            ]);
        });
    }

    /**
     * A production quality check. `scrapFailed` records the failed quantity as abnormal scrap.
     */
    public function recordCheck(int $companyId, int $id, string $name, string $result, float $inspected, float $failed, ?string $note, bool $scrapFailed, string $date, ?int $userId): void
    {
        $order = $this->openOrder($companyId, $id);

        if (! in_array($result, ['pass', 'fail'], true) || $inspected < 0 || $failed < 0 || $failed > $inspected) {
            throw ValidationException::withMessages(['result' => ['Enter pass or fail, a quantity inspected and a failed quantity that is not more than it.']]);
        }

        DB::transaction(function () use ($companyId, $order, $name, $result, $inspected, $failed, $note, $scrapFailed, $date, $userId): void {
            DB::table('work_order_checks')->insert([
                'company_id' => $companyId, 'work_order_id' => $order->id, 'check_name' => mb_substr(strip_tags($name), 0, 100), 'result' => $result, 'qty_inspected' => round($inspected, 4),
                'qty_failed' => round($failed, 4), 'note' => $note !== null ? mb_substr(strip_tags($note), 0, 200) : null, 'inspected_by' => $userId, 'inspected_on' => $date,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            if ($scrapFailed && $failed > 0) {
                $this->recordScrap($companyId, (int) $order->id, $failed, false, 'Failed check: '.$name, $date, $userId);
            }
        });
    }

    /**
     * Receives finished goods (and the BOM's by-products and co-products in proportion) into stock at the production cost.
     *
     * @return array{finished_unit_cost: float, absorbed: float, outputs: list<array<string, mixed>>}
     *
     * @throws ValidationException
     */
    public function receive(int $companyId, int $id, float $quantity, string $date, bool $backflush, ?int $userId): array
    {
        $order = $this->openOrder($companyId, $id);
        $this->posting->assertReady($companyId);
        $settings = $this->settings->all($companyId);

        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => ['Enter a quantity above zero.']]);
        }

        if ($settings['require_qc_before_receipt']) {
            $passed = (float) DB::table('work_order_checks')->where('work_order_id', $id)->sum(DB::raw('qty_inspected - qty_failed'));

            if ($passed + 0.00005 < (float) $order->produced_qty + $quantity) {
                throw ValidationException::withMessages(['quantity' => ['Only '.round($passed - (float) $order->produced_qty, 4).' unit(s) passed a quality check and are not received yet.']]);
            }
        }

        return DB::transaction(function () use ($companyId, $order, $quantity, $date, $backflush, $userId): array {
            if ($backflush) {
                $this->backflush($companyId, $order, $quantity, $date, $userId);
                $order = $this->find($companyId, (int) $order->id);
            }

            $pool = $this->pool($order);
            $remainingPlanned = max((float) $order->planned_qty - (float) $order->produced_qty, $quantity);
            $isLast = $quantity + 0.00005 >= $remainingPlanned;
            $share = round($isLast ? $pool : $pool * $quantity / $remainingPlanned, 2);
            $bom = $this->boms->find($companyId, (int) $order->bom_id);
            $factor = $quantity / max(0.0001, (float) $bom->output_qty);
            $outputs = [];
            $byValue = 0.0;

            foreach (DB::table('bom_outputs')->where('bom_id', $bom->id)->get() as $output) {
                $outQty = round((float) $output->quantity * $factor, 4);
                $value = round($share * (float) $output->cost_share_percent / 100, 2);
                $byValue += $value;
                $outputs[] = ['product_id' => (int) $output->product_id, 'variation_id' => (int) $output->variation_id, 'kind' => $output->kind, 'quantity' => $outQty, 'value' => $value];
            }

            $finishedValue = round($share - $byValue, 2);
            $unit = round($finishedValue / $quantity, 4);
            $lines = [['product_id' => (int) $order->product_id, 'variation_id' => (int) $order->variation_id, 'quantity_adjustment' => $quantity, 'opening_cost' => $unit]];

            foreach ($outputs as $output) {
                $lines[] = ['product_id' => $output['product_id'], 'variation_id' => $output['variation_id'], 'quantity_adjustment' => $output['quantity'], 'opening_cost' => $output['quantity'] > 0 ? round($output['value'] / $output['quantity'], 4) : 0];
            }

            $transaction = $this->adjust($order, $date, 'Receipt from', $lines);
            $voucher = $this->posting->post($companyId, (int) $order->branch_id, $date, ['finished_goods_inventory' => $share], ['wip' => $share], 'Work order '.$order->wo_no.': finished goods received', $order->wo_no);

            DB::table('work_order_receipts')->insert([
                'company_id' => $companyId, 'work_order_id' => $order->id, 'product_id' => $order->product_id, 'variation_id' => $order->variation_id, 'kind' => 'finished', 'quantity' => round($quantity, 4),
                'unit_cost' => $unit, 'total_cost' => $finishedValue, 'stock_transaction_id' => $transaction->id, 'voucher_id' => $voucher, 'received_on' => $date, 'received_by' => $userId,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach ($outputs as $output) {
                DB::table('work_order_receipts')->insert([
                    'company_id' => $companyId, 'work_order_id' => $order->id, 'product_id' => $output['product_id'], 'variation_id' => $output['variation_id'], 'kind' => $output['kind'],
                    'quantity' => $output['quantity'], 'unit_cost' => $output['quantity'] > 0 ? round($output['value'] / $output['quantity'], 4) : 0, 'total_cost' => $output['value'],
                    'stock_transaction_id' => $transaction->id, 'received_on' => $date, 'received_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $produced = round((float) $order->produced_qty + $quantity, 4);
            $done = $produced + 0.00005 >= (float) $order->planned_qty;
            DB::table('work_orders')->where('id', $order->id)->update([
                'produced_qty' => $produced, 'absorbed_cost' => round((float) $order->absorbed_cost + $share, 2), 'fg_unit_cost' => $unit, 'status' => $done ? 'completed' : 'in_progress',
                'completed_at' => $done ? now() : null, 'updated_at' => now(),
            ]);

            return ['finished_unit_cost' => $unit, 'absorbed' => $share, 'outputs' => $outputs];
        });
    }

    /**
     * Closes a work order with something received: the cost still held (labour recorded after the last receipt, an unfinished batch)
     * is taken out as a variance loss, so no work order stays in progress for ever.
     */
    public function close(int $companyId, int $id, string $date): float
    {
        $order = $this->find($companyId, $id);

        if (! in_array($order->status, ['in_progress', 'completed'], true) || (float) $order->produced_qty <= 0) {
            throw ValidationException::withMessages(['status' => ['Only a work order that received finished goods can be closed.']]);
        }

        $this->posting->assertReady($companyId);

        return (float) DB::transaction(function () use ($companyId, $order, $date): float {
            $left = max(0.0, round($this->pool($order), 2));
            $voucher = $this->posting->post($companyId, (int) $order->branch_id, $date, ['scrap_loss' => $left], ['wip' => $left], 'Work order '.$order->wo_no.': closing variance', $order->wo_no);
            DB::table('work_orders')->where('id', $order->id)->update([
                'scrap_loss' => round((float) $order->scrap_loss + $left, 2), 'status' => 'completed', 'completed_at' => $order->completed_at ?? now(), 'updated_at' => now(),
            ]);

            if ($voucher !== null && $left > 0) {
                DB::table('work_order_scraps')->insert([
                    'company_id' => $companyId, 'work_order_id' => $order->id, 'quantity' => 0, 'is_normal' => false, 'reason' => 'Closing variance', 'cost' => $left, 'voucher_id' => $voucher,
                    'recorded_on' => $date, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $left;
        });
    }

    /**
     * What a work order holds in progress: materials + labour + overhead - absorbed by receipts - taken out as loss.
     */
    public function pool(object $order): float
    {
        return round((float) $order->material_cost + (float) $order->labor_cost + (float) $order->overhead_cost - (float) $order->absorbed_cost - (float) $order->scrap_loss, 2);
    }

    public function find(int $companyId, int $id): object
    {
        $order = DB::table('work_orders')->where('company_id', $companyId)->where('id', $id)->first();
        abort_if($order === null, 404);

        return $order;
    }

    // ------------------------------------------------------------------------------------------------------------------

    private function openOrder(int $companyId, int $id): object
    {
        $order = $this->find($companyId, $id);

        if (! in_array($order->status, ['released', 'in_progress'], true)) {
            throw ValidationException::withMessages(['status' => ['The work order must be released and not completed or cancelled.']]);
        }

        return $order;
    }

    private function hasActivity(int $id): bool
    {
        foreach (['work_order_issues', 'work_order_receipts', 'work_order_scraps', 'work_order_labor'] as $table) {
            if (DB::table($table)->where('work_order_id', $id)->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Issues what the received quantity needs and was not issued yet (required x produced share - already issued).
     */
    private function backflush(int $companyId, object $order, float $quantity, string $date, ?int $userId): void
    {
        $share = min(1.0, ((float) $order->produced_qty + $quantity) / max(0.0001, (float) $order->planned_qty));
        $lines = [];

        foreach (DB::table('work_order_materials')->where('work_order_id', $order->id)->get() as $material) {
            $missing = round((float) $material->required_qty * $share - (float) $material->issued_qty, 4);

            if ($missing > 0) {
                $lines[] = ['product_id' => (int) $material->product_id, 'variation_id' => (int) $material->variation_id, 'quantity' => $missing];
            }
        }

        if ($lines !== []) {
            $this->issue($companyId, (int) $order->id, $lines, $date, $userId);
        }
    }

    /**
     * One completed stock adjustment for the work order, through the same path kitting uses.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function adjust(object $order, string $date, string $verb, array $rows): Transaction
    {
        $units = Product::query()->whereIn('id', array_column($rows, 'product_id'))->pluck('unit_id', 'id');
        $lines = array_map(fn (array $row): array => $row + ['unit_id' => $units[$row['product_id']] ?? null], $rows);

        return Transaction::createAdjustment((object) [
            'company_id' => $order->company_id, 'branch_id' => $order->branch_id, 'warehouse_id' => $order->warehouse_id, 'transaction_date' => $date, 'adjustment_type' => 'normal',
            'additional_note' => $verb.' work order '.$order->wo_no, 'status' => 'completed', 'total_item' => count($lines), 'purchaselines' => $lines,
        ]);
    }
}
