<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's order as a document of its own, before any invoice: draft or pending approval, confirmed, then invoiced in one or
 * several invoices (each quantity tracked per line) until it is fully invoiced; or cancelled. It posts nothing and holds no stock;
 * the invoice made from it is an ordinary sale.
 */
class SalesOrder extends Model
{
    use Auditable;
    use ScopedToCompany;

    public const STATUSES = ['draft', 'pending_approval', 'confirmed', 'partially_invoiced', 'invoiced', 'cancelled'];

    protected bool $branchScoped = true;

    protected $fillable = [
        'company_id', 'branch_id', 'contact_id', 'order_no', 'order_date', 'expected_date', 'status', 'quotation_id', 'note',
        'discount_amount', 'shipping_charges', 'total', 'approved_by', 'approved_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date:Y-m-d', 'expected_date' => 'date:Y-m-d', 'approved_at' => 'datetime',
            'discount_amount' => 'float', 'shipping_charges' => 'float', 'total' => 'float',
        ];
    }

    /**
     * @return HasMany<SalesOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class);
    }
}
