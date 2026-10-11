<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * The company (and branch) a write is made under: a company user's own, whatever the request says; the superadmin
 * names the company, and may name a branch of it.
 */
trait ScopesToCompany
{
    protected function scopeCompanyId(Request $request): int
    {
        $user = Auth::user();

        if ($user?->hasRole('superadmin')) {
            $companyId = (int) $request->input('company_id');

            if ($companyId <= 0) {
                throw ValidationException::withMessages(['company_id' => ['Choose a company.']]);
            }

            return $companyId;
        }

        return (int) $user?->company_id;
    }

    /**
     * A branch user always writes under their own branch; a company admin or superadmin may name one (or none).
     */
    protected function scopeBranchId(Request $request, ?int $companyId = null): ?int
    {
        $user = Auth::user();

        if ($user?->branch_id && ! $user->hasRole('companyadmin') && ! $user->hasRole('superadmin')) {
            return (int) $user->branch_id;
        }

        $branchId = $request->input('branch_id');

        if ($branchId === null || $branchId === '') {
            return null;
        }

        if ($companyId !== null && ! Branch::query()->where('company_id', $companyId)->whereKey((int) $branchId)->exists()) {
            throw ValidationException::withMessages(['branch_id' => ['Choose a branch of this company.']]);
        }

        return (int) $branchId;
    }
}
