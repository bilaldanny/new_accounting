<?php

namespace App\Http\Controllers;

use App\Services\YearRollover;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Preview and run the year rollover (opening balances of the next financial year from the closing one).
 */
class YearRolloverController extends Controller
{
    public function __construct(private readonly YearRollover $rollover) {}

    public function preview(Request $request): JsonResponse
    {
        abortUnlessMenuPermission('/period-lock/rollover');
        [$companyId, $from, $to] = $this->input($request);

        return response()->json(['data' => $this->rollover->preview($companyId, $from, $to)]);
    }

    public function run(Request $request): JsonResponse
    {
        abortUnlessMenuPermission('/period-lock/rollover');
        [$companyId, $from, $to] = $this->input($request);
        $result = $this->rollover->run($companyId, $from, $to);

        return response()->json(['message' => 'Opening balances of '.$result['to']['name'].' written', 'data' => $result]);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function input(Request $request): array
    {
        $data = $request->validate([
            'from_year_id' => ['required', 'integer'],
            'to_year_id' => ['required', 'integer', 'different:from_year_id'],
        ]);

        $user = $request->user();
        $companyId = $user?->hasRole('superadmin') ? (int) $request->input('company_id') : (int) $user?->company_id;

        if ($companyId <= 0) {
            throw ValidationException::withMessages(['company_id' => ['Choose a company.']]);
        }

        return [$companyId, (int) $data['from_year_id'], (int) $data['to_year_id']];
    }
}
