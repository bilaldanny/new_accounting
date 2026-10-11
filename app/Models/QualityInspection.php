<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An inspection of what a purchase received: per line, how much was accepted and how much rejected. Stock counts only what
 * was accepted (`purchase_lines.quantity_rejected` is taken off the received quantity by StockMovements).
 */
class QualityInspection extends Model
{
    use Auditable;
    use ScopedToCompany;

    protected bool $branchScoped = true;

    protected $fillable = ['company_id', 'branch_id', 'transaction_id', 'inspection_no', 'inspected_on', 'result', 'note', 'inspected_by'];

    protected function casts(): array
    {
        return ['inspected_on' => 'date:Y-m-d'];
    }

    /**
     * @return HasMany<QualityInspectionLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(QualityInspectionLine::class);
    }
}
