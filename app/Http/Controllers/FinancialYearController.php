<?php

namespace App\Http\Controllers;

use App\Models\FinancialYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;

class FinancialYearController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sortBy = $request->input('sort_by', 'created_at');
        $sortType = $request->input('sort_type', 'desc');
        $showRecord = (int) $request->input('show_record', 10);
        $curPage = (int) $request->input('cur_page', 1);
        $search = $request->input('search', '');

        $query = FinancialYear::query()
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('start_date', 'like', "%{$search}%")
                        ->orWhere('end_date', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                });
            })
            ->when($request->input('status') !== 'all' && $request->filled('status'), fn ($q) => $q->where('status', $request->boolean('status')))
            ->orderBy($sortBy, $sortType);

        Paginator::currentPageResolver(fn () => $curPage);

        return response()->json($query->paginate($showRecord));
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => 'required|string',
            'end_date' => 'required|string',
        ]);

        FinancialYear::storeFromRequest($request);

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(FinancialYear::query()->findOrFail($id));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $financialYear = FinancialYear::query()->findOrFail($id);

        if ($request->input('updatetype') !== 'status') {
            $request->validate([
                'start_date' => 'required|string',
                'end_date' => 'required|string',
            ]);
        }

        $financialYear->updateFromRequest($request);

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function destroy(int $id): JsonResponse
    {
        FinancialYear::query()->findOrFail($id)->delete();

        return response()->json(['message' => 'Successfully Deleted']);
    }

    public function fetch(Request $request): JsonResponse
    {
        $years = FinancialYear::query()
            ->where('status', true)
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->select('financial_years.*', DB::raw("CONCAT(start_date, ' - ', end_date) AS text"))
            ->orderByDesc('start_date')
            ->get();

        return response()->json($years);
    }
}
