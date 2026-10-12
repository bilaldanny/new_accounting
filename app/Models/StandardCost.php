<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A standard cost per base unit of a variation from an effective date (append only).
 */
class StandardCost extends Model
{
    protected $fillable = ['company_id', 'variation_id', 'cost', 'effective_from', 'created_by'];

    protected function casts(): array
    {
        return ['cost' => 'float', 'effective_from' => 'date:Y-m-d'];
    }
}
