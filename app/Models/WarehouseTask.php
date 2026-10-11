<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A pick, pack or put-away work list. It records the work (who, what, how much was done, where it was put); the stock
 * itself moves through the issue and receiving notes exactly as before.
 */
class WarehouseTask extends Model
{
    use Auditable;
    use ScopedToCompany;

    public const TYPES = ['pick', 'pack', 'putaway'];

    public const STATUSES = ['open', 'in_progress', 'done', 'cancelled'];

    protected bool $branchScoped = true;

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'task_no', 'type', 'transaction_id', 'status', 'assigned_to', 'note', 'completed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    /**
     * @return HasMany<WarehouseTaskLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(WarehouseTaskLine::class);
    }
}
