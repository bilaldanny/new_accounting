<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

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

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function createCompanySettings(int $companyId, ?string $businessName = null): self
    {
        $company = Company::query()->find($companyId);

        $setting = new self;
        $setting->company_id = $companyId;
        $setting->business_name = $businessName ?? $company?->name;
        $setting->save();

        return $setting;
    }
}
