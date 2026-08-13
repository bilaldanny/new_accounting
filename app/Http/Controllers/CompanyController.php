<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\JsonResponse;

class CompanyController extends Controller
{
    public function fetch(): JsonResponse
    {
        $companies = Company::query()
            ->where('is_active', true)
            ->select('companies.*')
            ->selectRaw('name as text')
            ->orderBy('name')
            ->get();

        return response()->json($companies);
    }
}
