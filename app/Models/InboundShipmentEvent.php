<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InboundShipmentEvent extends Model
{
    protected $fillable = ['inbound_shipment_id', 'status', 'location', 'note', 'occurred_at', 'created_by'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }
}
