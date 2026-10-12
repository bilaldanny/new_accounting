<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Services\FxSettlement;
use App\Services\MultiCurrency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Foreign currency on vouchers (see Services\MultiCurrency, Services\FxSettlement): the company switch and the exchange gain / loss
 * account, dated exchange rates, and the settlement of foreign-currency party lines. Off by default.
 */
class ForeignCurrencyController extends Controller
{
    use ScopesToCompany;

    public function __construct(private readonly FxSettlement $settlement) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/foreigncurrency');

        $companyId = $this->scopeCompanyId($request);
        $installed = MultiCurrency::installed();
        $settings = $installed ? DB::table('company_settings')->where('company_id', $companyId)->first(['multi_currency_vouchers', 'fx_gain_loss_account_id']) : null;

        return response()->json([
            'installed' => $installed,
            'enabled' => (bool) ($settings->multi_currency_vouchers ?? false),
            'fx_gain_loss_account_id' => $settings->fx_gain_loss_account_id ?? null,
            'base_currency_id' => MultiCurrency::baseCurrencyId($companyId),
            'rates' => $installed && DB::getSchemaBuilder()->hasTable('currency_rate_history')
                ? DB::table('currency_rate_history as h')->join('currencies as c', 'c.id', '=', 'h.currency_id')->where('h.company_id', $companyId)
                    ->orderByDesc('h.effective_on')->limit(100)->get(['h.id', 'h.currency_id', 'c.code', 'h.effective_on', 'h.rate'])
                : [],
            'open_lines' => $this->settlement->openLines($companyId),
        ]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/foreigncurrency/settings');

        $data = $request->validate(['enabled' => 'required|boolean', 'fx_gain_loss_account_id' => 'nullable|integer']);
        $companyId = $this->scopeCompanyId($request);

        if (! MultiCurrency::installed()) {
            throw ValidationException::withMessages(['enabled' => ['The multi-currency tables are not installed yet.']]);
        }

        if (! empty($data['fx_gain_loss_account_id']) && ! ChartOfAccount::query()->where('company_id', $companyId)->whereKey($data['fx_gain_loss_account_id'])->exists()) {
            throw ValidationException::withMessages(['fx_gain_loss_account_id' => ['Choose an account of this company.']]);
        }

        if ($data['enabled'] && MultiCurrency::baseCurrencyId($companyId) === null) {
            throw ValidationException::withMessages(['enabled' => ['Set the company base currency in Company Settings first.']]);
        }

        DB::table('company_settings')->updateOrInsert(
            ['company_id' => $companyId],
            ['multi_currency_vouchers' => $data['enabled'] ? 1 : 0, 'fx_gain_loss_account_id' => $data['fx_gain_loss_account_id'] ?? null, 'updated_at' => now()],
        );

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function addRate(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/foreigncurrency/rates');

        $data = $request->validate(['currency_id' => 'required|integer', 'effective_on' => 'required|date_format:Y-m-d', 'rate' => 'required|numeric|gt:0']);
        $companyId = $this->scopeCompanyId($request);

        if (! Currency::query()->whereKey($data['currency_id'])->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['currency_id' => ['Choose an active currency.']]);
        }

        if ((int) $data['currency_id'] === MultiCurrency::baseCurrencyId($companyId)) {
            throw ValidationException::withMessages(['currency_id' => ['The base currency has no rate; it is always 1.']]);
        }

        DB::table('currency_rate_history')->updateOrInsert(
            ['company_id' => $companyId, 'currency_id' => $data['currency_id'], 'effective_on' => $data['effective_on']],
            ['rate' => $data['rate'], 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now()],
        );

        return response()->json(['message' => 'Successfully Saved'], 201);
    }

    public function settle(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/foreigncurrency/settle');

        $data = $request->validate([
            'detail_id' => 'required|integer',
            'foreign_amount' => 'required|numeric|gt:0',
            'settlement_rate' => 'required|numeric|gt:0',
            'settled_on' => 'required|date_format:Y-m-d',
        ]);

        $companyId = $this->scopeCompanyId($request);
        $branchId = $this->scopeBranchId($request, $companyId) ?? (int) Branch::query()->where('company_id', $companyId)->orderBy('id')->value('id');
        $result = $this->settlement->settle($companyId, $branchId, (int) $data['detail_id'], (float) $data['foreign_amount'], (float) $data['settlement_rate'], $data['settled_on']);

        return response()->json(['message' => 'Settlement recorded', 'data' => $result], 201);
    }
}
