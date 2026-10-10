<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in a company's period lock history (append-only). `action` is lock, advance, reopen or clear; the company's current
 * lock is its latest row, and a row with no `locked_through` means the books are open. Nothing is ever edited or deleted, so the
 * table is its own audit trail (reopen and override are also written to the audit log).
 */
class PeriodLockEntry extends Model
{
    protected $table = 'period_locks';

    public const ACTION_LOCK = 'lock';

    public const ACTION_ADVANCE = 'advance';

    public const ACTION_REOPEN = 'reopen';

    public const ACTION_CLEAR = 'clear';

    protected $fillable = ['company_id', 'branch_id', 'locked_through', 'action', 'reason', 'locked_by'];

    protected function casts(): array
    {
        return ['locked_through' => 'date:Y-m-d'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }
}
