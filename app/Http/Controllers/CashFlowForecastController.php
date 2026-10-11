<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Services\CashFlowForecast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The cash flow forecast (weekly buckets of expected receipts and payments over today's bank and cash balance).
 */
class CashFlowForecastController extends Controller
{
    use ScopesToCompany;

    public function __construct(private readonly CashFlowForecast $forecast) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/cashflowforecast');

        $data = $request->validate([
            'company_id' => 'nullable|integer',
            'branch_id' => 'nullable|integer',
            'weeks' => 'nullable|integer|min:1|max:'.CashFlowForecast::MAX_WEEKS,
            'as_of' => 'nullable|date_format:Y-m-d',
        ]);

        $companyId = $this->scopeCompanyId($request);
        $branchId = $this->scopeBranchId($request, $companyId);

        return response()->json(['data' => $this->forecast->build($companyId, $branchId, (int) ($data['weeks'] ?? 8), $data['as_of'] ?? null)]);
    }
}
