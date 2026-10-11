<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * The record of a financial year being closed: the result carried to retained earnings and the year that was activated.
 */
class YearClosing extends Model
{
    use Auditable;
    use ScopedToCompany;

    protected $fillable = ['company_id', 'from_year_id', 'to_year_id', 'closed_on', 'net_result', 'summary', 'closed_by'];

    protected function casts(): array
    {
        return ['closed_on' => 'date', 'net_result' => 'float', 'summary' => 'array'];
    }
}
