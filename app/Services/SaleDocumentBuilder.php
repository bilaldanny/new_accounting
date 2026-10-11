<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductDetail;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Makes an ordinary sale from a plain list of lines (product, quantity, optional price and discount) for the features that
 * create sales on someone's behalf: a sales order being invoiced, a recurring invoice, an imported file, a web-shop order.
 * It always goes through Transaction::createSell, so numbering, tax, credit limit, stock check, price rules, the ledger
 * posting (for a final sale) and every lock behave exactly as when the sale form saves the same sale.
 */
class SaleDocumentBuilder
{
    public function __construct(private readonly PriceResolver $prices) {}

    /**
     * Turns product rows into sale lines, pricing a row that names no price the way the sale form does (customer price list
     * first, then the default sell price).
     *
     * @param  list<array<string, mixed>>  $rows  each with product_id (or a Product), quantity, and optionally variation_id, unit_price, discount
     * @return array{lines: list<array<string, mixed>>, total: float}
     *
     * @throws ValidationException
     */
    public function lines(int $companyId, ?int $branchId, ?int $contactId, array $rows, string $field = 'lines'): array
    {
        $lines = [];
        $total = 0.0;

        foreach ($rows as $index => $row) {
            $product = Product::query()->where('company_id', $companyId)->where('active', 1)->find((int) ($row['product_id'] ?? 0));

            if ($product === null) {
                throw ValidationException::withMessages(["{$field}.{$index}.product_id" => ['Choose an active product of this company.']]);
            }

            $quantity = (float) ($row['quantity'] ?? 0);

            if ($quantity <= 0) {
                throw ValidationException::withMessages(["{$field}.{$index}.quantity" => ['Enter a quantity above zero.']]);
            }

            $variation = ! empty($row['variation_id'])
                ? ProductDetail::query()->where('product_id', $product->id)->find((int) $row['variation_id'])
                : ProductDetail::query()->where('product_id', $product->id)->orderBy('id')->first();

            if ($variation === null) {
                throw ValidationException::withMessages(["{$field}.{$index}.variation_id" => ['That variation does not belong to the product.']]);
            }

            $unitPrice = isset($row['unit_price']) && $row['unit_price'] !== ''
                ? (float) $row['unit_price']
                : (float) $this->prices->resolve($companyId, $branchId, $contactId, (int) $product->id, (int) $variation->id, $quantity)['price'];
            $discount = max((float) ($row['discount'] ?? 0), 0);
            $net = max($unitPrice - $discount, 0);
            $subtotal = round($net * $quantity, 2);
            $total += $subtotal;

            $lines[] = [
                'product_id' => (int) $product->id,
                'variation_id' => (int) $variation->id,
                'itemtype_id' => $product->itemtype_id,
                'unit_id' => $product->unit_id,
                'quantity' => $quantity,
                'packing_qty' => 1,
                'unit_price' => $unitPrice,
                'discount_percent' => $discount,
                'unit_price_after_discount' => $net,
                'row_subtotal' => $subtotal,
            ];
        }

        return ['lines' => $lines, 'total' => round($total, 2)];
    }

    /**
     * @param  array<string, mixed>  $header  company_id, branch_id, contact_id, transaction_date, and optionally note, pay_term,
     *                                        pay_type, discount_amount, shipping_charges, warehouse_id, cashier_id, is_pos
     * @param  list<array<string, mixed>>  $lines  from lines()
     */
    public function create(array $header, array $lines, float $total, string $status = 'draft'): Transaction
    {
        $discount = max((float) ($header['discount_amount'] ?? 0), 0);
        $shipping = max((float) ($header['shipping_charges'] ?? 0), 0);

        return Transaction::createSell(new Request([
            'company_id' => $header['company_id'],
            'branch_id' => $header['branch_id'],
            'contact_id' => $header['contact_id'],
            'transaction_date' => $header['transaction_date'] ?? now()->toDateString(),
            'pay_term' => $header['pay_term'] ?? null,
            'pay_type' => $header['pay_type'] ?? 'day',
            'status' => $status,
            'discount_type' => $discount > 0 ? 'fixed' : 'none',
            'discount_amount' => $discount,
            'shipping_charges' => $shipping,
            'additional_note' => $header['note'] ?? null,
            'final_amount' => round($total - $discount + $shipping, 2),
            'total_item' => count($lines),
            'is_direct' => false,
            'is_pos' => (bool) ($header['is_pos'] ?? false),
            'warehouse_id' => $header['warehouse_id'] ?? null,
            'cashier_id' => $header['cashier_id'] ?? null,
            'selllines' => $lines,
        ]));
    }
}
