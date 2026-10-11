<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stock held back for a customer or an order. It never changes the stock figure (StockMovements stays the one definition);
 * with the company's `reservation_mode` set to `enforce` a sale may take only what is on hand less what others hold.
 */
class StockReservation extends Model
{
    use Auditable;
    use ScopedToCompany;

    public const STATUSES = ['active', 'released', 'fulfilled'];

    protected bool $branchScoped = true;

    protected $fillable = [
        'company_id', 'branch_id', 'product_id', 'variation_id', 'contact_id', 'quantity', 'reference', 'status', 'expires_on', 'note',
        'reserved_by', 'closed_by', 'closed_at',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'expires_on' => 'date:Y-m-d', 'closed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
