<?php

namespace App\Models;

use App\Services\StockMovements;
use Database\Factories\PurchaseLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseLine extends Model
{
    /** @use HasFactory<PurchaseLineFactory> */
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'product_id',
        'variation_id',
        'itemtype_id',
        'unit_id',
        'quantity',
        'quantity_received',
        'qunatity_sold',
        'quantity_returned',
        'purchase_rate',
        'default_sell_price',
        'discount_percent',
        'margin',
        'quantity_adjustment',
        'pp_without_discount',
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
        $this->transaction_id = $transactionId;
        $this->product_id = Transaction::resolveScopedId($row['product_id'] ?? null);
        $this->variation_id = Transaction::resolveScopedId($row['variation_id'] ?? null);
        $this->itemtype_id = Transaction::resolveScopedId($row['itemtype_id'] ?? null);
        $this->unit_id = Transaction::resolveScopedId($row['unit_id'] ?? null);
        $this->quantity = self::resolveNumeric($row['quantity'] ?? 1, 1);
        $this->quantity_received = self::resolveNumeric($row['quantity_received'] ?? 0);
        $this->qunatity_sold = self::resolveNumeric($row['qunatity_sold'] ?? 0);
        $this->quantity_returned = self::resolveNumeric($row['quantity_returned'] ?? 0);
        $this->quantity_adjustment = self::resolveNumeric($row['quantity_adjustment'] ?? 0);
        $this->pp_without_discount = self::resolveNumeric($row['pp_without_discount'] ?? $row['purchase_rate'] ?? 0);
        $this->discount_percent = self::resolveNumeric($row['discount_percent'] ?? 0);
        $this->purchase_rate = self::resolveNumeric($row['purchase_rate'] ?? $row['purchase_price'] ?? 0);
        $this->margin = self::resolveNumeric($row['profit_percent'] ?? $row['margin'] ?? 0);
        $this->default_sell_price = self::resolveNumeric($row['default_sell_price'] ?? 0);
        $this->packing_qty = (int) self::resolveNumeric($row['packing_qty'] ?? 1, 1);
    }

    /**
     * Stock of one variation in the given unit. The definition lives in StockMovements: it is always
     * tracked in the product's base unit and includes sales and sale returns; a child unit gets the
     * whole units that fit. A null branch is the total across every branch, and $excludeSaleId leaves
     * one sale out (used to show a sale's own stock while it is being viewed or edited).
     */
    public static function currentStock(int $productId, int $variationId, int $unitId, ?int $branchId = null, ?int $excludeSaleId = null): float
    {
        return StockMovements::inUnit(
            StockMovements::baseStock($productId, $variationId, $branchId, $excludeSaleId),
            $productId,
            $variationId,
            $unitId,
        );
    }
}
