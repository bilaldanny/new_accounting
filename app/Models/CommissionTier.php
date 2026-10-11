<?php

namespace App\Models;

use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Above `threshold_amount` of net sales in a month an agent earns `rate_percent` instead of the base percentage on the agent.
 * A tier with no agent applies to every agent of the company.
 */
class CommissionTier extends Model
{
    use ScopedToCompany;

    protected $fillable = ['company_id', 'commission_agent_id', 'threshold_amount', 'rate_percent'];

    protected function casts(): array
    {
        return ['threshold_amount' => 'float', 'rate_percent' => 'float'];
    }
}
