<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\FinancialYear;
use App\Models\YearClosing;
use App\Services\YearClose;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Year-end close: preview, close (rollover + closing record + activate the new year) and the history of closings with their
 * closing statement. See App\Services\YearClose for why nothing is posted to the ledger.
 */
class YearCloseController extends Controller
{
    use ScopesToCompany;

    public function __construct(private readonly YearClose $close) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/yearclose');

        $rows = YearClosing::query()->visibleToCurrentUser()
            ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
            ->orderByDesc('id')->limit(100)->get();

        $years = FinancialYear::query()->whereIn('id', $rows->pluck('from_year_id')->merge($rows->pluck('to_year_id')))->pluck('name', 'id');

        return response()->json([
            'data' => $rows->map(fn (YearClosing $closing): array => $closing->toArray() + [
                'from_year' => $years[$closing->from_year_id] ?? null, 'to_year' => $years[$closing->to_year_id] ?? null,
            ])->values(),
            'years' => FinancialYear::query()->visibleToCurrentUser()
                ->when($request->filled('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')))
                ->orderByDesc('start_date')->get(['id', 'name', 'start_date', 'end_date', 'status']),
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/yearclose');
        [$companyId, $from, $to] = $this->input($request);

        return response()->json(['data' => $this->close->preview($companyId, $from, $to)]);
    }

    public function run(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/yearclose/run');
        [$companyId, $from, $to] = $this->input($request);
        $result = $this->close->close($companyId, $from, $to);

        return response()->json(['message' => 'Year closed; '.$result['rollover']['to']['name'].' is now the active year', 'data' => $result]);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function input(Request $request): array
    {
        $data = $request->validate([
            'company_id' => 'nullable|integer',
            'from_year_id' => 'required|integer',
            'to_year_id' => 'required|integer|different:from_year_id',
        ]);

        return [$this->scopeCompanyId($request), (int) $data['from_year_id'], (int) $data['to_year_id']];
    }
}
