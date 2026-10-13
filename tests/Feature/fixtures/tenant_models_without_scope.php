<?php

/**
 * company_id models that carry no `visibleToCurrentUser` scope. Reviewed in the integration checkpoint (docs/security-sweep.md):
 * each is a child row reached through its already scoped parent, a per company settings row looked up by the signed-in company,
 * or a table the controllers filter with scopeCompanyId(). A new tenant model must not be added here without that review.
 */
return [
    'App\Models\AccountBalance',
    'App\Models\AssetEvent',
    'App\Models\BadDebtSetting',
    'App\Models\ChartOfAccountMapping',
    'App\Models\CompanySetting',
    'App\Models\ContactAttachment',
    'App\Models\CostRecalculationRun',
    'App\Models\CurrencyRate',
    'App\Models\DocumentSetting',
    'App\Models\DunningLevel',
    'App\Models\FbrSetting',
    'App\Models\FbrSubmission',
    'App\Models\FixedAssetDepreciation',
    'App\Models\GiftCardOrphanRefund',
    'App\Models\InventoryCloseEntry',
    'App\Models\JournalScheduleRun',
    'App\Models\LoyaltyPointEntry',
    'App\Models\LoyaltySetting',
    'App\Models\PeriodLockEntry',
    'App\Models\Permission',
    'App\Models\PortalRequest',
    'App\Models\ProductBarcode',
    'App\Models\ProductComponent',
    'App\Models\SaleQuotationDetail',
    'App\Models\SellLineCost',
    'App\Models\StandardCost',
    'App\Models\ValuationMethodChange',
];
