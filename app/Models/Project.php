<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A project or job that revenue and costs are tagged to. A separate master from cost centers; `cost_center_id` is an optional link.
 */
class Project extends Model
{
    use Auditable;
    use ScopedToCompany;

    public const STATUSES = ['active', 'on_hold', 'completed', 'cancelled'];

    protected $fillable = [
        'company_id', 'branch_id', 'code', 'name', 'contact_id', 'cost_center_id', 'start_date', 'end_date', 'budget_revenue', 'budget_cost',
        'status', 'description', 'created_by',
    ];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'budget_revenue' => 'float', 'budget_cost' => 'float'];
    }
}
