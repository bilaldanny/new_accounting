<?php

namespace App\Services;

use App\Models\PurchaseLandedCost;
use App\Models\PurchaseLine;
use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The landed cost of a purchase: its extra costs spread over its lines.
 *
 * A cost with basis `value` is shared by each line's value (`purchase_rate x quantity x packing`), one with
 * basis `quantity` by each line's base-unit quantity (`quantity x packing`). A line's share is rounded to the
 * cent and the last line takes the rounding remainder, so the shares always add up to exactly the costs. The
 * share is stored on the line (`landed_cost`, for its whole quantity) where Reports\AverageCost reads it, so
 * stock valuation and item profit pick it up without a second definition of cost.
 */
class LandedCost
{
    /**
     * Replaces the purchase's costs with `$costs` and re-spreads them.
     *
     * @param  list<array{type: string, amount: float|int|string, allocation_basis?: ?string, description?: ?string}>  $costs
     *
     * @throws ValidationException
     */
    public function replace(Transaction $purchase, array $costs): void
    {
        $this->assertPurchase($purchase);

        DB::transaction(function () use ($purchase, $costs): void {
            PurchaseLandedCost::query()->where('transaction_id', $purchase->id)->delete();

            foreach ($costs as $cost) {
                PurchaseLandedCost::query()->create([
                    'transaction_id' => $purchase->id,
                    'type' => $cost['type'],
                    'description' => $cost['description'] ?? null,
                    'amount' => round((float) $cost['amount'], 2),
                    'allocation_basis' => $cost['allocation_basis'] ?? 'value',
                    'created_by' => Auth::id(),
                ]);
            }

            $this->allocate($purchase);
        });
    }

    /**
     * Writes each line's share of the purchase's costs.
     *
     * @throws ValidationException
     */
    public function allocate(Transaction $purchase): void
    {
        $this->assertPurchase($purchase);

        $lines = PurchaseLine::query()->where('transaction_id', $purchase->id)->orderBy('id')->get();
        $costs = PurchaseLandedCost::query()->where('transaction_id', $purchase->id)->get();

        $shares = array_fill_keys($lines->pluck('id')->all(), 0.0);

        foreach (PurchaseLandedCost::BASES as $basis) {
            $total = round((float) $costs->where('allocation_basis', $basis)->sum('amount'), 2);

            if ($total == 0.0) {
                continue;
            }

            $weights = $lines->mapWithKeys(fn (PurchaseLine $line): array => [$line->id => $this->weight($line, $basis)]);
            $weightSum = (float) $weights->sum();

            if ($weightSum <= 0) {
                throw ValidationException::withMessages(['costs' => ['The purchase has no lines with a '.($basis === 'value' ? 'value' : 'quantity').' to spread the costs over.']]);
            }

            $given = 0.0;
            $lastId = $lines->last()->id;

            foreach ($lines as $line) {
                $share = $line->id === $lastId
                    ? round($total - $given, 2)
                    : round($total * $weights[$line->id] / $weightSum, 2);

                $given = round($given + $share, 2);
                $shares[$line->id] = round($shares[$line->id] + $share, 2);
            }
        }

        foreach ($lines as $line) {
            $line->forceFill(['landed_cost' => $shares[$line->id]])->save();
        }
    }

    /**
     * The costs and each line's share and landed unit cost, for the screen.
     *
     * @return array<string, mixed>
     */
    public function detail(Transaction $purchase): array
    {
        $costs = PurchaseLandedCost::query()->where('transaction_id', $purchase->id)->orderBy('id')->get();

        $lines = PurchaseLine::query()
            ->where('transaction_id', $purchase->id)
            ->with(['product:id,name', 'productdetail:id,name'])
            ->orderBy('id')
            ->get()
            ->map(function (PurchaseLine $line): array {
                $baseQuantity = (float) $line->quantity * $this->packing($line);

                return [
                    'id' => $line->id,
                    'product' => $line->product?->name,
                    'quantity' => (float) $line->quantity,
                    'purchase_rate' => (float) $line->purchase_rate,
                    'line_value' => round((float) $line->purchase_rate * $baseQuantity, 2),
                    'landed_cost' => round((float) $line->landed_cost, 2),
                    'landed_unit_cost' => $baseQuantity > 0 ? round(((float) $line->purchase_rate * $baseQuantity + (float) $line->landed_cost) / $baseQuantity, 4) : null,
                ];
            });

        return [
            'purchase' => [
                'id' => $purchase->id,
                'invoice_no' => $purchase->invoice_no,
                'status' => $purchase->status,
                'transaction_date' => $purchase->transaction_date?->toDateString(),
            ],
            'costs' => $costs->map(fn (PurchaseLandedCost $cost): array => [
                'id' => $cost->id,
                'type' => $cost->type,
                'description' => $cost->description,
                'amount' => $cost->amount,
                'allocation_basis' => $cost->allocation_basis,
            ])->all(),
            'total_costs' => round((float) $costs->sum('amount'), 2),
            'lines' => $lines->all(),
        ];
    }

    private function weight(PurchaseLine $line, string $basis): float
    {
        $quantity = (float) $line->quantity * $this->packing($line);

        return $basis === 'quantity' ? $quantity : (float) $line->purchase_rate * $quantity;
    }

    private function packing(PurchaseLine $line): float
    {
        return $line->packing_qty === null || (float) $line->packing_qty < 1 ? 1.0 : (float) $line->packing_qty;
    }

    /**
     * @throws ValidationException
     */
    private function assertPurchase(Transaction $purchase): void
    {
        if ($purchase->type !== Transaction::TYPE_PURCHASE) {
            throw ValidationException::withMessages(['transaction_id' => ['Landed costs belong to purchase orders.']]);
        }

        if ($purchase->status === 'draft') {
            throw ValidationException::withMessages(['transaction_id' => ['A draft purchase has no cost yet: save it as a purchase first.']]);
        }
    }
}
