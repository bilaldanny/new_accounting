<?php

namespace App\Services\Valuation;

/**
 * The cost layers of one variation in the order they arrived (oldest first), and the arithmetic FIFO needs over them: what the
 * first `c` units cost, and what the newest `r` units cost. Units asked for beyond the layers are costed at the last layer's
 * unit cost (the last known cost), so a sale before a purchase never gets a zero.
 */
final class VariationLayers
{
    /** @var list<array{date: string, qty: float, cost: float}> */
    private array $layers = [];

    private float $totalQty = 0.0;

    private float $totalCost = 0.0;

    public function add(string $date, float $qty, float $cost): void
    {
        if ($qty <= 0) {
            return;
        }

        $this->layers[] = ['date' => $date, 'qty' => $qty, 'cost' => $cost];
        $this->totalQty += $qty;
        $this->totalCost += $cost;
    }

    public function isEmpty(): bool
    {
        return $this->layers === [];
    }

    public function totalQty(): float
    {
        return $this->totalQty;
    }

    /**
     * Units that arrived on or before a day.
     */
    public function qtyUpTo(string $date): float
    {
        $qty = 0.0;

        foreach ($this->layers as $layer) {
            if ($layer['date'] <= $date) {
                $qty += $layer['qty'];
            }
        }

        return $qty;
    }

    /**
     * The cost of consuming the first `$units` units, oldest layer first.
     */
    public function cost(float $units): float
    {
        $cost = 0.0;
        $left = max($units, 0.0);

        foreach ($this->layers as $layer) {
            if ($left <= 0) {
                break;
            }

            $take = min($left, $layer['qty']);
            $cost += $layer['cost'] * $take / $layer['qty'];
            $left -= $take;
        }

        if ($left > 0) {
            $cost += $left * $this->lastUnitCost();
        }

        return $cost;
    }

    /**
     * The unit cost of the layer the next unit to be consumed comes from, after `$consumed` units are gone.
     */
    public function headUnitCost(float $consumed): ?float
    {
        if ($this->layers === []) {
            return null;
        }

        $left = max($consumed, 0.0);

        foreach ($this->layers as $layer) {
            if ($left < $layer['qty'] - 1e-9) {
                return $layer['cost'] / $layer['qty'];
            }

            $left -= $layer['qty'];
        }

        return $this->lastUnitCost();
    }

    /**
     * The unit cost of what is still on the shelf when `$remaining` units are left: the newest layers.
     */
    public function remainingUnitCost(float $remaining): ?float
    {
        if ($this->layers === []) {
            return null;
        }

        $remaining = min(max($remaining, 0.0), $this->totalQty);

        if ($remaining <= 1e-9) {
            return $this->lastUnitCost();
        }

        return ($this->totalCost - $this->cost($this->totalQty - $remaining)) / $remaining;
    }

    private function lastUnitCost(): float
    {
        $last = end($this->layers);

        return $last === false ? 0.0 : $last['cost'] / $last['qty'];
    }
}
