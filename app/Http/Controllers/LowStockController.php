<?php

namespace App\Http\Controllers;

use App\Services\LowStockReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;

class LowStockController extends Controller
{
    public function index(Request $request, LowStockReport $report): JsonResponse
    {
        $this->authorizeMenuPermission('/lowstock');

        $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'category_id' => 'nullable|integer',
            'brand_id' => 'nullable|integer',
            'search' => 'nullable|string|max:200',
            'sort_by' => 'nullable|string',
            'sort_type' => 'nullable|in:asc,desc',
            'show_record' => 'nullable|integer|min:1|max:1000',
            'cur_page' => 'nullable|integer|min:1',
        ]);

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

        $query = $report->query($companyId, $branchId, $request->only(['search', 'category_id', 'brand_id']));

        $sortBy = (string) $request->input('sort_by');
        $isKnownSort = array_key_exists($sortBy, LowStockReport::SORTABLE);
        $sortColumn = $isKnownSort ? LowStockReport::SORTABLE[$sortBy] : LowStockReport::SORTABLE['shortage'];
        $sortDirection = $isKnownSort && $request->input('sort_type') === 'asc' ? 'asc' : 'desc';

        $query->orderByRaw("{$sortColumn} {$sortDirection}")->orderBy('p.name')->orderBy('s.branch_id');

        $showRecord = $request->integer('show_record', 10);
        $curPage = $request->integer('cur_page', 1);

        Paginator::currentPageResolver(fn () => $curPage);
        $result = $query->paginate($showRecord);

        if ($curPage > $result->lastPage()) {
            Paginator::currentPageResolver(fn () => $result->lastPage());
            $result = $query->paginate($showRecord);
        }

        return response()->json(['data' => $result, 'trash_count' => 0]);
    }
}
