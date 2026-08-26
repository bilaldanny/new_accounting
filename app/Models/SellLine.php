<?php

namespace App\Models;

use Database\Factories\SellLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellLine extends Model
{
    /** @use HasFactory<SellLineFactory> */
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'product_id',
        'variation_id',
        'itemtype_id',
        'unit_id',
        'quantity',
        'quantity_issue',
        'quantity_returned',
        'unit_price',
        'discount_percent',
        'unit_price_after_discount',
        'subtotal',
        'packing_qty',
    ];

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductDetail, $this>
     */
    public function productdetail(): BelongsTo
    {
        return $this->belongsTo(ProductDetail::class, 'variation_id');
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<ItemType, $this>
     */
    public function itemtype(): BelongsTo
    {
        return $this->belongsTo(ItemType::class, 'itemtype_id');
    }

    public static function resolveNumeric(mixed $value, float $default = 0): float
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return (float) $value;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function createFromRow(array $row, int $transactionId): self
    {
        $line = new self;
        $line->fillFromRow($row, $transactionId);
        $line->save();

        return $line;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function fillFromRow(array $row, int $transactionId): void
    {
        $quantity = self::resolveNumeric($row['quantity'] ?? 1, 1);
        $unitPrice = self::resolveNumeric($row['unit_price'] ?? $row['default_sell_price'] ?? 0);
        $discount = self::resolveNumeric($row['discount_percent'] ?? 0);
        $packingQty = (int) self::resolveNumeric($row['packing_qty'] ?? 1, 1);
        $priceAfterDiscount = self::resolveNumeric(
            $row['unit_price_after_discount'] ?? max($unitPrice - $discount, 0)
        );
        $subtotal = self::resolveNumeric(
            $row['row_subtotal'] ?? $row['subtotal'] ?? ($priceAfterDiscount * $quantity * max($packingQty, 1))
        );

        $this->transaction_id = $transactionId;
        $this->product_id = Transaction::resolveScopedId($row['product_id'] ?? null);
        $this->variation_id = Transaction::resolveScopedId($row['variation_id'] ?? null);
        $this->itemtype_id = Transaction::resolveScopedId($row['itemtype_id'] ?? null);
        $this->unit_id = Transaction::resolveScopedId($row['unit_id'] ?? null);
        $this->quantity = $quantity;
        $this->quantity_issue = self::resolveNumeric($row['quantity_issue'] ?? 0);
        $this->quantity_returned = self::resolveNumeric($row['quantity_returned'] ?? 0);
        $this->unit_price = $unitPrice;
        $this->discount_percent = $discount;
        $this->unit_price_after_discount = $priceAfterDiscount;
        $this->subtotal = $subtotal;
        $this->packing_qty = max($packingQty, 1);
    }
}
