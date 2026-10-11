<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The row visibility every company-owned record shares: the superadmin sees every company, anyone else only their own
 * company. A model that is also kept per branch sets `protected bool $branchScoped = true`: a branch user (not the
 * company admin) then sees only their branch's rows.
 *
 * @mixin Model
 */
trait ScopedToCompany
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleToCurrentUser(Builder $query): Builder
    {
        $user = Auth::user();

        if ($user?->hasRole('superadmin')) {
            return $query;
        }

        if (! $user?->company_id) {
            return $query->whereRaw('0 = 1');
        }

        $query->where($this->qualifyColumn('company_id'), $user->company_id);

        if (($this->branchScoped ?? false) && $user->branch_id && ! $user->hasRole('companyadmin')) {
            $query->where($this->qualifyColumn('branch_id'), $user->branch_id);
        }

        return $query;
    }
}
