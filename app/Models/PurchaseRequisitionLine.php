<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One requested product on a Purchase Requisition: what and how much, no price (the requisition
 * is a request, not yet a purchase).
 */
class PurchaseRequisitionLine extends Model
{
    protected $fillable = [
        'purchase_requisition_id',
        'product_id',
        'variation_id',
        'unit_id',
        'requested_quantity',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'requested_quantity' => 'float',
        ];
    }

    /**
     * @return BelongsTo<PurchaseRequisition, $this>
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
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
     * @param  array<string, mixed>  $row
     */
    public static function createFromRow(array $row, int $requisitionId): self
    {
        $line = new self;
        $line->fillFromRow($row, $requisitionId);
        $line->save();

        return $line;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function fillFromRow(array $row, int $requisitionId): void
    {
        $this->purchase_requisition_id = $requisitionId;
        $this->product_id = PurchaseRequisition::resolveScopedId($row['product_id'] ?? null);
        $this->variation_id = PurchaseRequisition::resolveScopedId($row['variation_id'] ?? null);
        $this->unit_id = PurchaseRequisition::resolveScopedId($row['unit_id'] ?? null);
        $this->requested_quantity = round((float) ($row['requested_quantity'] ?? $row['quantity'] ?? 0), 2);
        $this->note = $row['note'] ?? null;
    }
}
