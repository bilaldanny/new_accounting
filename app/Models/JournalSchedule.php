<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A schedule that posts journal vouchers on a rule: recurring, reversing, accrual, amortization or recurring expense.
 * See Services\JournalScheduleEngine.
 */
class JournalSchedule extends Model
{
    use Auditable;
    use ScopedToCompany;

    public const KINDS = ['recurring', 'reversing', 'accrual', 'amortization', 'expense'];

    public const FREQUENCIES = ['daily', 'weekly', 'monthly', 'yearly'];

    protected $fillable = [
        'company_id', 'branch_id', 'kind', 'name', 'frequency', 'start_date', 'end_date', 'max_runs', 'auto_post', 'auto_reverse', 'status',
        'lines', 'meta', 'blocked_reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'auto_post' => 'boolean', 'auto_reverse' => 'boolean',
            'lines' => 'array', 'meta' => 'array',
        ];
    }

    /**
     * @return HasMany<JournalScheduleRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(JournalScheduleRun::class);
    }
}
