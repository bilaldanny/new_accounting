<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A company's costing method from an effective date forward (average | fifo | standard). See Services\Valuation\ValuationMethod.
 */
class ValuationMethodChange extends Model
{
    protected $fillable = ['company_id', 'method', 'effective_from', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['effective_from' => 'date:Y-m-d'];
    }
}
