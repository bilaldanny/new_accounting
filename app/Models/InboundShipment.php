<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Where an order from a supplier is on its way: carrier, tracking number, container, dates and a status history. It records
 * the journey; the stock is received through the receiving note as before.
 */
class InboundShipment extends Model
{
    use Auditable;
    use ScopedToCompany;

    public const STATUSES = ['ordered', 'shipped', 'in_transit', 'customs', 'arrived', 'received', 'cancelled'];

    protected bool $branchScoped = true;

    protected $fillable = [
        'company_id', 'branch_id', 'transaction_id', 'shipment_no', 'carrier', 'tracking_no', 'container_no', 'status', 'shipped_on',
        'eta', 'arrived_on', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return ['shipped_on' => 'date:Y-m-d', 'eta' => 'date:Y-m-d', 'arrived_on' => 'date:Y-m-d'];
    }

    /**
     * @return HasMany<InboundShipmentEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(InboundShipmentEvent::class)->orderBy('occurred_at')->orderBy('id');
    }
}
