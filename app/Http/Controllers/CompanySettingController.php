<?php

namespace App\Http\Controllers;

use App\Models\ChartOfAccountMapping;
use App\Models\Company;
use App\Models\CompanySetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class CompanySettingController extends Controller
{
    public function show(Request $request, int $companyId): JsonResponse
    {
        $this->authorizeCompanyAccess($request, $companyId);

        $company = Company::query()->find($companyId);

        if ($company === null) {
            return response()->json(['message' => 'Company not found.'], 404);
        }

        $setting = CompanySetting::query()->firstOrCreate(
            ['company_id' => $companyId],
            CompanySetting::defaultSettingsAttributes($company->name),
        );

        $payload = [
            'companySetting' => CompanySetting::formatForResponse($setting, $company),
        ];

        if ($request->filled('branch_id')) {
            $payload['account_setup'] = ChartOfAccountMapping::forBranch(
                $companyId,
                $request->integer('branch_id'),
            )->values();
        }

        return response()->json($payload);
    }

    public function update(Request $request, int $companyId): JsonResponse
    {
        $this->authorizeCompanyAccess($request, $companyId);

        $company = Company::query()->findOrFail($companyId);

        $request->validate([
            'business_name' => 'bail|required|string|min:3|max:200',
            'start_date' => 'nullable|string',
            'currency_placement' => 'nullable|string|in:ba,aa',
            'currency_id' => 'nullable|integer|exists:currencies,id',
            'profit_percent' => 'nullable|numeric',
            'logo' => 'nullable|string',
            'timezone_id' => 'nullable|integer|exists:timezones,id',
            'financial_start_month' => 'nullable|string',
            'date_format' => 'nullable|string',
            'time_format' => 'nullable|string|in:12,24',
            'search_type' => 'nullable|string|in:searchbox,selectbox',
            'accounting_method' => 'nullable|string|in:fifo,lifo',
            'default_customer' => 'nullable|integer',
            'default_pos_unit' => 'nullable|string|in:0,1',
            'update_packing_qty' => 'nullable|boolean',
            'purchase_column' => 'nullable|array',
            'purchase_column.*.name' => 'nullable|string',
            'purchase_column.*.show' => 'nullable|boolean',
            'transaction_edit_days' => 'nullable|string',
            'email' => 'nullable|email',
            'phone' => 'nullable|numeric',
            'cell' => 'nullable|numeric',
            'whatsapp_no' => 'nullable|numeric',
            'fb_link' => 'nullable|string',
            'address' => 'nullable|string',
            'country_id' => 'nullable|integer|exists:countries,id',
            'state_id' => 'nullable|integer|exists:states,id',
            'city_id' => 'nullable|integer|exists:cities,id',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'account_setup' => 'nullable|array',
            'account_setup.*.id' => 'nullable|integer',
            'account_setup.*.key' => 'nullable|string',
            'account_setup.*.name' => 'nullable|string',
            'account_setup.*.value' => 'nullable',
            'purchase_order' => 'nullable|string',
            'purchase_return' => 'nullable|string',
            'stock_transfer' => 'nullable|string',
            'stock_adjustment' => 'nullable|string',
            'sell_return' => 'nullable|string',
            'invoice' => 'nullable|string',
            'expenses' => 'nullable|string',
            'supplier' => 'nullable|string',
            'customer' => 'nullable|string',
            'bank' => 'nullable|string',
            'product' => 'nullable|string',
            'purchase_payment' => 'nullable|string',
            'sell_payment' => 'nullable|string',
            'expense_payment' => 'nullable|string',
            'business_location' => 'nullable|string',
            'subscription_no' => 'nullable|string',
            'draft' => 'nullable|string',
            'opening_stock' => 'nullable|string',
            'grn' => 'nullable|string',
            'gin' => 'nullable|string',
            'purchase_approval' => 'nullable|boolean',
            'sell_approval' => 'nullable|boolean',
            'journal_entry' => 'nullable|boolean',
            'show_sku' => 'nullable|boolean',
            'cash_collection' => 'nullable|boolean',
            'payment' => 'nullable|boolean',
            'limit_account' => 'nullable|boolean',
            'auto_grn' => 'nullable|boolean',
            'auto_gin' => 'nullable|boolean',
        ]);

        DB::beginTransaction();

        try {
            $setting = CompanySetting::query()->firstOrCreate(
                ['company_id' => $companyId],
                CompanySetting::defaultSettingsAttributes($company->name),
            );

            CompanySetting::updateFromRequest($request, $setting, $company);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        $company->refresh();
        $setting->refresh();

        $payload = [
            'message' => 'Successfully Saved',
            'companySetting' => CompanySetting::formatForResponse($setting, $company),
        ];

        if ($request->filled('branch_id')) {
            $payload['account_setup'] = ChartOfAccountMapping::forBranch(
                $companyId,
                $request->integer('branch_id'),
            )->values();
        }

        return response()->json($payload);
    }

    private function authorizeCompanyAccess(Request $request, int $companyId): void
    {
        $user = $request->user();
        $roleName = strtolower(str_replace(' ', '', (string) ($user?->rolename ?? '')));

        if ($roleName === 'superadmin') {
            return;
        }

        if ((int) ($user?->company_id ?? 0) !== $companyId) {
            abort(403);
        }
    }
}
