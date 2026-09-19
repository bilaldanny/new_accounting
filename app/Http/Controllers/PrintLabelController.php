<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PrintLabelController extends Controller
{
    public function searchProducts(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => Auth::user()?->hasRole('superadmin') ? 'required' : 'nullable',
            'branch_id' => 'nullable',
            'search' => 'nullable|string|max:200',
            'category_id' => 'nullable|integer',
            'brand_id' => 'nullable|integer',
        ]);

        $this->authorizeMenuPermission('/printlabel');

        $companyId = Auth::user()?->hasRole('superadmin')
            ? Transaction::resolveScopedId($request->company_id)
            : Transaction::resolveScopedId(Auth::user()?->company_id ?? $request->company_id);

        return response()->json(Transaction::searchProducts(
            $companyId,
            Transaction::resolveScopedId($request->branch_id),
            $request->input('search'),
            $request->only(['category_id', 'brand_id']),
        ));
    }
}
