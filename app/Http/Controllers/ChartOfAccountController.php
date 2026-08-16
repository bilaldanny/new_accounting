<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ChartOfAccountController extends Controller
{
    public function fetchParentAccounts(Request $request): JsonResponse
    {
        return response()->json($this->accountOptions($request, 'c'));
    }

    public function fetchChildAccounts(Request $request): JsonResponse
    {
        return response()->json($this->accountOptions($request, 't'));
    }

    public function fetchAllAccounts(Request $request): JsonResponse
    {
        return response()->json($this->accountOptions($request));
    }

    public function fetchParentSaleAccounts(Request $request): JsonResponse
    {
        $request->validate([
            'parent_id' => 'required|integer',
        ]);

        $accounts = ChartOfAccount::query()
            ->where('active', true)
            ->when($request->filled('company_id'), fn ($query) => $query->where('company_id', $request->integer('company_id')))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->where('parent_id', $request->integer('parent_id'))
            ->where('acc_type', 't')
            ->orderBy('code')
            ->select('chart_of_accounts.*', DB::raw("CONCAT(code, ' - ', name) AS text"))
            ->get();

        return response()->json($accounts);
    }

    public function fetchParentPurchaseAccounts(Request $request): JsonResponse
    {
        return $this->fetchParentSaleAccounts($request);
    }

    /**
     * @return Collection<int, ChartOfAccount>
     */
    private function accountOptions(Request $request, ?string $accType = null)
    {
        return ChartOfAccount::query()
            ->where('active', true)
            ->when($request->filled('company_id'), fn ($query) => $query->where('company_id', $request->integer('company_id')))
            ->when($request->filled('branch_id'), fn ($query) => $query->where('branch_id', $request->integer('branch_id')))
            ->when($accType !== null, fn ($query) => $query->where('acc_type', $accType))
            ->orderBy('code')
            ->select('chart_of_accounts.*', DB::raw("CONCAT(code, ' - ', name) AS text"))
            ->get();
    }
}
