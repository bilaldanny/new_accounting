<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function fetch(Request $request): JsonResponse
    {
        if ($request->company_id === null || $request->company_id === 'undefined' || $request->company_id === '') {
            return response()->json([]);
        }

        $branches = Branch::query()
            ->where('is_active', true)
            ->where('company_id', $request->company_id)
            ->select('branches.*')
            ->selectRaw('name as text')
            ->orderBy('name')
            ->get();

        return response()->json($branches);
    }
}
