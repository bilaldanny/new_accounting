<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Which company and branch a report covers for the signed in user: the superadmin sees every company
 * (or the one they pick), everyone else only their own company, and a plain branch user only their own
 * branch while a company admin may pick any.
 */
trait ResolvesReportScope
{
    /**
     * @return array{0: ?int, 1: ?int} the company id and the branch id, null meaning no limit
     */
    protected function reportScope(Request $request): array
    {
        $user = Auth::user();
        $isSuperadmin = $user->hasRole('superadmin');

        if (! $isSuperadmin && ! $user->company_id) {
            abort(403);
        }

        $companyId = $isSuperadmin
            ? ($request->integer('company_id') ?: null)
            : (int) $user->company_id;
        $branchId = $user->branch_id && ! $isSuperadmin && ! $user->hasRole('companyadmin')
            ? (int) $user->branch_id
            : ($request->integer('branch_id') ?: null);

        return [$companyId, $branchId];
    }
}
