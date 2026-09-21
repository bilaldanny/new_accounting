<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\CurrencyRate;
use App\Services\DisplayCurrency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Settings > Exchange Rates: a company's display rates (see DisplayCurrency). Reading needs the page permission,
 * saving the update permission. A company user works on their own company, the superadmin names one.
 */
class CurrencyRateController extends Controller
{
    public function __construct(private readonly DisplayCurrency $display) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/currencyrate');
        $request->validate(['company_id' => 'nullable|integer']);

        $companyId = $this->companyId($request);

        if ($companyId === null) {
            return response()->json(['message' => 'Choose a company.'], 422);
        }

        $baseId = $this->display->baseCurrencyId($companyId);
        $rates = CurrencyRate::query()->where('company_id', $companyId)->pluck('rate', 'currency_id');
        $base = $baseId === null ? null : Currency::withTrashed()->find($baseId);

        return response()->json([
            'company_id' => $companyId,
            'base' => $base === null ? null : ['id' => $base->id, 'code' => $base->code, 'symbol' => $base->symbol],
            'currencies' => Currency::query()
                ->where('is_active', true)
                ->when($baseId !== null, fn ($query) => $query->where('id', '!=', $baseId))
                ->orderBy('code')
                ->get(['id', 'code', 'currency_name', 'symbol'])
                ->map(fn (Currency $currency): array => [
                    'id' => $currency->id,
                    'code' => $currency->code,
                    'name' => $currency->currency_name,
                    'symbol' => $currency->symbol,
                    'rate' => $rates[$currency->id] ?? null,
                ]),
        ]);
    }

    /**
     * Saves the rates sent (an empty rate removes it); rates not sent are left alone. The base currency has no
     * rate: it is always 1.
     */
    public function update(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/currencyrate/update');

        $data = $request->validate([
            'company_id' => 'nullable|integer',
            'rates' => 'required|array|min:1|max:300',
            'rates.*.currency_id' => 'required|integer|distinct|exists:currencies,id',
            'rates.*.rate' => 'nullable|numeric|gt:0|max:1000000000|decimal:0,6',
        ]);

        $companyId = $this->companyId($request);

        if ($companyId === null) {
            return response()->json(['message' => 'Choose a company.'], 422);
        }

        $baseId = $this->display->baseCurrencyId($companyId);

        foreach ($data['rates'] as $index => $row) {
            if ($baseId !== null && (int) $row['currency_id'] === $baseId) {
                return response()->json([
                    'message' => 'The base currency is always 1 and has no rate.',
                    'errors' => ["rates.{$index}.currency_id" => ['The base currency is always 1 and has no rate.']],
                ], 422);
            }
        }

        DB::transaction(function () use ($companyId, $data): void {
            foreach ($data['rates'] as $row) {
                $query = CurrencyRate::query()->where('company_id', $companyId)->where('currency_id', $row['currency_id']);

                if (! isset($row['rate']) || $row['rate'] === '') {
                    $query->delete();

                    continue;
                }

                CurrencyRate::query()->updateOrCreate(
                    ['company_id' => $companyId, 'currency_id' => (int) $row['currency_id']],
                    ['rate' => (float) $row['rate']],
                );
            }
        });

        return response()->json(['message' => 'Successfully Saved']);
    }

    private function companyId(Request $request): ?int
    {
        $user = Auth::user();

        if ($user?->hasRole('superadmin')) {
            return $request->filled('company_id') ? $request->integer('company_id') : null;
        }

        return $user?->company_id ? (int) $user->company_id : null;
    }
}
