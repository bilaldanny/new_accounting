<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QualityInspectionLine extends Model
{
    protected $fillable = ['quality_inspection_id', 'purchase_line_id', 'accepted_qty', 'rejected_qty', 'reason'];

    protected function casts(): array
    {
        return ['accepted_qty' => 'float', 'rejected_qty' => 'float'];
    }
}
