<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A sales route: a weekday, its salesperson, rider and van (a warehouse) and the customers visited in order.
 */
class SalesRoute extends Model
{
    use Auditable;
    use ScopedToCompany;
    use SoftDeletes;

    protected bool $branchScoped = true;

    protected $fillable = ['company_id', 'branch_id', 'name', 'weekday', 'salesperson_id', 'rider_id', 'warehouse_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return HasMany<SalesRouteStop, $this>
     */
    public function stops(): HasMany
    {
        return $this->hasMany(SalesRouteStop::class)->orderBy('sequence')->orderBy('id');
    }
}
