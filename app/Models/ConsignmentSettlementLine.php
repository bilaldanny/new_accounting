<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsignmentSettlementLine extends Model
{
    protected $fillable = ['consignment_settlement_id', 'product_id', 'variation_id', 'quantity_sold', 'unit_cost', 'amount'];

    protected function casts(): array
    {
        return ['quantity_sold' => 'float', 'unit_cost' => 'float', 'amount' => 'float'];
    }
}
