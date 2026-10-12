<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The cost locked on a sale line by a recalculation. Valid only while the line keeps its date and base quantity.
 */
class SellLineCost extends Model
{
    protected $fillable = [
        'company_id', 'sell_line_id', 'transaction_id', 'variation_id', 'method', 'cost_date', 'base_quantity', 'unit_cost', 'cost', 'locked_by', 'locked_at',
    ];

    protected function casts(): array
    {
        return ['cost_date' => 'date:Y-m-d', 'base_quantity' => 'float', 'unit_cost' => 'float', 'cost' => 'float', 'locked_at' => 'datetime'];
    }
}
