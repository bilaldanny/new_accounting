<?php

namespace App\Services;

use App\Models\PurchaseLine;
use App\Models\SellLine;

/**
 * The tax a sell or purchase return gives back, worked out from the lines of the document it returns.
 *
 * The tax of a line was fixed when the document was saved (line `tax_id` and `tax_amount`; an exempt line carries a zero amount
 * and keeps its rule). A return reverses that same tax on the same tax id in proportion to the quantity returned, so a full
 * return gives back exactly what was charged and a half return half of it, whichever way the prices were entered:
 *
 * - exclusive prices: the return is the value of the goods plus the tax on them;
 * - inclusive prices: the return is the value of the goods, which already holds the tax; the tax is shown carved out of it.
 *
 * A document whose lines name no tax (an untaxed one, or an old one that only has a tax amount on its header) is not touched:
 * the return stays quantity x rate with no tax, exactly as it always was.
 */
class ReturnTax
{
    /**
     * @param  iterable<SellLine|PurchaseLine>  $lines  the document's lines, with `quantity_returned` already set
     * @return array{taxed: bool, tax_amount: float, total_before_tax: float, final_amount: float}
     */
    public function compute(iterable $lines, bool $inclusive): array
    {
        $net = 0.0;
        $tax = 0.0;
        $taxed = false;

        foreach ($lines as $line) {
            $returned = (float) $line->quantity_returned;

            if ($returned <= 0.0) {
                continue;
            }

            $rate = $line instanceof SellLine ? (float) $line->unit_price_after_discount : (float) $line->purchase_rate;
            $net += $rate * $returned;

            if ($line->tax_id === null) {
                continue;
            }

            $taxed = true;
            $sold = (float) $line->quantity;
            $share = $sold > 0.0 ? min($returned / $sold, 1.0) : 1.0;
            $tax += round((float) $line->tax_amount * $share, 2);
        }

        $net = round($net, 2);
        $tax = round($tax, 2);

        if (! $taxed) {
            return ['taxed' => false, 'tax_amount' => 0.0, 'total_before_tax' => $net, 'final_amount' => $net];
        }

        return [
            'taxed' => true,
            'tax_amount' => $tax,
            'total_before_tax' => $inclusive ? round($net - $tax, 2) : $net,
            'final_amount' => $inclusive ? $net : round($net + $tax, 2),
        ];
    }
}
