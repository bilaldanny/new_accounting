<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CompanySetting extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'business_name',
        'start_date',
        'currency_placement',
        'currency_id',
        'profit_percent',
        'logo',
        'timezone_id',
        'financial_start_month',
        'date_format',
        'time_format',
        'search_type',
        'accounting_method',
        'default_customer',
        'default_pos_unit',
        'update_packing_qty',
        'purchase_column',
        'transaction_edit_days',
        'purchase_order',
        'purchase_return',
        'stock_transfer',
        'stock_adjustment',
        'sell_return',
        'invoice',
        'expenses',
        'supplier',
        'customer',
        'bank',
        'product',
        'purchase_payment',
        'sell_payment',
        'expense_payment',
        'business_location',
        'subscription_no',
        'draft',
        'opening_stock',
        'grn',
        'gin',
        'purchase_approval',
        'sell_approval',
        'journal_entry',
        'show_sku',
        'cash_collection',
        'payment',
        'limit_account',
        'auto_grn',
        'auto_gin',
    ];

    protected function casts(): array
    {
        return [
            'profit_percent' => 'float',
            'purchase_column' => 'array',
            'update_packing_qty' => 'boolean',
            'purchase_approval' => 'boolean',
            'sell_approval' => 'boolean',
            'journal_entry' => 'boolean',
            'show_sku' => 'boolean',
            'cash_collection' => 'boolean',
            'payment' => 'boolean',
            'limit_account' => 'boolean',
            'auto_grn' => 'boolean',
            'auto_gin' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return array<int, array{name: string, show: bool}>
     */
    public static function defaultPurchaseColumns(): array
    {
        return [
            ['name' => 'Packing Quantity', 'show' => true],
            ['name' => 'Unit Cost (Before Discount)', 'show' => true],
            ['name' => 'Discount %', 'show' => true],
            ['name' => 'Unit Cost (Before Tax)', 'show' => true],
            ['name' => 'Subtotal (Before Tax)', 'show' => true],
            ['name' => 'Product Tax', 'show' => true],
            ['name' => 'Net Cost', 'show' => true],
            ['name' => 'Line Total', 'show' => true],
            ['name' => 'Profit Margin %', 'show' => true],
            ['name' => 'Unit Selling Price (Inc. tax)', 'show' => true],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function defaultPrefixes(): array
    {
        return [
            'purchase_order' => 'PO',
            'purchase_return' => 'PR',
            'stock_transfer' => 'ST',
            'stock_adjustment' => 'SA',
            'sell_return' => 'SR',
            'invoice' => 'INV',
            'expenses' => 'EXP',
            'supplier' => 'SU',
            'customer' => 'CU',
            'bank' => 'BA',
            'product' => 'PRO',
            'purchase_payment' => 'PP',
            'sell_payment' => 'SP',
            'expense_payment' => 'EP',
            'business_location' => 'BL',
            'subscription_no' => 'SN',
            'draft' => 'DRA',
            'opening_stock' => 'OS',
            'grn' => 'GRN',
            'gin' => 'GIN',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultSettingsAttributes(?string $businessName = null): array
    {
        return [
            'business_name' => $businessName,
            'search_type' => 'searchbox',
            'accounting_method' => 'lifo',
            'default_pos_unit' => '0',
            'purchase_column' => self::defaultPurchaseColumns(),
            ...self::defaultPrefixes(),
        ];
    }

    public static function createCompanySettings(int $companyId, ?string $businessName = null): self
    {
        $company = Company::query()->find($companyId);

        $setting = new self;
        $setting->company_id = $companyId;
        $setting->fill(self::defaultSettingsAttributes($businessName ?? $company?->name));
        $setting->save();

        return $setting;
    }

    /**
     * @return array<string, mixed>
     */
    public static function formatForResponse(self $setting, Company $company): array
    {
        $purchaseColumns = $setting->purchase_column;

        if (! is_array($purchaseColumns) || $purchaseColumns === []) {
            $purchaseColumns = self::defaultPurchaseColumns();
        }

        return [
            'id' => $setting->id,
            'company_id' => $setting->company_id,
            'business_name' => $setting->business_name,
            'start_date' => $setting->start_date,
            'currency_placement' => $setting->currency_placement,
            'currency_id' => $setting->currency_id,
            'profit_percent' => $setting->profit_percent,
            'logo' => $setting->logo ?? $company->logo,
            'logo_url' => Company::logoUrl($setting->logo ?? $company->logo),
            'timezone_id' => $setting->timezone_id,
            'financial_start_month' => $setting->financial_start_month,
            'date_format' => $setting->date_format,
            'time_format' => $setting->time_format,
            'search_type' => $setting->search_type ?? 'searchbox',
            'accounting_method' => $setting->accounting_method ?? 'lifo',
            'default_customer' => $setting->default_customer,
            'default_pos_unit' => $setting->default_pos_unit ?? '0',
            'update_packing_qty' => (bool) $setting->update_packing_qty,
            'purchase_column' => $purchaseColumns,
            'transaction_edit_days' => $setting->transaction_edit_days,
            'email' => $company->email,
            'phone' => $company->phone,
            'cell' => $company->cell,
            'whatsapp_no' => $company->whatsapp_no,
            'fb_link' => $company->fb_link,
            'address' => $company->address,
            'country_id' => $company->country_id,
            'state_id' => $company->state_id,
            'city_id' => $company->city_id,
            'purchase_order' => $setting->purchase_order,
            'purchase_return' => $setting->purchase_return,
            'stock_transfer' => $setting->stock_transfer,
            'stock_adjustment' => $setting->stock_adjustment,
            'sell_return' => $setting->sell_return,
            'invoice' => $setting->invoice,
            'expenses' => $setting->expenses,
            'supplier' => $setting->supplier,
            'customer' => $setting->customer,
            'bank' => $setting->bank,
            'product' => $setting->product,
            'purchase_payment' => $setting->purchase_payment,
            'sell_payment' => $setting->sell_payment,
            'expense_payment' => $setting->expense_payment,
            'business_location' => $setting->business_location,
            'subscription_no' => $setting->subscription_no,
            'draft' => $setting->draft,
            'opening_stock' => $setting->opening_stock,
            'grn' => $setting->grn,
            'gin' => $setting->gin,
            'purchase_approval' => (bool) $setting->purchase_approval,
            'sell_approval' => (bool) $setting->sell_approval,
            'journal_entry' => (bool) $setting->journal_entry,
            'show_sku' => (bool) $setting->show_sku,
            'cash_collection' => (bool) $setting->cash_collection,
            'payment' => (bool) $setting->payment,
            'limit_account' => (bool) $setting->limit_account,
            'auto_grn' => (bool) $setting->auto_grn,
            'auto_gin' => (bool) $setting->auto_gin,
        ];
    }

    public static function updateFromRequest(Request $request, self $setting, Company $company): self
    {
        $setting->fill($request->only([
            'business_name',
            'currency_placement',
            'currency_id',
            'profit_percent',
            'timezone_id',
            'financial_start_month',
            'date_format',
            'time_format',
            'search_type',
            'accounting_method',
            'default_customer',
            'default_pos_unit',
            'update_packing_qty',
            'transaction_edit_days',
            'purchase_order',
            'purchase_return',
            'stock_transfer',
            'stock_adjustment',
            'sell_return',
            'invoice',
            'expenses',
            'supplier',
            'customer',
            'bank',
            'product',
            'purchase_payment',
            'sell_payment',
            'expense_payment',
            'business_location',
            'subscription_no',
            'draft',
            'opening_stock',
            'grn',
            'gin',
            'purchase_approval',
            'sell_approval',
            'journal_entry',
            'show_sku',
            'cash_collection',
            'payment',
            'limit_account',
            'auto_grn',
            'auto_gin',
        ]));

        if ($request->filled('start_date')) {
            $setting->start_date = Carbon::parse(str_replace('/', '-', (string) $request->input('start_date')))->format('Y-m-d');
        }

        if ($request->has('purchase_column') && is_array($request->input('purchase_column'))) {
            $setting->purchase_column = $request->input('purchase_column');
        }

        if ($request->filled('logo')) {
            $setting->logo = $request->string('logo')->toString();
            $company->logo = $request->string('logo')->toString();
        }

        $company->email = $request->input('email');
        $company->phone = $request->input('phone');
        $company->cell = $request->input('cell');
        $company->whatsapp_no = $request->input('whatsapp_no');
        $company->fb_link = $request->input('fb_link');
        $company->address = $request->input('address');
        $company->country_id = $request->input('country_id') ?: null;
        $company->state_id = $request->input('state_id') ?: null;
        $company->city_id = $request->input('city_id') ?: null;

        $setting->save();
        $company->save();

        if ($request->filled('branch_id') && is_array($request->input('account_setup'))) {
            ChartOfAccountMapping::syncFromRequest(
                $request,
                (int) $company->id,
                $request->integer('branch_id'),
                $request->input('account_setup'),
            );
        }

        return $setting;
    }
}
