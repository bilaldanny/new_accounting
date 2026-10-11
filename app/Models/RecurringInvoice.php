<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A standing invoice: every `interval` days / weeks / months / quarters / years a sale is made for the customer from its lines,
 * as a draft (the default, nothing posted) or as a final sale. See App\Services\RecurringInvoices.
 */
class RecurringInvoice extends Model
{
    use Auditable;
    use ScopedToCompany;

    public const FREQUENCIES = ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'];

    public const STATUSES = ['active', 'paused', 'ended'];

    protected bool $branchScoped = true;

    protected $fillable = [
        'company_id', 'branch_id', 'contact_id', 'name', 'frequency', 'interval', 'next_run_on', 'ends_on', 'max_runs', 'runs', 'status',
        'post_as', 'lines', 'note', 'last_run_at', 'last_transaction_id', 'created_by',
    ];

    protected function casts(): array
    {
        return ['next_run_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'lines' => 'array', 'last_run_at' => 'datetime'];
    }

    /**
     * The run after the given day.
     */
    public function nextAfter(CarbonInterface $day): CarbonInterface
    {
        $step = max((int) $this->interval, 1);

        return match ($this->frequency) {
            'daily' => $day->copy()->addDays($step),
            'weekly' => $day->copy()->addWeeks($step),
            'quarterly' => $day->copy()->addMonthsNoOverflow(3 * $step),
            'yearly' => $day->copy()->addYearsNoOverflow($step),
            default => $day->copy()->addMonthsNoOverflow($step),
        };
    }
}
