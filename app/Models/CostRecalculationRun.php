<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One committed cost recalculation (audit trail).
 */
class CostRecalculationRun extends Model
{
    protected $fillable = ['company_id', 'from_date', 'to_date', 'method', 'lines', 'cost_before', 'cost_after', 'run_by'];

    protected function casts(): array
    {
        return ['from_date' => 'date:Y-m-d', 'to_date' => 'date:Y-m-d', 'cost_before' => 'float', 'cost_after' => 'float'];
    }
}
