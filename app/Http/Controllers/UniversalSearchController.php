<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesReportScope;
use App\Services\Search\UniversalSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * `GET /api/search?q=` for the search bar. The company and branch are the signed-in user's (a superadmin who has not picked a
 * company searches pages only); each group of the answer follows the user's menu permissions (see UniversalSearch).
 */
class UniversalSearchController extends Controller
{
    use ResolvesReportScope;

    public function __invoke(Request $request, UniversalSearch $search): JsonResponse
    {
        $request->validate(['q' => 'required|string|min:2|max:60', 'company_id' => 'nullable|integer', 'branch_id' => 'nullable|integer']);
        [$companyId, $branchId] = $this->reportScope($request);

        return response()->json(['data' => $search->search(Auth::user(), (string) $request->query('q'), $companyId, $branchId)]);
    }
}
