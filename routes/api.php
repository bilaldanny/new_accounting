<?php

use App\Http\Controllers\AccountBalanceController;
use App\Http\Controllers\ActivityController;
use App\Http\Controllers\AllocationController;
use App\Http\Controllers\AnalyticsOverviewController;
use App\Http\Controllers\Api\PublicApiCustomerController;
use App\Http\Controllers\Api\PublicApiInvoiceController;
use App\Http\Controllers\Api\PublicApiProductController;
use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\ApiLogController;
use App\Http\Controllers\ApprovalCenterController;
use App\Http\Controllers\AssetApprovalController;
use App\Http\Controllers\AssetCategoryController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\BadDebtController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\BankInstrumentController;
use App\Http\Controllers\BankIssuerController;
use App\Http\Controllers\BankReconciliationController;
use App\Http\Controllers\BlogPostController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\BulkPriceUpdateController;
use App\Http\Controllers\BusinessSettingsController;
use App\Http\Controllers\CashCollectionApprovalController;
use App\Http\Controllers\CashCollectionController;
use App\Http\Controllers\CashFlowForecastController;
use App\Http\Controllers\CashierController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ChartOfAccountController;
use App\Http\Controllers\ChequePrintController;
use App\Http\Controllers\ChequeRecordController;
use App\Http\Controllers\CityController;
use App\Http\Controllers\CommissionAgentController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanySettingController;
use App\Http\Controllers\ConsignmentController;
use App\Http\Controllers\ConsumerController;
use App\Http\Controllers\ContactAttachmentController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ContactDuplicateController;
use App\Http\Controllers\CostCenterController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\CreditDebitNoteController;
use App\Http\Controllers\CreditLimitApprovalController;
use App\Http\Controllers\CreditLimitRequestController;
use App\Http\Controllers\CrmAutomationController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\CurrencyRateController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerGroupController;
use App\Http\Controllers\CustomerSubscriptionBillingController;
use App\Http\Controllers\CustomerSubscriptionController;
use App\Http\Controllers\CustomerSubscriptionInvoiceController;
use App\Http\Controllers\CustomerSubscriptionPlanController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardInsightsController;
use App\Http\Controllers\DashboardToolsController;
use App\Http\Controllers\DataCenterController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\DiscountController;
use App\Http\Controllers\DispatchController;
use App\Http\Controllers\DocumentCenterController;
use App\Http\Controllers\DocumentOutputController;
use App\Http\Controllers\DocumentSettingController;
use App\Http\Controllers\DunningController;
use App\Http\Controllers\EcommerceConnectionController;
use App\Http\Controllers\ExpenseClaimController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FbrController;
use App\Http\Controllers\FinancialYearController;
use App\Http\Controllers\FixedAssetAcquisitionController;
use App\Http\Controllers\FixedAssetController;
use App\Http\Controllers\FixedAssetDepreciationController;
use App\Http\Controllers\FixedAssetLifecycleController;
use App\Http\Controllers\FixedAssetTransferController;
use App\Http\Controllers\ForeignCurrencyController;
use App\Http\Controllers\FundTransferController;
use App\Http\Controllers\GiftCardController;
use App\Http\Controllers\HelpCenterController;
use App\Http\Controllers\HrmAttendanceController;
use App\Http\Controllers\HrmController;
use App\Http\Controllers\HrmPayrollController;
use App\Http\Controllers\ImportPurchaseController;
use App\Http\Controllers\InboundShipmentController;
use App\Http\Controllers\InstallmentPlanController;
use App\Http\Controllers\IntegrationsController;
use App\Http\Controllers\InterBranchController;
use App\Http\Controllers\InventoryCloseController;
use App\Http\Controllers\IssueNoteController;
use App\Http\Controllers\ItemTypeController;
use App\Http\Controllers\JournalEntryController;
use App\Http\Controllers\JournalScheduleController;
use App\Http\Controllers\LandedCostController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\LeadSourceController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\LowStockController;
use App\Http\Controllers\LoyaltyController;
use App\Http\Controllers\ManufacturingController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\MessagingSettingController;
use App\Http\Controllers\MyNotificationsController;
use App\Http\Controllers\OpeningStockController;
use App\Http\Controllers\OpportunityController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentTermController;
use App\Http\Controllers\PeriodLockController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\PettyCashController;
use App\Http\Controllers\PipelineStageController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\PortalCustomerController;
use App\Http\Controllers\PortalRequestController;
use App\Http\Controllers\PortalSubscriptionController;
use App\Http\Controllers\PortalSupplierController;
use App\Http\Controllers\PortalUserController;
use App\Http\Controllers\PosOfflineController;
use App\Http\Controllers\PosShiftController;
use App\Http\Controllers\PriceListApprovalController;
use App\Http\Controllers\PriceListController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\PrintLabelController;
use App\Http\Controllers\ProductAttributeController;
use App\Http\Controllers\ProductBulkController;
use App\Http\Controllers\ProductComponentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductDiscountRuleController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectWorkController;
use App\Http\Controllers\PublicLeadCaptureController;
use App\Http\Controllers\PublicStorefrontController;
use App\Http\Controllers\PurchaseApprovalController;
use App\Http\Controllers\PurchaseApprovalRuleController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\PurchasePaymentController;
use App\Http\Controllers\PurchaseRequisitionApprovalController;
use App\Http\Controllers\PurchaseRequisitionController;
use App\Http\Controllers\PurchaseReturnApprovalController;
use App\Http\Controllers\PurchaseReturnController;
use App\Http\Controllers\QualityInspectionController;
use App\Http\Controllers\QuotationController;
use App\Http\Controllers\ReceiptShareController;
use App\Http\Controllers\ReceivingNoteController;
use App\Http\Controllers\RecurringInvoiceController;
use App\Http\Controllers\RentalController;
use App\Http\Controllers\ReorderSuggestionController;
use App\Http\Controllers\ReportBuilderController;
use App\Http\Controllers\ReportFilterController;
use App\Http\Controllers\Reports\AnalyticsReportController;
use App\Http\Controllers\Reports\CrmReportController;
use App\Http\Controllers\Reports\CustomerSubscriptionReportController;
use App\Http\Controllers\Reports\FinancialReportController;
use App\Http\Controllers\Reports\InsightReportController;
use App\Http\Controllers\Reports\LedgerReportController;
use App\Http\Controllers\Reports\PartyLedgerReportController;
use App\Http\Controllers\Reports\PartyReportController;
use App\Http\Controllers\Reports\ProductReportController;
use App\Http\Controllers\Reports\StockReportController;
use App\Http\Controllers\Reports\TransactionReportController;
use App\Http\Controllers\ReportScheduleController;
use App\Http\Controllers\RmaController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SaasAdminController;
use App\Http\Controllers\SaleImportController;
use App\Http\Controllers\SalesKpiController;
use App\Http\Controllers\SalesOrderController;
use App\Http\Controllers\SalesRouteController;
use App\Http\Controllers\SecurityCenterController;
use App\Http\Controllers\SellApprovalController;
use App\Http\Controllers\SellController;
use App\Http\Controllers\SellPaymentController;
use App\Http\Controllers\SellReturnController;
use App\Http\Controllers\ServiceJobController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StateController;
use App\Http\Controllers\StockAdjustmentApprovalController;
use App\Http\Controllers\StockAdjustmentController;
use App\Http\Controllers\StockReservationController;
use App\Http\Controllers\StockTakeController;
use App\Http\Controllers\StockTrackingController;
use App\Http\Controllers\StockTransferApprovalController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\StockWriteoffController;
use App\Http\Controllers\StoreCreditController;
use App\Http\Controllers\StorefrontController;
use App\Http\Controllers\SubscriptionInvoiceController;
use App\Http\Controllers\SubscriptionPlanController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupplierPaymentController;
use App\Http\Controllers\SystemCenterController;
use App\Http\Controllers\TaxController;
use App\Http\Controllers\TaxExemptionController;
use App\Http\Controllers\TaxPaymentController;
use App\Http\Controllers\TaxPreviewController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\TenantSelfServiceController;
use App\Http\Controllers\TimezoneController;
use App\Http\Controllers\TransporterController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UniversalSearchController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ValuationController;
use App\Http\Controllers\VariationController;
use App\Http\Controllers\VoucherApprovalController;
use App\Http\Controllers\VoucherOptionsController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\WarehouseLocationController;
use App\Http\Controllers\WarehouseTaskController;
use App\Http\Controllers\WarrantyClaimController;
use App\Http\Controllers\WarrantyController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\YearCloseController;
use App\Http\Controllers\YearRolloverController;
use App\Http\Middleware\LogPublicApiRequest;
use App\Http\Middleware\ValidateBulkActionBody;
use App\Services\Reports\InsightReportCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/* Extras */
Route::middleware('throttle:120,1')->group(function () {
    Route::post('fetchcountries', [CountryController::class, 'fetch']);
    Route::post('fetchstates', [StateController::class, 'fetch']);
    Route::post('fetchcities', [CityController::class, 'fetch']);
    Route::get('fetchcurrencies', [CurrencyController::class, 'fetch']);
    Route::get('fetchtimezones', [TimezoneController::class, 'fetch']);
});

/* Public Lead Capture Form (no auth — embeddable on an external site) */
Route::post('leadcapture', [PublicLeadCaptureController::class, 'store'])->middleware('throttle:lead-capture')->name('leadcapture.store');
Route::get('branding', [TenantSelfServiceController::class, 'branding'])->middleware('throttle:60,1');
Route::post('gateway/stripe/webhook/{company}', [IntegrationsController::class, 'stripeWebhook'])->whereNumber('company')->middleware('throttle:60,1');
Route::post('hrm/device/punch', [HrmAttendanceController::class, 'devicePunch'])->middleware('throttle:120,1');
Route::get('storefront/{code}', [PublicStorefrontController::class, 'catalog'])->where('code', '[a-z0-9-]+')->middleware('throttle:120,1');
Route::post('storefront/{code}/orders', [PublicStorefrontController::class, 'order'])->where('code', '[a-z0-9-]+')->middleware('throttle:20,1');
/* Public Lead Capture Form */

Route::middleware(['auth:sanctum', ValidateBulkActionBody::class])->group(function () {
    /* Customer / supplier portal and the accounts for it */
    Route::get('portal', [PortalController::class, 'show']);
    Route::post('portal/change-password', [PortalController::class, 'changePassword']);
    Route::get('portal-users', [PortalUserController::class, 'index']);
    Route::post('portal-users', [PortalUserController::class, 'store']);
    Route::post('portal-users/{id}/reset-password', [PortalUserController::class, 'resetPassword']);
    Route::delete('portal-users/{id}', [PortalUserController::class, 'destroy']);

    /* Customer Self-Service Portal (Customer Subscription Module) */
    Route::get('portal/subscriptions', [PortalSubscriptionController::class, 'index']);
    Route::get('portal/subscription-plans', [PortalSubscriptionController::class, 'availablePlans']);
    Route::get('portal/subscription-invoices', [PortalSubscriptionController::class, 'invoices']);
    Route::post('portal/subscriptions/{id}/change-plan', [PortalSubscriptionController::class, 'changePlan']);
    Route::post('portal/subscriptions/{id}/pause', [PortalSubscriptionController::class, 'pause']);
    Route::post('portal/subscriptions/{id}/resume', [PortalSubscriptionController::class, 'resume']);
    Route::post('portal/subscriptions/{id}/cancel', [PortalSubscriptionController::class, 'cancel']);
    /* Customer / supplier portal: documents, statement, requests and bills (rate limited per account) */
    Route::middleware('throttle:portal')->group(function () {
        Route::get('portal/payment-info', [PortalController::class, 'paymentInfo']);
        Route::get('portal/invoices', [PortalCustomerController::class, 'invoices']);
        Route::get('portal/invoices/{id}', [PortalCustomerController::class, 'invoice']);
        Route::get('portal/statement', [PortalCustomerController::class, 'statement']);
        Route::get('portal/products', [PortalCustomerController::class, 'products']);
        Route::get('portal/requests', [PortalCustomerController::class, 'requests']);
        Route::get('portal/purchase-orders', [PortalSupplierController::class, 'purchaseOrders']);
        Route::get('portal/purchase-orders/{id}', [PortalSupplierController::class, 'purchaseOrder']);
        Route::get('portal/bills', [PortalSupplierController::class, 'bills']);
        Route::get('portal/bills/{id}/file', [PortalSupplierController::class, 'billFile']);
    });
    Route::middleware('throttle:portal-submit')->group(function () {
        Route::post('portal/requests', [PortalCustomerController::class, 'storeRequest']);
        Route::post('portal/requests/{id}/cancel', [PortalCustomerController::class, 'cancelRequest']);
        Route::post('portal/bills', [PortalSupplierController::class, 'storeBill']);
    });
    Route::get('portal-requests', [PortalRequestController::class, 'index']);
    Route::get('portal-requests/{id}/document', [PortalRequestController::class, 'document']);
    Route::post('portal-requests/{id}/approve', [PortalRequestController::class, 'approve']);
    Route::post('portal-requests/{id}/reject', [PortalRequestController::class, 'reject']);
    /* Customer Self-Service Portal */

    /* Exchange rates (display only) */
    Route::get('currency-rates', [CurrencyRateController::class, 'index']);
    Route::put('currency-rates', [CurrencyRateController::class, 'update']);

    /* API keys (Sanctum personal access tokens) */
    Route::get('api-keys', [ApiKeyController::class, 'index']);
    Route::post('api-keys', [ApiKeyController::class, 'store']);
    Route::delete('api-keys/{id}', [ApiKeyController::class, 'destroy']);

    /* Dashboard: one lazy call per card group (sales, receivables, inventory, approvals, financial, stats, recent) */
    Route::get('dashboard/{widget}', [DashboardController::class, 'show']);

    /* Menu */
    Route::get('menus/trash', [MenuController::class, 'trash']);
    Route::resource('menus', MenuController::class);
    Route::get('/fetchmenus', [MenuController::class, 'fetchmenus']);
    Route::get('/fetchpermenus', [MenuController::class, 'fetchpermenus']);
    Route::post('/menus/statusupdate', [MenuController::class, 'updatestatus']);
    Route::post('/menus/{id}/sort-order', [MenuController::class, 'updateSortOrder']);
    Route::post('/menus/import', [MenuController::class, 'import']);
    Route::post('/menus/duplicate', [MenuController::class, 'duplicate']);
    Route::post('/menus/bulk_delete', [MenuController::class, 'bulk_delete']);
    Route::post('menus/bulk_delete_per', [MenuController::class, 'bulk_delete_per']);
    Route::post('menus/restore_records', [MenuController::class, 'restore_records']);
    Route::get('getpermissions', [MenuController::class, 'getpermission']);
    /* Menu */

    /* Role */
    Route::post('roles/check-name', [RoleController::class, 'checkName']);
    Route::post('/roles/import', [RoleController::class, 'import']);
    Route::get('roles/trash', [RoleController::class, 'trash']);
    Route::resource('roles', RoleController::class);
    Route::get('/fetchroles', [RoleController::class, 'fetchroles']);
    Route::post('/roles/statusupdate', [RoleController::class, 'updatestatus']);
    Route::post('/roles/duplicate', [RoleController::class, 'duplicate']);
    Route::post('/roles/bulk_delete', [RoleController::class, 'bulk_delete']);
    Route::post('roles/bulk_delete_per', [RoleController::class, 'bulk_delete_per']);
    Route::post('roles/restore_records', [RoleController::class, 'restore_records']);
    Route::get('/fetchcompanies', [CompanyController::class, 'fetch']);
    Route::get('/fetchbranches', [BranchController::class, 'fetch']);
    Route::get('/fetchdepartments', [DepartmentController::class, 'fetch']);
    Route::get('/fetchcustomergroups', [CustomerGroupController::class, 'fetch']);
    /* Role */

    /* Branch */
    Route::get('branches/generate-code', [BranchController::class, 'generateCode']);
    Route::get('branches/trash', [BranchController::class, 'trash']);
    Route::resource('branches', BranchController::class);
    Route::post('/branches/statusupdate', [BranchController::class, 'updatestatus']);
    Route::post('/branches/import', [BranchController::class, 'import']);
    Route::post('/branches/duplicate', [BranchController::class, 'duplicate']);
    Route::post('/branches/bulk_delete', [BranchController::class, 'bulk_delete']);
    Route::post('branches/bulk_delete_per', [BranchController::class, 'bulk_delete_per']);
    Route::post('branches/restore_records', [BranchController::class, 'restore_records']);
    /* Branch */

    /* Company */
    Route::get('companies/generate-code', [CompanyController::class, 'generateCode']);
    Route::post('companies/check-code', [CompanyController::class, 'checkCode']);
    Route::post('companies/check-admin-identity', [CompanyController::class, 'checkAdminIdentity']);
    Route::get('companies/trash', [CompanyController::class, 'trash']);
    Route::post('/companies/import', [CompanyController::class, 'import']);
    Route::resource('companies', CompanyController::class);
    Route::post('/companies/statusupdate', [CompanyController::class, 'updatestatus']);
    Route::post('/companies/duplicate', [CompanyController::class, 'duplicate']);
    Route::post('/companies/bulk_delete', [CompanyController::class, 'bulk_delete']);
    Route::post('companies/bulk_delete_per', [CompanyController::class, 'bulk_delete_per']);
    Route::post('companies/restore_records', [CompanyController::class, 'restore_records']);
    Route::post('companies/{id}/send-credentials', [CompanyController::class, 'sendCredentials']);
    Route::get('company-settings/{companyId}', [CompanySettingController::class, 'show']);
    Route::put('company-settings/{companyId}', [CompanySettingController::class, 'update']);
    Route::get('software-settings', [SettingController::class, 'show']);
    Route::put('software-settings', [SettingController::class, 'update']);
    Route::post('software-settings/test-smtp', [SettingController::class, 'testSmtp']);
    Route::post('software-settings/test-send', [SettingController::class, 'testSend']);
    Route::get('fetchparentaccounts', [ChartOfAccountController::class, 'fetchParentAccounts']);
    Route::get('fetchcontrolaccounts', [ChartOfAccountController::class, 'fetchControlAccounts']);
    Route::get('fetchchildaccounts', [ChartOfAccountController::class, 'fetchChildAccounts']);
    Route::get('fetchallaccounts', [ChartOfAccountController::class, 'fetchAllAccounts']);
    Route::get('fetchparentsaleaccounts', [ChartOfAccountController::class, 'fetchParentSaleAccounts']);
    Route::get('fetchparentpurchaseaccounts', [ChartOfAccountController::class, 'fetchParentPurchaseAccounts']);
    Route::get('chart-of-accounts/generate-code', [ChartOfAccountController::class, 'generateCode']);
    Route::get('chart-of-accounts/resolve-from-parent', [ChartOfAccountController::class, 'resolveFromParent']);
    Route::post('chart-of-accounts/check-code', [ChartOfAccountController::class, 'checkCode']);
    Route::resource('chart-of-accounts', ChartOfAccountController::class)->only(['index', 'store', 'show', 'update']);
    Route::get('fetchcustomers', [ContactController::class, 'fetchCustomers']);
    Route::post('taxes/statusupdate', [TaxController::class, 'updateStatus']);
    Route::post('taxes/bulk_delete', [TaxController::class, 'bulk_delete']);
    Route::get('fetchtaxes', [TaxController::class, 'fetch']);
    Route::post('taxes/preview', TaxPreviewController::class);
    Route::get('stock-tracking/serials', [StockTrackingController::class, 'serials']);
    Route::get('stock-tracking/batches', [StockTrackingController::class, 'batches']);
    Route::get('stock-tracking/available-serials', [StockTrackingController::class, 'availableSerials']);
    Route::get('stock-tracking/available-batches', [StockTrackingController::class, 'availableBatches']);
    Route::post('stock-tracking/serials', [StockTrackingController::class, 'registerSerials']);
    Route::post('stock-tracking/serials/{id}/write-off', [StockTrackingController::class, 'writeOffSerial']);
    Route::post('stock-tracking/batches', [StockTrackingController::class, 'registerBatch']);
    Route::post('stock-tracking/batches/{id}/write-off', [StockTrackingController::class, 'writeOffBatch']);
    Route::get('fbr-settings', [FbrController::class, 'show']);
    Route::put('fbr-settings', [FbrController::class, 'update']);
    Route::get('fbr-submissions', [FbrController::class, 'submissions']);
    Route::post('fbr-submissions/{transactionId}', [FbrController::class, 'submit']);
    Route::resource('taxes', TaxController::class);
    Route::get('fetchobaccounts', [ChartOfAccountController::class, 'fetchObAccounts']);
    Route::get('account-balances/fetch-balance', [AccountBalanceController::class, 'fetchBalance']);
    Route::post('account-balances', [AccountBalanceController::class, 'store']);
    Route::get('fetchfinancialyears', [FinancialYearController::class, 'fetch']);
    Route::resource('financialyears', FinancialYearController::class);
    Route::get('period-locks', [PeriodLockController::class, 'index']);
    Route::post('period-locks', [PeriodLockController::class, 'store']);
    Route::put('period-locks/rules', [PeriodLockController::class, 'updateRules']);
    Route::get('year-rollover/preview', [YearRolloverController::class, 'preview']);
    Route::post('year-rollover', [YearRolloverController::class, 'run']);
    /* Company */

    /* Permission */
    Route::post('permissions', [PermissionController::class, 'store']);
    Route::get('fetchpermissions', [PermissionController::class, 'fetch']);
    Route::post('permissions/statusupdate', [PermissionController::class, 'updatestatus']);
    /* Permission */

    /* Currency */
    Route::post('currencies/check-code', [CurrencyController::class, 'checkCode']);
    Route::post('currencies/fetch-from-api', [CurrencyController::class, 'fetchFromApi']);
    Route::get('currencies/trash', [CurrencyController::class, 'trash']);
    Route::resource('currencies', CurrencyController::class);
    Route::post('/currencies/statusupdate', [CurrencyController::class, 'updatestatus']);
    Route::post('/currencies/bulk_delete', [CurrencyController::class, 'bulk_delete']);
    Route::post('currencies/bulk_delete_per', [CurrencyController::class, 'bulk_delete_per']);
    Route::post('currencies/restore_records', [CurrencyController::class, 'restore_records']);
    /* Currency */

    /* Timezone */
    Route::post('timezones/check-name', [TimezoneController::class, 'checkName']);
    Route::post('timezones/fetch-from-api', [TimezoneController::class, 'fetchFromApi']);
    Route::get('timezones/trash', [TimezoneController::class, 'trash']);
    Route::resource('timezones', TimezoneController::class);
    Route::post('/timezones/bulk_delete', [TimezoneController::class, 'bulk_delete']);
    Route::post('timezones/bulk_delete_per', [TimezoneController::class, 'bulk_delete_per']);
    Route::post('timezones/restore_records', [TimezoneController::class, 'restore_records']);
    /* Timezone */

    /* Country */
    Route::post('countries/check-name', [CountryController::class, 'checkName']);
    Route::post('countries/check-iso2', [CountryController::class, 'checkIso2']);
    Route::post('countries/fetch-from-api', [CountryController::class, 'fetchFromApi']);
    Route::get('countries/trash', [CountryController::class, 'trash']);
    Route::resource('countries', CountryController::class);
    Route::post('/countries/statusupdate', [CountryController::class, 'updatestatus']);
    Route::post('/countries/bulk_delete', [CountryController::class, 'bulk_delete']);
    Route::post('countries/bulk_delete_per', [CountryController::class, 'bulk_delete_per']);
    Route::post('countries/restore_records', [CountryController::class, 'restore_records']);
    /* Country */

    /* State */
    Route::post('states/check-name', [StateController::class, 'checkName']);
    Route::post('states/fetch-from-api', [StateController::class, 'fetchFromApi']);
    Route::get('states/trash', [StateController::class, 'trash']);
    Route::resource('states', StateController::class);
    Route::post('/states/statusupdate', [StateController::class, 'updatestatus']);
    Route::post('/states/bulk_delete', [StateController::class, 'bulk_delete']);
    Route::post('states/bulk_delete_per', [StateController::class, 'bulk_delete_per']);
    Route::post('states/restore_records', [StateController::class, 'restore_records']);
    /* State */

    /* City */
    Route::post('cities/check-name', [CityController::class, 'checkName']);
    Route::post('cities/fetch-from-api', [CityController::class, 'fetchFromApi']);
    Route::get('cities/trash', [CityController::class, 'trash']);
    Route::resource('cities', CityController::class);
    Route::post('/cities/statusupdate', [CityController::class, 'updatestatus']);
    Route::post('/cities/bulk_delete', [CityController::class, 'bulk_delete']);
    Route::post('cities/bulk_delete_per', [CityController::class, 'bulk_delete_per']);
    Route::post('cities/restore_records', [CityController::class, 'restore_records']);
    /* City */

    /* Pricing */
    Route::get('pricing/resolve', [PricingController::class, 'resolve']);
    Route::post('bulk-price-update/preview', [BulkPriceUpdateController::class, 'preview']);
    Route::post('bulk-price-update/apply', [BulkPriceUpdateController::class, 'apply']);
    /* Pricing */

    /* Landed Cost */
    Route::get('landed-costs', [LandedCostController::class, 'index']);
    Route::get('landed-costs/{id}', [LandedCostController::class, 'show']);
    Route::put('landed-costs/{id}', [LandedCostController::class, 'update']);
    /* Landed Cost */

    /* Bank Reconciliation */
    Route::get('petty-cash/funds', [PettyCashController::class, 'index']);
    Route::post('petty-cash/funds', [PettyCashController::class, 'store']);
    Route::put('petty-cash/funds/{id}', [PettyCashController::class, 'update'])->whereNumber('id');
    Route::delete('petty-cash/funds/{id}', [PettyCashController::class, 'destroy'])->whereNumber('id');
    Route::get('petty-cash/funds/{id}/entries', [PettyCashController::class, 'entries'])->whereNumber('id');
    Route::post('petty-cash/funds/{id}/expense', [PettyCashController::class, 'expense'])->whereNumber('id');
    Route::post('petty-cash/funds/{id}/replenish', [PettyCashController::class, 'replenish'])->whereNumber('id');
    Route::delete('petty-cash/entries/{id}', [PettyCashController::class, 'destroyEntry'])->whereNumber('id');

    Route::get('cheque-layouts', [ChequePrintController::class, 'layouts']);
    Route::post('cheque-layouts', [ChequePrintController::class, 'storeLayout']);
    Route::put('cheque-layouts/{id}', [ChequePrintController::class, 'updateLayout'])->whereNumber('id');
    Route::delete('cheque-layouts/{id}', [ChequePrintController::class, 'destroyLayout'])->whereNumber('id');
    Route::post('cheque-print', [ChequePrintController::class, 'render']);

    Route::post('bank-reconciliations/parse', [BankReconciliationController::class, 'parse']);
    Route::post('bank-reconciliations/{id}/lines/{lineId}/charge', [BankReconciliationController::class, 'charge'])->whereNumber('id')->whereNumber('lineId');

    Route::get('bank-instruments', [BankInstrumentController::class, 'index']);
    Route::post('bank-instruments', [BankInstrumentController::class, 'store']);
    Route::put('bank-instruments/{id}', [BankInstrumentController::class, 'update'])->whereNumber('id');
    Route::delete('bank-instruments/{id}', [BankInstrumentController::class, 'destroy'])->whereNumber('id');

    Route::get('loans', [LoanController::class, 'index']);
    Route::post('loans', [LoanController::class, 'store']);
    Route::get('loans/{id}', [LoanController::class, 'show'])->whereNumber('id');
    Route::post('loans/{id}/pay', [LoanController::class, 'pay'])->whereNumber('id');

    Route::get('tax-payments', [TaxPaymentController::class, 'index']);
    Route::get('tax-payments/summary', [TaxPaymentController::class, 'summary']);
    Route::post('tax-payments', [TaxPaymentController::class, 'store']);
    Route::delete('tax-payments/{id}', [TaxPaymentController::class, 'destroy'])->whereNumber('id');

    Route::get('payment-terms', [PaymentTermController::class, 'index']);
    Route::post('payment-terms', [PaymentTermController::class, 'store']);
    Route::post('payment-terms/assign', [PaymentTermController::class, 'assign']);
    Route::put('payment-terms/{id}', [PaymentTermController::class, 'update'])->whereNumber('id');
    Route::delete('payment-terms/{id}', [PaymentTermController::class, 'destroy'])->whereNumber('id');
    Route::get('early-payment-discounts', [PaymentTermController::class, 'eligible']);
    Route::post('early-payment-discounts', [PaymentTermController::class, 'applyDiscount']);

    Route::get('allocation/suggest', [AllocationController::class, 'suggest']);
    Route::post('allocation/apply', [AllocationController::class, 'apply']);

    Route::get('cash-flow-forecast', [CashFlowForecastController::class, 'show']);

    Route::get('year-close', [YearCloseController::class, 'index']);
    Route::get('year-close/preview', [YearCloseController::class, 'preview']);
    Route::post('year-close', [YearCloseController::class, 'run']);

    Route::get('bank-reconciliations', [BankReconciliationController::class, 'index']);
    Route::get('bank-reconciliations/accounts', [BankReconciliationController::class, 'accounts']);
    Route::post('bank-reconciliations', [BankReconciliationController::class, 'store']);
    Route::get('bank-reconciliations/{id}', [BankReconciliationController::class, 'show']);
    Route::delete('bank-reconciliations/{id}', [BankReconciliationController::class, 'destroy']);
    Route::post('bank-reconciliations/{id}/auto-match', [BankReconciliationController::class, 'autoMatch']);
    Route::post('bank-reconciliations/{id}/lines/{lineId}/match', [BankReconciliationController::class, 'match']);
    Route::post('bank-reconciliations/{id}/lines/{lineId}/unmatch', [BankReconciliationController::class, 'unmatch']);
    Route::post('bank-reconciliations/{id}/reconcile', [BankReconciliationController::class, 'reconcile']);
    /* Bank Reconciliation */

    /* Budgets */
    Route::get('budgets', [BudgetController::class, 'index']);
    Route::post('budgets', [BudgetController::class, 'store']);
    Route::get('budgets/{id}', [BudgetController::class, 'show']);
    Route::put('budgets/{id}', [BudgetController::class, 'update']);
    Route::delete('budgets/{id}', [BudgetController::class, 'destroy']);
    Route::post('budgets/{id}/restore', [BudgetController::class, 'restore']);
    /* Budgets */

    /* Cost Centers */
    Route::get('fetchcostcenters', [CostCenterController::class, 'fetch']);
    Route::get('tax-exemptions', [TaxExemptionController::class, 'index']);
    Route::post('tax-exemptions', [TaxExemptionController::class, 'store']);
    Route::get('tax-exemptions/{id}', [TaxExemptionController::class, 'show']);
    Route::put('tax-exemptions/{id}', [TaxExemptionController::class, 'update']);
    Route::delete('tax-exemptions/{id}', [TaxExemptionController::class, 'destroy']);
    Route::get('cost-centers', [CostCenterController::class, 'index']);
    Route::post('cost-centers', [CostCenterController::class, 'store']);
    Route::get('cost-centers/{id}', [CostCenterController::class, 'show']);
    Route::put('cost-centers/{id}', [CostCenterController::class, 'update']);
    Route::delete('cost-centers/{id}', [CostCenterController::class, 'destroy']);
    /* Cost Centers */

    /* Fixed Assets */
    Route::get('asset-categories', [AssetCategoryController::class, 'index']);
    Route::post('asset-categories', [AssetCategoryController::class, 'store']);
    Route::get('asset-categories/{id}', [AssetCategoryController::class, 'show']);
    Route::put('asset-categories/{id}', [AssetCategoryController::class, 'update']);
    Route::delete('asset-categories/{id}', [AssetCategoryController::class, 'destroy']);
    Route::get('depreciation', [FixedAssetDepreciationController::class, 'index']);
    Route::get('depreciation/preview', [FixedAssetDepreciationController::class, 'preview']);
    Route::post('depreciation/run', [FixedAssetDepreciationController::class, 'run']);
    Route::post('fixed-assets/acquire', [FixedAssetAcquisitionController::class, 'acquire']);
    Route::get('fixed-assets', [FixedAssetController::class, 'index']);
    Route::post('fixed-assets', [FixedAssetController::class, 'store']);
    Route::get('fixed-assets/{id}', [FixedAssetController::class, 'show']);
    Route::put('fixed-assets/{id}', [FixedAssetController::class, 'update']);
    Route::delete('fixed-assets/{id}', [FixedAssetController::class, 'destroy']);
    Route::get('fixed-assets/{id}/history', [FixedAssetAcquisitionController::class, 'history']);
    Route::post('fixed-assets/{id}/revalue', [FixedAssetLifecycleController::class, 'revalue']);
    Route::post('fixed-assets/{id}/impair', [FixedAssetLifecycleController::class, 'impair']);
    Route::post('fixed-assets/{id}/dispose', [FixedAssetLifecycleController::class, 'dispose']);
    Route::get('asset-approvals', [AssetApprovalController::class, 'index']);
    Route::post('asset-approvals/{id}/approve', [AssetApprovalController::class, 'approve']);
    Route::post('asset-approvals/{id}/reject', [AssetApprovalController::class, 'reject']);
    Route::post('fixed-assets/{id}/transfer', [FixedAssetTransferController::class, 'store']);
    Route::post('fixed-assets/{id}/cwip-cost', [FixedAssetAcquisitionController::class, 'cwipCost']);
    Route::post('fixed-assets/{id}/capitalise', [FixedAssetAcquisitionController::class, 'capitalise']);
    /* Fixed Assets */

    /* POS Shifts */
    Route::get('pos-shifts', [PosShiftController::class, 'index']);
    Route::get('pos-shifts/current', [PosShiftController::class, 'current']);
    Route::post('pos-shifts/open', [PosShiftController::class, 'open']);
    Route::get('pos-shifts/{id}', [PosShiftController::class, 'show']);
    Route::post('pos-shifts/{id}/movement', [PosShiftController::class, 'movement']);
    Route::post('pos-shifts/{id}/close', [PosShiftController::class, 'close']);
    /* POS Shifts */

    /* Audit Trail */
    Route::get('audit-logs', [AuditLogController::class, 'index']);
    /* Audit Trail */

    /* Contact Duplicates */
    Route::get('contacts/duplicates', [ContactDuplicateController::class, 'index']);
    Route::post('contacts/duplicates/merge', [ContactDuplicateController::class, 'merge']);
    /* Contact Duplicates */

    /* Supplier */
    Route::get('suppliers/trash', [SupplierController::class, 'trash']);
    Route::get('suppliers/generate-code', [SupplierController::class, 'generateCode']);
    Route::resource('suppliers', SupplierController::class);
    Route::post('suppliers/import', [SupplierController::class, 'import']);
    Route::post('/suppliers/statusupdate', [SupplierController::class, 'updatestatus']);
    Route::post('/suppliers/duplicate', [SupplierController::class, 'duplicate']);
    Route::post('/suppliers/{id}/link-coa', [SupplierController::class, 'linkCoa']);
    Route::post('/suppliers/bulk_delete', [SupplierController::class, 'bulk_delete']);
    Route::post('suppliers/bulk_delete_per', [SupplierController::class, 'bulk_delete_per']);
    Route::post('suppliers/restore_records', [SupplierController::class, 'restore_records']);
    Route::get('/fetchsuppliers', [SupplierController::class, 'fetch']);
    Route::get('/fetchcontactdetail', [SupplierController::class, 'contactDetail']);
    Route::get('/fetchledger', [PartyLedgerReportController::class, 'fetchLedger']);
    Route::get('/fetchledger/pdf', [PartyLedgerReportController::class, 'statementPdf']);
    Route::post('/contact-ledger/send', [PartyLedgerReportController::class, 'sendStatement'])->middleware('throttle:30,1');
    Route::post('/contact-ledger-watches', [PartyLedgerReportController::class, 'saveLedgerWatch']);
    /* Supplier */

    /* Bank */
    Route::get('banks/trash', [BankController::class, 'trash']);
    Route::get('banks/generate-code', [BankController::class, 'generateCode']);
    Route::resource('banks', BankController::class);
    Route::post('/banks/statusupdate', [BankController::class, 'updatestatus']);
    Route::post('/banks/duplicate', [BankController::class, 'duplicate']);
    Route::post('/banks/{id}/link-coa', [BankController::class, 'linkCoa']);
    Route::post('/banks/bulk_delete', [BankController::class, 'bulk_delete']);
    Route::post('banks/bulk_delete_per', [BankController::class, 'bulk_delete_per']);
    Route::post('banks/restore_records', [BankController::class, 'restore_records']);
    Route::get('/fetchbanks', [BankController::class, 'fetch']);
    /* Bank */

    /* Customer */
    Route::get('customers/trash', [CustomerController::class, 'trash']);
    Route::get('customers/generate-code', [CustomerController::class, 'generateCode']);
    Route::resource('customers', CustomerController::class);
    Route::post('customers/import', [CustomerController::class, 'import']);
    Route::get('customers/{id}/attachments', [ContactAttachmentController::class, 'index'])->defaults('kind', 'customer');
    Route::post('customers/{id}/attachments', [ContactAttachmentController::class, 'store'])->defaults('kind', 'customer');
    Route::get('customers/{id}/attachments/{attachmentId}/download', [ContactAttachmentController::class, 'download'])->defaults('kind', 'customer');
    Route::delete('customers/{id}/attachments/{attachmentId}', [ContactAttachmentController::class, 'destroy'])->defaults('kind', 'customer');
    Route::get('suppliers/{id}/attachments', [ContactAttachmentController::class, 'index'])->defaults('kind', 'supplier');
    Route::post('suppliers/{id}/attachments', [ContactAttachmentController::class, 'store'])->defaults('kind', 'supplier');
    Route::get('suppliers/{id}/attachments/{attachmentId}/download', [ContactAttachmentController::class, 'download'])->defaults('kind', 'supplier');
    Route::delete('suppliers/{id}/attachments/{attachmentId}', [ContactAttachmentController::class, 'destroy'])->defaults('kind', 'supplier');
    Route::post('/customers/statusupdate', [CustomerController::class, 'updatestatus']);
    Route::post('/customers/duplicate', [CustomerController::class, 'duplicate']);
    Route::post('/customers/{id}/link-coa', [CustomerController::class, 'linkCoa']);
    Route::post('/customers/bulk_delete', [CustomerController::class, 'bulk_delete']);
    Route::post('customers/bulk_delete_per', [CustomerController::class, 'bulk_delete_per']);
    Route::post('customers/restore_records', [CustomerController::class, 'restore_records']);
    /* Customer */

    /* Customer Group */
    Route::post('customer-groups/check-name', [CustomerGroupController::class, 'checkName']);
    Route::get('customer-groups/trash', [CustomerGroupController::class, 'trash']);
    Route::resource('customer-groups', CustomerGroupController::class);
    Route::post('/customer-groups/statusupdate', [CustomerGroupController::class, 'updatestatus']);
    Route::post('/customer-groups/duplicate', [CustomerGroupController::class, 'duplicate']);
    Route::post('/customer-groups/bulk_delete', [CustomerGroupController::class, 'bulk_delete']);
    Route::post('customer-groups/bulk_delete_per', [CustomerGroupController::class, 'bulk_delete_per']);
    Route::post('customer-groups/restore_records', [CustomerGroupController::class, 'restore_records']);
    /* Customer Group */

    /* Department */
    Route::post('departments/check-name', [DepartmentController::class, 'checkName']);
    Route::post('/departments/import', [DepartmentController::class, 'import']);
    Route::get('departments/trash', [DepartmentController::class, 'trash']);
    Route::resource('departments', DepartmentController::class);
    Route::post('/departments/statusupdate', [DepartmentController::class, 'updatestatus']);
    Route::post('/departments/duplicate', [DepartmentController::class, 'duplicate']);
    Route::post('/departments/bulk_delete', [DepartmentController::class, 'bulk_delete']);
    Route::post('departments/bulk_delete_per', [DepartmentController::class, 'bulk_delete_per']);
    Route::post('departments/restore_records', [DepartmentController::class, 'restore_records']);
    /* Department */

    /* Category */
    Route::post('/categories/import', [CategoryController::class, 'import']);
    Route::post('categories/check-name', [CategoryController::class, 'checkName']);
    Route::get('categories/trash', [CategoryController::class, 'trash']);
    Route::resource('categories', CategoryController::class);
    Route::get('/fetchcategories', [CategoryController::class, 'fetch']);
    Route::get('/fetchsubcategories', [CategoryController::class, 'fetchsub']);
    Route::post('/categories/statusupdate', [CategoryController::class, 'updatestatus']);
    Route::post('/categories/duplicate', [CategoryController::class, 'duplicate']);
    Route::post('/categories/bulk_delete', [CategoryController::class, 'bulk_delete']);
    Route::post('categories/bulk_delete_per', [CategoryController::class, 'bulk_delete_per']);
    Route::post('categories/restore_records', [CategoryController::class, 'restore_records']);
    /* Category */

    /* Brand */
    Route::post('/brands/import', [BrandController::class, 'import']);
    Route::post('brands/check-name', [BrandController::class, 'checkName']);
    Route::get('brands/trash', [BrandController::class, 'trash']);
    Route::resource('brands', BrandController::class);
    Route::get('/fetchbrands', [BrandController::class, 'fetch']);
    Route::post('/brands/statusupdate', [BrandController::class, 'updatestatus']);
    Route::post('/brands/duplicate', [BrandController::class, 'duplicate']);
    Route::post('/brands/bulk_delete', [BrandController::class, 'bulk_delete']);
    Route::post('brands/bulk_delete_per', [BrandController::class, 'bulk_delete_per']);
    Route::post('brands/restore_records', [BrandController::class, 'restore_records']);
    /* Brand */

    /* Warranty */
    Route::post('/warranties/import', [WarrantyController::class, 'import']);
    Route::post('warranties/check-name', [WarrantyController::class, 'checkName']);
    Route::get('warranties/trash', [WarrantyController::class, 'trash']);
    Route::resource('warranties', WarrantyController::class);
    Route::get('/fetchwarranties', [WarrantyController::class, 'fetch']);
    Route::post('/warranties/statusupdate', [WarrantyController::class, 'updatestatus']);
    Route::post('/warranties/duplicate', [WarrantyController::class, 'duplicate']);
    Route::post('/warranties/bulk_delete', [WarrantyController::class, 'bulk_delete']);
    Route::post('warranties/bulk_delete_per', [WarrantyController::class, 'bulk_delete_per']);
    Route::post('warranties/restore_records', [WarrantyController::class, 'restore_records']);
    /* Warranty */

    /* Item Type */
    Route::post('/item-types/import', [ItemTypeController::class, 'import']);
    Route::post('item-types/check-name', [ItemTypeController::class, 'checkName']);
    Route::get('item-types/trash', [ItemTypeController::class, 'trash']);
    Route::resource('item-types', ItemTypeController::class);
    Route::get('/fetchitemtypes', [ItemTypeController::class, 'fetch']);
    Route::post('/item-types/statusupdate', [ItemTypeController::class, 'updatestatus']);
    Route::post('/item-types/duplicate', [ItemTypeController::class, 'duplicate']);
    Route::post('/item-types/bulk_delete', [ItemTypeController::class, 'bulk_delete']);
    Route::post('item-types/bulk_delete_per', [ItemTypeController::class, 'bulk_delete_per']);
    Route::post('item-types/restore_records', [ItemTypeController::class, 'restore_records']);
    /* Item Type */

    /* Journal Entry */
    Route::get('journal-entries/voucher-no', [JournalEntryController::class, 'voucherNo']);
    Route::resource('journal-entries', JournalEntryController::class);
    Route::post('/journal-entries/duplicate', [JournalEntryController::class, 'duplicate']);
    Route::post('/journal-entries/bulk_delete', [JournalEntryController::class, 'bulk_delete']);

    /* Voucher Approval: journal entries, payments, expenses, deposits, fund transfers and credit/debit notes */
    foreach ([
        'journal' => 'journal-entry-approvals',
        'payment' => 'payment-approvals',
        'expense' => 'expense-approvals',
        'deposit' => 'deposit-approvals',
        'fundtransfer' => 'fund-transfer-approvals',
        'creditdebitnote' => 'credit-debit-note-approvals',
    ] as $family => $uri) {
        Route::get($uri, [VoucherApprovalController::class, 'index'])->defaults('family', $family);
        Route::get($uri.'/{id}', [VoucherApprovalController::class, 'show'])->defaults('family', $family);
        Route::post($uri.'/{id}/approve', [VoucherApprovalController::class, 'approve'])->defaults('family', $family);
        Route::post($uri.'/{id}/reject', [VoucherApprovalController::class, 'reject'])->defaults('family', $family);
    }
    /* Voucher Approval */
    /* Journal Entry */

    /* Payment */
    Route::get('payments/voucher-no', [PaymentController::class, 'voucherNo']);
    Route::resource('payments', PaymentController::class);
    Route::post('/payments/duplicate', [PaymentController::class, 'duplicate']);
    Route::post('/payments/bulk_delete', [PaymentController::class, 'bulk_delete']);
    /* Payment */

    /* Expense */
    Route::get('expenses/voucher-no', [ExpenseController::class, 'voucherNo']);
    Route::resource('expenses', ExpenseController::class);
    Route::post('/expenses/duplicate', [ExpenseController::class, 'duplicate']);
    Route::post('/expenses/bulk_delete', [ExpenseController::class, 'bulk_delete']);
    /* Expense */

    /* Deposit */
    Route::get('deposits/voucher-no', [DepositController::class, 'voucherNo']);
    Route::resource('deposits', DepositController::class);
    Route::post('/deposits/duplicate', [DepositController::class, 'duplicate']);
    Route::post('/deposits/bulk_delete', [DepositController::class, 'bulk_delete']);
    /* Deposit */

    /* Fund Transfer */
    Route::get('fundtransfers/voucher-no', [FundTransferController::class, 'voucherNo']);
    Route::resource('fundtransfers', FundTransferController::class);
    Route::post('/fundtransfers/duplicate', [FundTransferController::class, 'duplicate']);
    Route::post('/fundtransfers/bulk_delete', [FundTransferController::class, 'bulk_delete']);
    /* Fund Transfer */

    /* Credit/Debit Note */
    Route::get('credit-debit-notes/voucher-no', [CreditDebitNoteController::class, 'voucherNo']);
    Route::resource('credit-debit-notes', CreditDebitNoteController::class);
    Route::post('/credit-debit-notes/duplicate', [CreditDebitNoteController::class, 'duplicate']);
    Route::post('/credit-debit-notes/bulk_delete', [CreditDebitNoteController::class, 'bulk_delete']);
    /* Credit/Debit Note */

    Route::get('purchases/search-products', [PurchaseController::class, 'searchProducts']);
    Route::get('purchases/trash', [PurchaseController::class, 'trash']);
    Route::resource('purchases', PurchaseController::class);
    Route::post('/purchases/statusupdate', [PurchaseController::class, 'updatestatus']);
    Route::post('/purchases/duplicate', [PurchaseController::class, 'duplicate']);
    Route::post('/purchases/bulk_delete', [PurchaseController::class, 'bulk_delete']);
    Route::post('purchases/bulk_delete_per', [PurchaseController::class, 'bulk_delete_per']);
    Route::post('purchases/restore_records', [PurchaseController::class, 'restore_records']);
    /* Purchase */

    /* Purchase Requisition */
    Route::get('purchase-requisitions/eligible', [PurchaseRequisitionController::class, 'eligible']);
    Route::get('purchase-requisitions/{id}/lines', [PurchaseRequisitionController::class, 'linesForConversion']);
    Route::resource('purchase-requisitions', PurchaseRequisitionController::class);
    Route::post('/purchase-requisitions/bulk_delete', [PurchaseRequisitionController::class, 'bulk_delete']);

    Route::get('purchase-requisition-approvals', [PurchaseRequisitionApprovalController::class, 'index']);
    Route::get('purchase-requisition-approvals/{id}', [PurchaseRequisitionApprovalController::class, 'show']);
    Route::post('purchase-requisition-approvals/{id}/approve', [PurchaseRequisitionApprovalController::class, 'approve']);
    Route::post('purchase-requisition-approvals/{id}/reject', [PurchaseRequisitionApprovalController::class, 'reject']);
    /* Purchase Requisition */

    /* Stock Transfer */
    Route::get('stocktransfers/search-products', [StockTransferController::class, 'searchProducts']);
    Route::get('stocktransfers/trash', [StockTransferController::class, 'trash']);
    Route::resource('stocktransfers', StockTransferController::class);
    Route::post('stocktransfers/statusupdate', [StockTransferController::class, 'updatestatus']);
    Route::post('stocktransfers/bulk_delete', [StockTransferController::class, 'bulk_delete']);
    Route::post('stocktransfers/bulk_delete_per', [StockTransferController::class, 'bulk_delete_per']);
    Route::post('stocktransfers/restore_records', [StockTransferController::class, 'restore_records']);
    /* Stock Transfer */

    /* Stock Adjustment */
    Route::get('stockadjustments/search-products', [StockAdjustmentController::class, 'searchProducts']);
    Route::get('stockadjustments/trash', [StockAdjustmentController::class, 'trash']);
    Route::resource('stockadjustments', StockAdjustmentController::class);
    Route::post('stockadjustments/statusupdate', [StockAdjustmentController::class, 'updatestatus']);
    Route::post('stockadjustments/bulk_delete', [StockAdjustmentController::class, 'bulk_delete']);
    Route::post('stockadjustments/bulk_delete_per', [StockAdjustmentController::class, 'bulk_delete_per']);
    Route::post('stockadjustments/restore_records', [StockAdjustmentController::class, 'restore_records']);
    /* Stock Adjustment */

    /* Warehouse */
    Route::get('warehouses/trash', [WarehouseController::class, 'trash']);
    Route::get('fetchwarehouses', [WarehouseController::class, 'fetch']);
    Route::get('warehouse-locations', [WarehouseLocationController::class, 'index']);
    Route::post('warehouse-locations', [WarehouseLocationController::class, 'store']);
    Route::put('warehouse-locations/{id}', [WarehouseLocationController::class, 'update']);
    Route::delete('warehouse-locations/{id}', [WarehouseLocationController::class, 'destroy']);
    Route::resource('warehouses', WarehouseController::class);
    Route::post('warehouses/statusupdate', [WarehouseController::class, 'updatestatus']);
    Route::post('warehouses/bulk_delete', [WarehouseController::class, 'bulk_delete']);
    Route::post('warehouses/bulk_delete_per', [WarehouseController::class, 'bulk_delete_per']);
    Route::post('warehouses/restore_records', [WarehouseController::class, 'restore_records']);
    /* Warehouse */

    /* Consumer */
    Route::get('consumers/trash', [ConsumerController::class, 'trash']);
    Route::get('fetchconsumers', [ConsumerController::class, 'fetch']);
    Route::resource('consumers', ConsumerController::class);
    Route::post('consumers/statusupdate', [ConsumerController::class, 'updatestatus']);
    Route::post('consumers/bulk_delete', [ConsumerController::class, 'bulk_delete']);
    Route::post('consumers/bulk_delete_per', [ConsumerController::class, 'bulk_delete_per']);
    Route::post('consumers/restore_records', [ConsumerController::class, 'restore_records']);
    /* Consumer */

    /* Bank Issuer */
    Route::get('bank-issuers/trash', [BankIssuerController::class, 'trash']);
    Route::get('fetchbankissuers', [BankIssuerController::class, 'fetch']);
    Route::resource('bank-issuers', BankIssuerController::class);
    Route::post('bank-issuers/statusupdate', [BankIssuerController::class, 'updatestatus']);
    Route::post('bank-issuers/bulk_delete', [BankIssuerController::class, 'bulk_delete']);
    Route::post('bank-issuers/bulk_delete_per', [BankIssuerController::class, 'bulk_delete_per']);
    Route::post('bank-issuers/restore_records', [BankIssuerController::class, 'restore_records']);
    /* Bank Issuer */

    /* Cash Collection */
    Route::get('cash-collections/trash', [CashCollectionController::class, 'trash']);
    Route::get('cash-collections/open-invoices', [CashCollectionController::class, 'openInvoices']);
    Route::resource('cash-collections', CashCollectionController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::post('cash-collections/{id}/complete', [CashCollectionController::class, 'complete']);
    Route::post('cash-collections/{id}/cancel', [CashCollectionController::class, 'cancel']);
    Route::post('cash-collections/{id}/apply-advance', [CashCollectionController::class, 'applyAdvance']);
    Route::post('cash-collections/{id}/refund-advance', [CashCollectionController::class, 'refundAdvance']);
    Route::post('cash-collections/{id}/reverse', [CashCollectionController::class, 'reverse']);
    Route::post('cash-collections/bulk_delete', [CashCollectionController::class, 'bulk_delete']);
    Route::post('cash-collections/bulk_delete_per', [CashCollectionController::class, 'bulk_delete_per']);
    Route::post('cash-collections/restore_records', [CashCollectionController::class, 'restore_records']);
    /* Cash Collection */

    /* Backup */
    Route::get('backups', [BackupController::class, 'index']);
    Route::post('backups', [BackupController::class, 'store']);
    Route::get('backups/{id}/download', [BackupController::class, 'download']);
    Route::delete('backups/{id}', [BackupController::class, 'destroy']);
    /* Backup */

    /* Document settings (barcode, invoice, receipt printer) */
    Route::get('document-settings/{group}', [DocumentSettingController::class, 'show']);
    Route::put('document-settings/{group}', [DocumentSettingController::class, 'update']);
    /* Document settings */

    /* Stock Take */
    Route::get('stock-takes/trash', [StockTakeController::class, 'trash']);
    Route::resource('stock-takes', StockTakeController::class)->only(['index', 'store', 'show', 'destroy']);
    Route::put('stock-takes/{id}/counts', [StockTakeController::class, 'counts']);
    Route::post('stock-takes/{id}/complete', [StockTakeController::class, 'complete']);
    Route::post('stock-takes/bulk_delete', [StockTakeController::class, 'bulk_delete']);
    Route::post('stock-takes/bulk_delete_per', [StockTakeController::class, 'bulk_delete_per']);
    Route::post('stock-takes/restore_records', [StockTakeController::class, 'restore_records']);
    /* Stock Take */

    /* Loyalty */
    Route::get('loyalty/settings', [LoyaltyController::class, 'settings']);
    Route::put('loyalty/settings', [LoyaltyController::class, 'updateSettings']);
    Route::post('loyalty/earn', [LoyaltyController::class, 'earn']);
    Route::post('loyalty/redeem', [LoyaltyController::class, 'redeem']);
    Route::post('loyalty/adjust', [LoyaltyController::class, 'adjust']);
    Route::get('loyalty', [LoyaltyController::class, 'index']);
    Route::get('loyalty/{id}', [LoyaltyController::class, 'show']);
    /* Loyalty */

    /* Discount */
    Route::post('discounts/apply', [DiscountController::class, 'apply']);
    Route::get('discounts/trash', [DiscountController::class, 'trash']);
    Route::get('fetchdiscounts', [DiscountController::class, 'fetch']);
    Route::resource('discounts', DiscountController::class);
    Route::post('discounts/statusupdate', [DiscountController::class, 'updatestatus']);
    Route::post('discounts/bulk_delete', [DiscountController::class, 'bulk_delete']);
    Route::post('discounts/bulk_delete_per', [DiscountController::class, 'bulk_delete_per']);
    Route::post('discounts/restore_records', [DiscountController::class, 'restore_records']);
    /* Discount */

    /* Gift Card */
    Route::post('gift-cards/lookup', [GiftCardController::class, 'lookup']);
    Route::get('gift-cards/orphan-refunds', [GiftCardController::class, 'orphanRefunds']);
    Route::post('gift-cards/orphan-refunds/{id}/resolve', [GiftCardController::class, 'resolveOrphanRefund']);
    Route::post('gift-cards/{id}/redeem', [GiftCardController::class, 'redeem']);
    Route::post('gift-cards/{id}/topup', [GiftCardController::class, 'topup']);
    Route::get('gift-cards/trash', [GiftCardController::class, 'trash']);
    Route::get('fetchgiftcards', [GiftCardController::class, 'fetch']);
    Route::resource('gift-cards', GiftCardController::class);
    Route::post('gift-cards/statusupdate', [GiftCardController::class, 'updatestatus']);
    Route::post('gift-cards/bulk_delete', [GiftCardController::class, 'bulk_delete']);
    Route::post('gift-cards/bulk_delete_per', [GiftCardController::class, 'bulk_delete_per']);
    Route::post('gift-cards/restore_records', [GiftCardController::class, 'restore_records']);
    /* Gift Card */

    /* Transporter */
    Route::get('transporters/trash', [TransporterController::class, 'trash']);
    Route::get('fetchtransporters', [TransporterController::class, 'fetch']);
    Route::resource('transporters', TransporterController::class);
    Route::post('transporters/statusupdate', [TransporterController::class, 'updatestatus']);
    Route::post('transporters/bulk_delete', [TransporterController::class, 'bulk_delete']);
    Route::post('transporters/bulk_delete_per', [TransporterController::class, 'bulk_delete_per']);
    Route::post('transporters/restore_records', [TransporterController::class, 'restore_records']);
    /* Transporter */

    /* Commission Agent */
    Route::get('commission-agents/trash', [CommissionAgentController::class, 'trash']);
    Route::get('fetchcommissionagents', [CommissionAgentController::class, 'fetch']);
    Route::resource('commission-agents', CommissionAgentController::class);
    Route::post('commission-agents/statusupdate', [CommissionAgentController::class, 'updatestatus']);
    Route::post('commission-agents/bulk_delete', [CommissionAgentController::class, 'bulk_delete']);
    Route::post('commission-agents/bulk_delete_per', [CommissionAgentController::class, 'bulk_delete_per']);
    Route::post('commission-agents/restore_records', [CommissionAgentController::class, 'restore_records']);
    /* Commission Agent */

    /* Leads (CRM) */
    Route::get('leads/trash', [LeadController::class, 'trash']);
    Route::get('fetchleads', [LeadController::class, 'fetch']);
    // apiResource (not resource): "leads" is also the Inertia page prefix, so the web.php page route
    // names (leads.edit, leads.view) would collide with a full resource's own leads.edit/leads.show
    // names. apiResource only registers index/store/show/update/destroy, which is all this JSON
    // controller has anyway.
    Route::apiResource('leads', LeadController::class);
    Route::post('leads/statusupdate', [LeadController::class, 'updatestatus']);
    Route::post('leads/bulk_delete', [LeadController::class, 'bulk_delete']);
    Route::post('leads/bulk_delete_per', [LeadController::class, 'bulk_delete_per']);
    Route::post('leads/restore_records', [LeadController::class, 'restore_records']);
    Route::post('leads/import', [LeadController::class, 'import']);
    /* Leads (CRM) */

    /* Lead Sources (CRM) */
    Route::get('lead-sources/trash', [LeadSourceController::class, 'trash']);
    Route::get('fetchleadsources', [LeadSourceController::class, 'fetch']);
    Route::apiResource('lead-sources', LeadSourceController::class);
    Route::post('lead-sources/statusupdate', [LeadSourceController::class, 'updatestatus']);
    Route::post('lead-sources/bulk_delete', [LeadSourceController::class, 'bulk_delete']);
    Route::post('lead-sources/bulk_delete_per', [LeadSourceController::class, 'bulk_delete_per']);
    Route::post('lead-sources/restore_records', [LeadSourceController::class, 'restore_records']);
    /* Lead Sources (CRM) */

    /* Pipeline Stages (CRM) */
    Route::get('pipeline-stages/trash', [PipelineStageController::class, 'trash']);
    Route::get('fetchpipelinestages', [PipelineStageController::class, 'fetch']);
    Route::apiResource('pipeline-stages', PipelineStageController::class);
    Route::post('pipeline-stages/statusupdate', [PipelineStageController::class, 'updatestatus']);
    Route::post('pipeline-stages/bulk_delete', [PipelineStageController::class, 'bulk_delete']);
    Route::post('pipeline-stages/bulk_delete_per', [PipelineStageController::class, 'bulk_delete_per']);
    Route::post('pipeline-stages/restore_records', [PipelineStageController::class, 'restore_records']);
    /* Pipeline Stages (CRM) */

    /* Opportunities (CRM) */
    Route::get('opportunities/trash', [OpportunityController::class, 'trash']);
    Route::get('opportunities/board', [OpportunityController::class, 'board']);
    Route::get('fetchopportunities', [OpportunityController::class, 'fetch']);
    Route::post('opportunities/{id}/move-stage', [OpportunityController::class, 'moveStage']);
    Route::apiResource('opportunities', OpportunityController::class);
    Route::post('opportunities/bulk_delete', [OpportunityController::class, 'bulk_delete']);
    Route::post('opportunities/bulk_delete_per', [OpportunityController::class, 'bulk_delete_per']);
    Route::post('opportunities/restore_records', [OpportunityController::class, 'restore_records']);
    /* Opportunities (CRM) */

    /* Activities (CRM) */
    Route::get('activities/trash', [ActivityController::class, 'trash']);
    Route::get('activities/timeline', [ActivityController::class, 'timeline']);
    Route::post('activities/{id}/complete', [ActivityController::class, 'complete']);
    Route::apiResource('activities', ActivityController::class);
    Route::post('activities/bulk_delete', [ActivityController::class, 'bulk_delete']);
    Route::post('activities/bulk_delete_per', [ActivityController::class, 'bulk_delete_per']);
    Route::post('activities/restore_records', [ActivityController::class, 'restore_records']);
    /* Activities (CRM) */

    /* CRM Analytics */
    Route::get('crmanalytics/conversion', [CrmReportController::class, 'conversion']);
    Route::get('crmanalytics/pipeline', [CrmReportController::class, 'pipeline']);
    Route::get('crmanalytics/sales-activity', [CrmReportController::class, 'salesActivity']);
    Route::get('crmanalytics/salesperson-performance', [CrmReportController::class, 'salespersonPerformance']);
    /* CRM Analytics */

    /* Subscription Plans (SaaS Tenant Billing) */
    Route::get('subscriptionplans/trash', [SubscriptionPlanController::class, 'trash']);
    Route::get('fetchsubscriptionplans', [SubscriptionPlanController::class, 'fetch']);
    Route::apiResource('subscriptionplans', SubscriptionPlanController::class);
    Route::post('subscriptionplans/bulk_delete', [SubscriptionPlanController::class, 'bulk_delete']);
    Route::post('subscriptionplans/bulk_delete_per', [SubscriptionPlanController::class, 'bulk_delete_per']);
    Route::post('subscriptionplans/restore_records', [SubscriptionPlanController::class, 'restore_records']);
    /* Subscription Plans (SaaS Tenant Billing) */

    /* Blog posts (public site content, superadmin only) */
    Route::get('blog-posts/trash', [BlogPostController::class, 'trash']);
    Route::apiResource('blog-posts', BlogPostController::class)->parameters(['blog-posts' => 'blog_post']);
    Route::post('blog-posts/bulk_delete', [BlogPostController::class, 'bulk_delete']);
    Route::post('blog-posts/bulk_delete_per', [BlogPostController::class, 'bulk_delete_per']);
    Route::post('blog-posts/restore_records', [BlogPostController::class, 'restore_records']);
    /* Blog posts */

    /* Coupons (SaaS Tenant Billing) */
    Route::get('coupons/trash', [CouponController::class, 'trash']);
    Route::get('fetchcoupons', [CouponController::class, 'fetch']);
    Route::apiResource('coupons', CouponController::class);
    Route::post('coupons/bulk_delete', [CouponController::class, 'bulk_delete']);
    Route::post('coupons/bulk_delete_per', [CouponController::class, 'bulk_delete_per']);
    Route::post('coupons/restore_records', [CouponController::class, 'restore_records']);
    /* Coupons (SaaS Tenant Billing) */

    /* Tenant Directory (SaaS Tenant Billing) */
    Route::get('tenants', [TenantController::class, 'index']);
    Route::get('tenants/{id}', [TenantController::class, 'show']);
    Route::post('tenants/{id}/status', [TenantController::class, 'updateStatus']);
    Route::post('tenants/{id}/plan', [TenantController::class, 'changePlan']);
    /* Tenant Directory (SaaS Tenant Billing) */

    /* Subscription Invoices (SaaS Tenant Billing) */
    Route::get('subscriptioninvoices', [SubscriptionInvoiceController::class, 'index']);
    Route::get('subscriptioninvoices/{id}', [SubscriptionInvoiceController::class, 'show']);
    Route::post('subscriptioninvoices/generate', [SubscriptionInvoiceController::class, 'generate']);
    Route::post('subscriptioninvoices/{id}/mark-paid', [SubscriptionInvoiceController::class, 'markPaid']);
    Route::post('subscriptioninvoices/{id}/cancel', [SubscriptionInvoiceController::class, 'cancel']);
    /* Subscription Invoices (SaaS Tenant Billing) */

    /* Webhooks (Integrations & Open API) */
    Route::get('webhooks/trash', [WebhookController::class, 'trash']);
    Route::apiResource('webhooks', WebhookController::class);
    Route::post('webhooks/bulk_delete', [WebhookController::class, 'bulk_delete']);
    Route::post('webhooks/bulk_delete_per', [WebhookController::class, 'bulk_delete_per']);
    Route::post('webhooks/restore_records', [WebhookController::class, 'restore_records']);
    Route::get('webhooks/{id}/deliveries', [WebhookController::class, 'deliveries']);
    /* Webhooks (Integrations & Open API) */

    /* API Logs (Integrations & Open API) */
    Route::get('apilogs', [ApiLogController::class, 'index']);
    /* API Logs (Integrations & Open API) */

    /* Customer Subscription Plans (Customer Subscription Module) */
    Route::get('customersubscriptionplans/trash', [CustomerSubscriptionPlanController::class, 'trash']);
    Route::get('fetchcustomersubscriptionplans', [CustomerSubscriptionPlanController::class, 'fetch']);
    Route::apiResource('customersubscriptionplans', CustomerSubscriptionPlanController::class);
    Route::post('customersubscriptionplans/bulk_delete', [CustomerSubscriptionPlanController::class, 'bulk_delete']);
    Route::post('customersubscriptionplans/bulk_delete_per', [CustomerSubscriptionPlanController::class, 'bulk_delete_per']);
    Route::post('customersubscriptionplans/restore_records', [CustomerSubscriptionPlanController::class, 'restore_records']);
    /* Customer Subscription Plans (Customer Subscription Module) */

    /* Customer Subscriptions (Customer Subscription Module) */
    Route::get('customersubscriptions', [CustomerSubscriptionController::class, 'index']);
    Route::post('customersubscriptions', [CustomerSubscriptionController::class, 'store']);
    Route::get('customersubscriptions/{id}', [CustomerSubscriptionController::class, 'show']);
    Route::post('customersubscriptions/{id}/change-plan', [CustomerSubscriptionController::class, 'changePlan']);
    Route::post('customersubscriptions/{id}/pause', [CustomerSubscriptionController::class, 'pause']);
    Route::post('customersubscriptions/{id}/resume', [CustomerSubscriptionController::class, 'resume']);
    Route::post('customersubscriptions/{id}/cancel', [CustomerSubscriptionController::class, 'cancel']);
    Route::post('customersubscriptions/{id}/usage', [CustomerSubscriptionController::class, 'recordUsage']);
    /* Customer Subscriptions (Customer Subscription Module) */

    /* Customer Subscription Invoices (Customer Subscription Module) */
    Route::get('customersubscriptioninvoices', [CustomerSubscriptionInvoiceController::class, 'index']);
    Route::get('customersubscriptioninvoices/{id}', [CustomerSubscriptionInvoiceController::class, 'show']);
    Route::post('customersubscriptioninvoices/generate', [CustomerSubscriptionInvoiceController::class, 'generate']);
    Route::post('customersubscriptioninvoices/{id}/mark-paid', [CustomerSubscriptionInvoiceController::class, 'markPaid']);
    Route::post('customersubscriptioninvoices/{id}/cancel', [CustomerSubscriptionInvoiceController::class, 'cancel']);
    Route::post('customersubscriptioninvoices/{id}/refund', [CustomerSubscriptionInvoiceController::class, 'refund']);
    /* Customer Subscription Invoices (Customer Subscription Module) */

    /* Customer Subscription Analytics (Customer Subscription Module) */
    Route::get('customersubscriptionanalytics/summary', [CustomerSubscriptionReportController::class, 'summary']);
    Route::get('customersubscriptionanalytics/churn', [CustomerSubscriptionReportController::class, 'churn']);
    Route::get('customersubscriptionanalytics/ltv', [CustomerSubscriptionReportController::class, 'ltv']);
    Route::get('customersubscriptionanalytics/revenue', [CustomerSubscriptionReportController::class, 'revenue']);
    Route::get('customersubscriptionanalytics/renewal-due', [CustomerSubscriptionReportController::class, 'renewalDue']);
    /* Customer Subscription Analytics (Customer Subscription Module) */

    /* Price List */
    Route::get('pricelists/search-products', [PriceListController::class, 'searchProducts']);
    Route::get('pricelists/trash', [PriceListController::class, 'trash']);
    Route::resource('pricelists', PriceListController::class);
    Route::post('pricelists/bulk_delete', [PriceListController::class, 'bulk_delete']);
    Route::post('pricelists/bulk_delete_per', [PriceListController::class, 'bulk_delete_per']);
    Route::post('pricelists/restore_records', [PriceListController::class, 'restore_records']);
    /* Price List */

    /* Print Label */
    Route::get('printlabels/search-products', [PrintLabelController::class, 'searchProducts']);
    /* Print Label */

    /* Low Stock */
    Route::get('lowstock', [LowStockController::class, 'index']);
    /* Low Stock */

    /* Transaction list reports */
    foreach (array_keys(TransactionReportController::PERMISSIONS) as $report) {
        Route::get('reports/'.$report, [TransactionReportController::class, 'index'])->defaults('report', $report);
    }
    /* Transaction list reports */

    /* Product and tax reports */
    foreach (array_keys(ProductReportController::PERMISSIONS) as $report) {
        Route::get('reports/'.$report, [ProductReportController::class, 'index'])->defaults('report', $report);
    }
    /* Product and tax reports */

    /* Stock reports */
    foreach (array_keys(StockReportController::PERMISSIONS) as $report) {
        Route::get('reports/'.$report, [StockReportController::class, 'index'])->defaults('report', $report);
    }
    /* Stock reports */

    /* Ledger reports */
    foreach (array_keys(LedgerReportController::PERMISSIONS) as $report) {
        Route::get('reports/'.$report, [LedgerReportController::class, 'index'])->defaults('report', $report);
    }
    /* Ledger reports */

    /* Financial statements */
    foreach (array_keys(FinancialReportController::PERMISSIONS) as $report) {
        Route::get('reports/'.$report, [FinancialReportController::class, 'index'])->defaults('report', $report);
    }
    /* Financial statements */

    /* Analytics reports */
    foreach (array_keys(AnalyticsReportController::PERMISSIONS) as $report) {
        Route::get('reports/'.$report, [AnalyticsReportController::class, 'index'])->defaults('report', $report);
    }
    /* Analytics reports */

    /* Insight reports: comparative statements, sales and profit by dimension, tax and stock take reports */
    foreach (array_keys(InsightReportCatalog::REPORTS) as $report) {
        Route::get('reports/'.$report, [InsightReportController::class, 'index'])->defaults('report', $report);
    }
    /* Insight reports */

    /* Party reports */
    foreach (array_keys(PartyReportController::PERMISSIONS) as $report) {
        Route::get('reports/'.$report, [PartyReportController::class, 'index'])->defaults('report', $report);
    }
    /* Party reports */

    /* Purchase Payment */
    Route::get('purchase-payments/eligible-purchases', [PurchasePaymentController::class, 'eligiblePurchases']);
    Route::get('purchase-payments/purchase/{id}', [PurchasePaymentController::class, 'purchase']);
    Route::get('purchase-payments/accounts', [PurchasePaymentController::class, 'paymentAccounts']);
    Route::resource('purchase-payments', PurchasePaymentController::class);
    Route::post('purchase-payments/bulk_delete', [PurchasePaymentController::class, 'bulk_delete']);
    /* Purchase Payment */

    /* Purchase Approval */
    Route::get('purchase-approvals', [PurchaseApprovalController::class, 'index']);
    Route::get('purchase-approvals/{id}', [PurchaseApprovalController::class, 'show']);
    Route::post('purchase-approvals/{id}/approve', [PurchaseApprovalController::class, 'approve']);
    /* Purchase Approval */

    /* Receiving Note */
    Route::get('receiving-notes/eligible-purchases', [ReceivingNoteController::class, 'eligiblePurchases']);
    Route::get('receiving-notes/purchase/{id}', [ReceivingNoteController::class, 'purchaseLines']);
    Route::get('receiving-notes/trash', [ReceivingNoteController::class, 'trash']);
    Route::resource('receiving-notes', ReceivingNoteController::class);
    Route::post('receiving-notes/bulk_delete', [ReceivingNoteController::class, 'bulk_delete']);
    Route::post('receiving-notes/bulk_delete_per', [ReceivingNoteController::class, 'bulk_delete_per']);
    Route::post('receiving-notes/restore_records', [ReceivingNoteController::class, 'restore_records']);
    /* Receiving Note */

    /* Purchase Return */
    Route::get('purchase-returns/eligible-purchases', [PurchaseReturnController::class, 'eligiblePurchases']);
    Route::get('purchase-returns/purchase/{id}', [PurchaseReturnController::class, 'purchaseLines']);
    Route::get('purchase-returns/trash', [PurchaseReturnController::class, 'trash']);
    Route::resource('purchase-returns', PurchaseReturnController::class);
    Route::post('purchase-returns/bulk_delete', [PurchaseReturnController::class, 'bulk_delete']);
    Route::post('purchase-returns/bulk_delete_per', [PurchaseReturnController::class, 'bulk_delete_per']);
    Route::post('purchase-returns/restore_records', [PurchaseReturnController::class, 'restore_records']);
    /* Purchase Return */

    /* Sell */
    Route::get('sells/search-products', [SellController::class, 'searchProducts']);
    Route::get('sells/trash', [SellController::class, 'trash']);
    Route::put('sells/{id}/shipping', [SellController::class, 'updateShipping']);
    Route::resource('sells', SellController::class);
    Route::post('/sells/statusupdate', [SellController::class, 'updatestatus']);
    Route::post('/sells/duplicate', [SellController::class, 'duplicate']);
    Route::post('/sells/bulk_delete', [SellController::class, 'bulk_delete']);
    Route::post('sells/bulk_delete_per', [SellController::class, 'bulk_delete_per']);
    Route::post('sells/restore_records', [SellController::class, 'restore_records']);
    /* Sell */

    /* Sell Payment */
    Route::get('sell-payments/eligible-sells', [SellPaymentController::class, 'eligibleSells']);
    Route::get('sell-payments/sell/{id}', [SellPaymentController::class, 'sell']);
    Route::get('sell-payments/accounts', [SellPaymentController::class, 'paymentAccounts']);
    Route::resource('sell-payments', SellPaymentController::class);
    Route::post('sell-payments/bulk_delete', [SellPaymentController::class, 'bulk_delete']);
    /* Sell Payment */

    /* Sell Approval */
    Route::get('sell-approvals', [SellApprovalController::class, 'index']);
    Route::get('sell-approvals/{id}', [SellApprovalController::class, 'show']);
    Route::post('sell-approvals/{id}/approve', [SellApprovalController::class, 'approve']);
    /* Sell Approval */

    /* Approval Center (Phase 1, easy half) */
    Route::get('purchase-return-approvals', [PurchaseReturnApprovalController::class, 'index']);
    Route::post('purchase-return-approvals/{id}/approve', [PurchaseReturnApprovalController::class, 'approve']);

    Route::get('stock-adjustment-approvals', [StockAdjustmentApprovalController::class, 'index']);
    Route::post('stock-adjustment-approvals/{id}/approve', [StockAdjustmentApprovalController::class, 'approve']);

    Route::get('stock-transfer-approvals', [StockTransferApprovalController::class, 'index']);
    Route::post('stock-transfer-approvals/{id}/approve', [StockTransferApprovalController::class, 'approve']);

    Route::get('cash-collection-approvals', [CashCollectionApprovalController::class, 'index']);
    Route::post('cash-collection-approvals/{id}/approve', [CashCollectionApprovalController::class, 'approve']);

    Route::get('pricelist-approvals', [PriceListApprovalController::class, 'index']);
    Route::post('pricelist-approvals/{id}/approve', [PriceListApprovalController::class, 'approve']);

    Route::post('credit-limit-requests', [CreditLimitRequestController::class, 'store']);
    Route::get('credit-limit-approvals', [CreditLimitApprovalController::class, 'index']);
    Route::post('credit-limit-approvals/{id}/approve', [CreditLimitApprovalController::class, 'approve']);
    Route::post('credit-limit-approvals/{id}/reject', [CreditLimitApprovalController::class, 'reject']);
    /* Approval Center (Phase 1, easy half) */

    /* Issue Note */
    Route::get('issue-notes/eligible-sells', [IssueNoteController::class, 'eligibleSells']);
    Route::get('issue-notes/sell/{id}', [IssueNoteController::class, 'sellLines']);
    Route::get('issue-notes/trash', [IssueNoteController::class, 'trash']);
    Route::resource('issue-notes', IssueNoteController::class);
    Route::post('issue-notes/bulk_delete', [IssueNoteController::class, 'bulk_delete']);
    Route::post('issue-notes/bulk_delete_per', [IssueNoteController::class, 'bulk_delete_per']);
    Route::post('issue-notes/restore_records', [IssueNoteController::class, 'restore_records']);
    /* Issue Note */

    /* Sell Return */
    Route::get('sell-returns/eligible-sells', [SellReturnController::class, 'eligibleSells']);
    Route::get('sell-returns/sell/{id}', [SellReturnController::class, 'sellLines']);
    Route::get('sell-returns/trash', [SellReturnController::class, 'trash']);
    Route::resource('sell-returns', SellReturnController::class);
    Route::post('sell-returns/bulk_delete', [SellReturnController::class, 'bulk_delete']);
    Route::post('sell-returns/bulk_delete_per', [SellReturnController::class, 'bulk_delete_per']);
    Route::post('sell-returns/restore_records', [SellReturnController::class, 'restore_records']);
    /* Sell Return */

    /* Product */
    Route::post('products/bulk-update', [ProductBulkController::class, 'update']);
    Route::get('products/export-all', [ProductBulkController::class, 'export']);
    Route::get('products/lookup', [ProductBulkController::class, 'lookup']);
    Route::get('products/{id}/components', [ProductComponentController::class, 'show'])->whereNumber('id');
    Route::put('products/{id}/components', [ProductComponentController::class, 'update'])->whereNumber('id');
    Route::post('products/{id}/assemble', [ProductComponentController::class, 'assemble'])->whereNumber('id');
    Route::get('product-components', [ProductComponentController::class, 'index']);
    Route::post('/products/import', [ProductController::class, 'import']);
    Route::post('products/check-name', [ProductController::class, 'checkName']);
    Route::post('/products/generate-variants', [ProductController::class, 'generateVariants']);
    Route::get('products/trash', [ProductController::class, 'trash']);
    Route::resource('products', ProductController::class);
    Route::get('/fetchproducts', [ProductController::class, 'fetch']);
    Route::post('/products/statusupdate', [ProductController::class, 'updatestatus']);
    Route::post('/products/duplicate', [ProductController::class, 'duplicate']);
    Route::post('/products/bulk_delete', [ProductController::class, 'bulk_delete']);
    Route::post('products/bulk_delete_per', [ProductController::class, 'bulk_delete_per']);
    Route::post('products/restore_records', [ProductController::class, 'restore_records']);
    /* Product */

    Route::get('product-attributes', [ProductAttributeController::class, 'index']);
    Route::post('product-attributes', [ProductAttributeController::class, 'store']);
    Route::put('product-attributes/{id}', [ProductAttributeController::class, 'update'])->whereNumber('id');
    Route::delete('product-attributes/{id}', [ProductAttributeController::class, 'destroy'])->whereNumber('id');

    Route::get('product-discount-rules/resolve', [ProductDiscountRuleController::class, 'resolve']);
    Route::get('product-discount-rules', [ProductDiscountRuleController::class, 'index']);
    Route::post('product-discount-rules', [ProductDiscountRuleController::class, 'store']);
    Route::put('product-discount-rules/{id}', [ProductDiscountRuleController::class, 'update'])->whereNumber('id');
    Route::delete('product-discount-rules/{id}', [ProductDiscountRuleController::class, 'destroy'])->whereNumber('id');

    Route::get('warranty-claims', [WarrantyClaimController::class, 'index']);
    Route::post('warranty-claims', [WarrantyClaimController::class, 'store']);
    Route::get('warranty-claims/{id}', [WarrantyClaimController::class, 'show'])->whereNumber('id');
    Route::post('warranty-claims/{id}/status', [WarrantyClaimController::class, 'transition'])->whereNumber('id');
    Route::delete('warranty-claims/{id}', [WarrantyClaimController::class, 'destroy'])->whereNumber('id');

    Route::get('consignment/stock', [ConsignmentController::class, 'stock']);
    Route::get('consignment/settlements', [ConsignmentController::class, 'index']);
    Route::post('consignment/settlements', [ConsignmentController::class, 'store']);
    Route::get('consignment/settlements/{id}', [ConsignmentController::class, 'show'])->whereNumber('id');
    Route::post('consignment/settlements/{id}/settle', [ConsignmentController::class, 'settle'])->whereNumber('id');
    Route::delete('consignment/settlements/{id}', [ConsignmentController::class, 'destroy'])->whereNumber('id');

    Route::get('stock-reservations/availability', [StockReservationController::class, 'availability']);
    Route::get('stock-reservations', [StockReservationController::class, 'index']);
    Route::post('stock-reservations', [StockReservationController::class, 'store']);
    Route::post('stock-reservations/{id}/close', [StockReservationController::class, 'close'])->whereNumber('id');

    Route::get('warehouse-tasks', [WarehouseTaskController::class, 'index']);
    Route::post('warehouse-tasks', [WarehouseTaskController::class, 'store']);
    Route::get('warehouse-tasks/{id}', [WarehouseTaskController::class, 'show'])->whereNumber('id');
    Route::post('warehouse-tasks/{id}/progress', [WarehouseTaskController::class, 'progress'])->whereNumber('id');
    Route::post('warehouse-tasks/{id}/complete', [WarehouseTaskController::class, 'complete'])->whereNumber('id');
    Route::post('warehouse-tasks/{id}/cancel', [WarehouseTaskController::class, 'cancel'])->whereNumber('id');

    Route::get('pos/offline/catalog', [PosOfflineController::class, 'catalog']);
    Route::post('pos/offline/sync', [PosOfflineController::class, 'sync']);
    Route::get('pos/offline/sales', [PosOfflineController::class, 'index']);
    Route::post('pos/offline/sales/{id}/resolve', [PosOfflineController::class, 'resolve'])->whereNumber('id');

    Route::get('interbranch', [InterBranchController::class, 'index']);
    Route::put('interbranch/settings', [InterBranchController::class, 'saveSettings']);
    Route::get('interbranch/consolidation', [InterBranchController::class, 'consolidation']);
    Route::post('interbranch', [InterBranchController::class, 'store']);
    Route::post('interbranch/{id}/reverse', [InterBranchController::class, 'reverse'])->whereNumber('id');

    /* Wave 3: SaaS billing and integrations */
    Route::get('saas/overview', [SaasAdminController::class, 'overview']);
    Route::put('saas/plans/{id}', [SaasAdminController::class, 'savePlan'])->whereNumber('id');
    Route::get('saas/usage/{companyId}', [SaasAdminController::class, 'usage'])->whereNumber('companyId');
    Route::post('saas/usage/snapshot', [SaasAdminController::class, 'snapshot']);
    Route::post('saas/partners', [SaasAdminController::class, 'savePartner']);
    Route::put('saas/partners/{id}', [SaasAdminController::class, 'savePartner'])->whereNumber('id');
    Route::get('saas/partners/{partnerId}/statement', [SaasAdminController::class, 'statement'])->whereNumber('partnerId');
    Route::put('saas/companies/{companyId}/partner', [SaasAdminController::class, 'assignPartner'])->whereNumber('companyId');
    Route::post('saas/addons', [SaasAdminController::class, 'saveAddon']);
    Route::put('saas/addons/{id}', [SaasAdminController::class, 'saveAddon'])->whereNumber('id');
    Route::post('saas/companies/{companyId}/addons', [SaasAdminController::class, 'assignAddon'])->whereNumber('companyId');
    Route::delete('saas/companies/{companyId}/addons/{id}', [SaasAdminController::class, 'removeAddon'])->whereNumber('companyId')->whereNumber('id');
    Route::post('saas/billing/run', [SaasAdminController::class, 'runBilling']);
    Route::post('saas/deletions/purge-due', [SaasAdminController::class, 'purgeDue']);
    Route::post('saas/companies/{companyId}/reactivate', [SaasAdminController::class, 'reactivate'])->whereNumber('companyId');
    Route::get('tenant-billing/overview', [TenantSelfServiceController::class, 'overview']);
    Route::post('tenant-billing/cancel', [TenantSelfServiceController::class, 'cancel']);
    Route::post('tenant-billing/renew', [TenantSelfServiceController::class, 'renew']);
    Route::post('tenant-billing/exports', [TenantSelfServiceController::class, 'export']);
    Route::get('tenant-billing/exports/{id}/download', [TenantSelfServiceController::class, 'downloadExport'])->whereNumber('id');
    Route::post('tenant-billing/deletion/request', [TenantSelfServiceController::class, 'requestDeletion']);
    Route::post('tenant-billing/deletion/confirm', [TenantSelfServiceController::class, 'confirmDeletion']);
    Route::post('tenant-billing/deletion/cancel', [TenantSelfServiceController::class, 'cancelDeletion']);
    Route::post('tenant-billing/domains', [TenantSelfServiceController::class, 'addDomain']);
    Route::post('tenant-billing/domains/{id}/verify', [TenantSelfServiceController::class, 'verifyDomain'])->whereNumber('id');
    Route::delete('tenant-billing/domains/{id}', [TenantSelfServiceController::class, 'removeDomain'])->whereNumber('id');
    Route::put('tenant-billing/branding', [TenantSelfServiceController::class, 'saveBranding']);
    Route::get('support-tickets', [TenantSelfServiceController::class, 'tickets']);
    Route::post('support-tickets', [TenantSelfServiceController::class, 'openTicket']);
    Route::get('support-tickets/{id}', [TenantSelfServiceController::class, 'ticket'])->whereNumber('id');
    Route::post('support-tickets/{id}/reply', [TenantSelfServiceController::class, 'replyTicket'])->whereNumber('id');
    Route::get('integrations/accounts', [IntegrationsController::class, 'accounts']);
    Route::put('integrations/accounts', [IntegrationsController::class, 'saveAccount']);
    Route::post('integrations/courier/book', [IntegrationsController::class, 'courierBook']);
    Route::get('integrations/courier/track', [IntegrationsController::class, 'courierTrack']);
    Route::post('integrations/gateway/checkout', [IntegrationsController::class, 'gatewayCheckout']);
    Route::post('integrations/bank-feed/fetch', [IntegrationsController::class, 'bankFeedFetch']);
    Route::get('docs/openapi', [IntegrationsController::class, 'openApi']);
    Route::get('docs/postman', [IntegrationsController::class, 'postman']);

    /* Wave 2: Approval Center */
    Route::get('approval-center/types', [ApprovalCenterController::class, 'types']);
    Route::get('approval-center/directory', [ApprovalCenterController::class, 'directory']);
    Route::get('approval-center/workflows', [ApprovalCenterController::class, 'workflows']);
    Route::post('approval-center/workflows', [ApprovalCenterController::class, 'saveWorkflow']);
    Route::put('approval-center/workflows/{id}', [ApprovalCenterController::class, 'saveWorkflow'])->whereNumber('id');
    Route::delete('approval-center/workflows/{id}', [ApprovalCenterController::class, 'deleteWorkflow'])->whereNumber('id');
    Route::get('approval-center/inbox', [ApprovalCenterController::class, 'inbox']);
    Route::get('approval-center/outbox', [ApprovalCenterController::class, 'outbox']);
    Route::get('approval-center/history', [ApprovalCenterController::class, 'history']);
    Route::get('approval-center/delegations', [ApprovalCenterController::class, 'delegations']);
    Route::post('approval-center/delegations', [ApprovalCenterController::class, 'saveDelegation']);
    Route::post('approval-center/delegations/{id}/end', [ApprovalCenterController::class, 'endDelegation'])->whereNumber('id');
    Route::get('approval-center/settings', [ApprovalCenterController::class, 'settings']);
    Route::put('approval-center/settings', [ApprovalCenterController::class, 'saveSettings']);
    Route::get('approval-center/discount-codes', [ApprovalCenterController::class, 'pendingDiscounts']);
    Route::post('approval-center/discount-codes/{id}/{decision}', [ApprovalCenterController::class, 'decideDiscount'])->whereNumber('id');

    /* Wave 1 */
    Route::get('my-notifications', [MyNotificationsController::class, 'index']);
    Route::post('my-notifications/read-all', [MyNotificationsController::class, 'readAll']);
    Route::post('my-notifications/{id}/read', [MyNotificationsController::class, 'read'])->whereNumber('id');
    Route::get('dashboard-insights', [DashboardInsightsController::class, 'show']);
    Route::get('search', UniversalSearchController::class);
    Route::get('voucher-options', VoucherOptionsController::class);
    Route::get('dashboard-tools/layout', [DashboardToolsController::class, 'layout']);
    Route::get('dashboard-tools/role-layouts', [DashboardToolsController::class, 'roleLayouts']);
    Route::put('dashboard-tools/role-layouts/{roleId}', [DashboardToolsController::class, 'saveRoleLayout'])->whereNumber('roleId');
    Route::delete('dashboard-tools/role-layouts/{roleId}', [DashboardToolsController::class, 'resetRoleLayout'])->whereNumber('roleId');
    Route::get('dashboard-tools/summary', [DashboardToolsController::class, 'summary']);
    Route::put('dashboard-tools/summary', [DashboardToolsController::class, 'saveSummary']);
    Route::get('dashboard-tools/summary/preview', [DashboardToolsController::class, 'previewSummary']);
    Route::post('dashboard-tools/summary/send', [DashboardToolsController::class, 'sendSummary']);
    Route::get('dashboard-tools/kpi-catalog', [DashboardToolsController::class, 'kpiCatalog']);
    Route::get('dashboard-tools/kpi-figures/{report}', [DashboardToolsController::class, 'kpiFigures'])->where('report', '[a-z-]+');
    Route::get('dashboard-tools/kpis', [DashboardToolsController::class, 'kpis']);
    Route::post('dashboard-tools/kpis', [DashboardToolsController::class, 'saveKpi']);
    Route::delete('dashboard-tools/kpis/{id}', [DashboardToolsController::class, 'deleteKpi'])->whereNumber('id');
    Route::get('dashboard-preferences', [DashboardInsightsController::class, 'preferences']);
    Route::put('dashboard-preferences', [DashboardInsightsController::class, 'savePreferences']);
    Route::get('crm/allocation-rules', [CrmAutomationController::class, 'rules']);
    Route::post('crm/allocation-rules', [CrmAutomationController::class, 'saveRule']);
    Route::put('crm/allocation-rules/{id}', [CrmAutomationController::class, 'saveRule'])->whereNumber('id');
    Route::delete('crm/allocation-rules/{id}', [CrmAutomationController::class, 'deleteRule'])->whereNumber('id');
    Route::post('crm/allocate-unassigned', [CrmAutomationController::class, 'allocateUnassigned']);
    Route::post('crm/run-reminders', [CrmAutomationController::class, 'runReminders']);
    Route::post('customer-subscription-billing/run', [CustomerSubscriptionBillingController::class, 'run']);
    Route::get('customer-subscription-billing/reminders', [CustomerSubscriptionBillingController::class, 'reminders']);
    Route::get('customer-subscriptions/{id}/refund-quote', [CustomerSubscriptionBillingController::class, 'quote'])->whereNumber('id');
    Route::post('customer-subscriptions/{id}/cancel-prorated', [CustomerSubscriptionBillingController::class, 'cancel'])->whereNumber('id');
    Route::get('security/settings', [SecurityCenterController::class, 'settings']);
    Route::put('security/settings', [SecurityCenterController::class, 'saveSettings']);
    Route::get('security/ip-rules', [SecurityCenterController::class, 'ipRules']);
    Route::post('security/ip-rules', [SecurityCenterController::class, 'addIpRule']);
    Route::delete('security/ip-rules/{id}', [SecurityCenterController::class, 'deleteIpRule'])->whereNumber('id');
    Route::get('security/login-events', [SecurityCenterController::class, 'loginEvents']);
    Route::get('security/devices', [SecurityCenterController::class, 'devices']);
    Route::get('security/sessions', [SecurityCenterController::class, 'sessions']);
    Route::post('security/sessions/{id}/revoke', [SecurityCenterController::class, 'revokeSession'])->whereNumber('id');
    Route::post('security/users/{userId}/revoke-sessions', [SecurityCenterController::class, 'revokeUserSessions'])->whereNumber('userId');
    Route::get('security/privacy', [SecurityCenterController::class, 'privacy']);
    Route::post('security/privacy/export', [SecurityCenterController::class, 'privacyExport']);
    Route::post('security/privacy/anonymize', [SecurityCenterController::class, 'privacyAnonymize']);

    Route::get('foreign-currency', [ForeignCurrencyController::class, 'show']);
    Route::put('foreign-currency/settings', [ForeignCurrencyController::class, 'saveSettings']);
    Route::post('foreign-currency/rates', [ForeignCurrencyController::class, 'addRate']);
    Route::post('foreign-currency/settle', [ForeignCurrencyController::class, 'settle']);

    Route::get('projects', [ProjectController::class, 'index']);
    Route::post('projects', [ProjectController::class, 'store']);
    Route::get('projects/report', [ProjectController::class, 'report']);
    Route::post('projects/tag', [ProjectController::class, 'tag']);
    Route::put('projects/{id}', [ProjectController::class, 'update'])->whereNumber('id');
    Route::delete('projects/{id}', [ProjectController::class, 'destroy'])->whereNumber('id');

    Route::get('dunning/levels', [DunningController::class, 'levels']);
    Route::put('dunning/levels/{level}', [DunningController::class, 'updateLevel'])->whereNumber('level');
    Route::get('dunning/candidates', [DunningController::class, 'candidates']);
    Route::post('dunning/send', [DunningController::class, 'send']);
    Route::get('dunning/events', [DunningController::class, 'events']);
    Route::get('dunning/cases', [DunningController::class, 'cases']);
    Route::post('dunning/cases', [DunningController::class, 'setCase']);

    Route::get('bad-debt/settings', [BadDebtController::class, 'settings']);
    Route::put('bad-debt/settings', [BadDebtController::class, 'saveSettings']);
    Route::get('bad-debt/invoices', [BadDebtController::class, 'invoices']);
    Route::get('bad-debt/writeoffs', [BadDebtController::class, 'writeoffs']);
    Route::post('bad-debt/writeoffs', [BadDebtController::class, 'request']);
    Route::post('bad-debt/writeoffs/{id}/approve', [BadDebtController::class, 'approve'])->whereNumber('id');
    Route::post('bad-debt/writeoffs/{id}/reject', [BadDebtController::class, 'reject'])->whereNumber('id');
    Route::post('bad-debt/writeoffs/{id}/reverse', [BadDebtController::class, 'reverse'])->whereNumber('id');
    Route::get('bad-debt/provision', [BadDebtController::class, 'provisionPreview']);
    Route::post('bad-debt/provision', [BadDebtController::class, 'provision']);

    Route::get('journal-schedules', [JournalScheduleController::class, 'index']);
    Route::post('journal-schedules', [JournalScheduleController::class, 'store']);
    Route::get('journal-schedules/preview', [JournalScheduleController::class, 'preview']);
    Route::post('journal-schedules/run', [JournalScheduleController::class, 'run']);
    Route::put('journal-schedules/{id}', [JournalScheduleController::class, 'update'])->whereNumber('id');
    Route::delete('journal-schedules/{id}', [JournalScheduleController::class, 'destroy'])->whereNumber('id');
    Route::get('journal-schedules/{id}/runs', [JournalScheduleController::class, 'runs'])->whereNumber('id');
    Route::post('journal-schedules/{id}/pause', fn (int $id) => app(JournalScheduleController::class)->setStatus($id, 'paused'))->whereNumber('id');
    Route::post('journal-schedules/{id}/resume', fn (int $id) => app(JournalScheduleController::class)->setStatus($id, 'active'))->whereNumber('id');
    Route::post('journal-schedules/{id}/cancel', fn (int $id) => app(JournalScheduleController::class)->setStatus($id, 'cancelled'))->whereNumber('id');

    Route::get('valuation', [ValuationController::class, 'show']);
    Route::post('valuation/method', [ValuationController::class, 'setMethod']);
    Route::post('valuation/standard-costs', [ValuationController::class, 'setStandardCosts']);
    Route::get('valuation/variance', [ValuationController::class, 'variance']);
    Route::get('valuation/reconciliation', [ValuationController::class, 'reconciliation']);
    Route::get('valuation/recalculation', [ValuationController::class, 'preview']);
    Route::post('valuation/recalculate', [ValuationController::class, 'recalculate']);

    Route::get('stock-writeoffs', [StockWriteoffController::class, 'index']);
    Route::post('stock-writeoffs', [StockWriteoffController::class, 'store']);

    Route::get('inventory-close', [InventoryCloseController::class, 'show']);
    Route::post('inventory-close', [InventoryCloseController::class, 'close']);
    Route::post('inventory-close/reopen', [InventoryCloseController::class, 'reopen']);

    Route::get('purchase-approval-rules', [PurchaseApprovalRuleController::class, 'index']);
    Route::post('purchase-approval-rules', [PurchaseApprovalRuleController::class, 'store']);
    Route::put('purchase-approval-rules/{id}', [PurchaseApprovalRuleController::class, 'update'])->whereNumber('id');
    Route::delete('purchase-approval-rules/{id}', [PurchaseApprovalRuleController::class, 'destroy'])->whereNumber('id');

    Route::get('reorder-suggestions', [ReorderSuggestionController::class, 'index']);
    Route::post('reorder-suggestions/purchase-orders', [ReorderSuggestionController::class, 'createOrders']);

    Route::get('import-purchases', [ImportPurchaseController::class, 'index']);
    Route::get('import-purchases/{id}', [ImportPurchaseController::class, 'show'])->whereNumber('id');
    Route::put('import-purchases/{id}', [ImportPurchaseController::class, 'update'])->whereNumber('id');

    Route::get('quality-inspections', [QualityInspectionController::class, 'index']);
    Route::get('quality-inspections/purchase/{transactionId}/lines', [QualityInspectionController::class, 'lines'])->whereNumber('transactionId');
    Route::post('quality-inspections', [QualityInspectionController::class, 'store']);
    Route::get('quality-inspections/{id}', [QualityInspectionController::class, 'show'])->whereNumber('id');
    Route::delete('quality-inspections/{id}', [QualityInspectionController::class, 'destroy'])->whereNumber('id');

    Route::get('supplier-payments', [SupplierPaymentController::class, 'index']);
    Route::post('supplier-payments', [SupplierPaymentController::class, 'store']);
    Route::get('supplier-payments/{id}', [SupplierPaymentController::class, 'show'])->whereNumber('id');
    Route::post('supplier-payments/{id}/apply-advance', [SupplierPaymentController::class, 'applyAdvance'])->whereNumber('id');
    Route::post('supplier-payments/{id}/refund-advance', [SupplierPaymentController::class, 'refundAdvance'])->whereNumber('id');
    Route::post('supplier-payments/{id}/reverse', [SupplierPaymentController::class, 'reverse'])->whereNumber('id');

    Route::get('inbound-shipments', [InboundShipmentController::class, 'index']);
    Route::post('inbound-shipments', [InboundShipmentController::class, 'store']);
    Route::get('inbound-shipments/{id}', [InboundShipmentController::class, 'show'])->whereNumber('id');
    Route::put('inbound-shipments/{id}', [InboundShipmentController::class, 'update'])->whereNumber('id');

    Route::get('rma', [RmaController::class, 'index']);
    Route::post('rma', [RmaController::class, 'store']);
    Route::post('rma/{id}/status', [RmaController::class, 'transition'])->whereNumber('id');
    Route::delete('rma/{id}', [RmaController::class, 'destroy'])->whereNumber('id');

    Route::get('quotations', [QuotationController::class, 'index']);
    Route::put('quotations/{id}', [QuotationController::class, 'update'])->whereNumber('id');
    Route::post('quotations/{id}/convert', [QuotationController::class, 'convert'])->whereNumber('id');

    Route::get('sales-orders', [SalesOrderController::class, 'index']);
    Route::post('sales-orders', [SalesOrderController::class, 'store']);
    Route::get('sales-orders/{id}', [SalesOrderController::class, 'show'])->whereNumber('id');
    Route::post('sales-orders/{id}/submit', [SalesOrderController::class, 'submit'])->whereNumber('id');
    Route::post('sales-orders/{id}/approve', [SalesOrderController::class, 'approve'])->whereNumber('id');
    Route::post('sales-orders/{id}/cancel', [SalesOrderController::class, 'cancel'])->whereNumber('id');
    Route::post('sales-orders/{id}/invoice', [SalesOrderController::class, 'invoice'])->whereNumber('id');

    Route::post('sells/import', [SaleImportController::class, 'import']);
    Route::post('sells/{id}/receipt-share', [ReceiptShareController::class, 'store'])->whereNumber('id');
    Route::get('sells/{id}/receipt-shares', [ReceiptShareController::class, 'index'])->whereNumber('id');

    Route::get('store-credit', [StoreCreditController::class, 'balance']);
    Route::get('store-credits', [StoreCreditController::class, 'index']);
    Route::post('store-credit/issue', [StoreCreditController::class, 'issue']);
    Route::post('store-credit/refund', [StoreCreditController::class, 'refund']);
    Route::post('store-credit/apply', [StoreCreditController::class, 'apply']);

    Route::get('cheque-records', [ChequeRecordController::class, 'index']);
    Route::post('cheque-records', [ChequeRecordController::class, 'store']);
    Route::post('cheque-records/{id}/status', [ChequeRecordController::class, 'transition'])->whereNumber('id');

    Route::get('cashiers', [CashierController::class, 'index']);
    Route::get('cashiers/performance', [CashierController::class, 'performance']);
    Route::post('cashiers/verify-pin', [CashierController::class, 'verifyPin'])->middleware('throttle:30,1');
    Route::post('cashiers', [CashierController::class, 'store']);
    Route::put('cashiers/{id}', [CashierController::class, 'update'])->whereNumber('id');
    Route::delete('cashiers/{id}', [CashierController::class, 'destroy'])->whereNumber('id');

    Route::get('riders', [DispatchController::class, 'riders']);
    Route::post('riders', [DispatchController::class, 'storeRider']);
    Route::put('riders/{id}', [DispatchController::class, 'updateRider'])->whereNumber('id');
    Route::delete('riders/{id}', [DispatchController::class, 'destroyRider'])->whereNumber('id');
    Route::get('dispatch/board', [DispatchController::class, 'board']);
    Route::post('dispatch/assign', [DispatchController::class, 'assign']);
    Route::post('dispatch/{id}/move', [DispatchController::class, 'move'])->whereNumber('id');

    Route::get('document-center/meta', [DocumentCenterController::class, 'meta']);
    Route::get('document-templates', [DocumentCenterController::class, 'templates']);
    Route::post('document-templates', [DocumentCenterController::class, 'saveTemplate']);
    Route::post('document-templates/preview', [DocumentCenterController::class, 'preview']);
    Route::put('document-templates/{id}', [DocumentCenterController::class, 'saveTemplate'])->whereNumber('id');
    Route::post('document-templates/{id}/default', [DocumentCenterController::class, 'makeDefault'])->whereNumber('id');
    Route::delete('document-templates/{id}', [DocumentCenterController::class, 'deleteTemplate'])->whereNumber('id');
    Route::get('message-templates', [DocumentCenterController::class, 'messages']);
    Route::post('message-templates', [DocumentCenterController::class, 'saveMessage']);
    Route::put('message-templates/{id}', [DocumentCenterController::class, 'saveMessage'])->whereNumber('id');
    Route::delete('message-templates/{id}', [DocumentCenterController::class, 'deleteMessage'])->whereNumber('id');
    Route::get('document-reminders', [DocumentCenterController::class, 'reminders']);
    Route::post('document-reminders', [DocumentCenterController::class, 'saveReminder']);
    Route::post('document-reminders/run', [DocumentCenterController::class, 'runReminders']);
    Route::put('document-reminders/{id}', [DocumentCenterController::class, 'saveReminder'])->whereNumber('id');
    Route::delete('document-reminders/{id}', [DocumentCenterController::class, 'deleteReminder'])->whereNumber('id');
    Route::get('document-delivery', [DocumentCenterController::class, 'delivery']);
    Route::put('document-delivery', [DocumentCenterController::class, 'saveDelivery']);
    Route::get('custom-fields', [DocumentCenterController::class, 'fields']);
    Route::post('custom-fields', [DocumentCenterController::class, 'saveField']);
    Route::put('custom-fields/{id}', [DocumentCenterController::class, 'saveField'])->whereNumber('id');
    Route::delete('custom-fields/{id}', [DocumentCenterController::class, 'deleteField'])->whereNumber('id');
    Route::get('documents/{id}/render', [DocumentOutputController::class, 'render'])->whereNumber('id');
    Route::get('documents/{id}/export', [DocumentOutputController::class, 'export'])->whereNumber('id');
    Route::post('documents/{id}/send', [DocumentOutputController::class, 'send'])->whereNumber('id');
    Route::get('documents/{id}/attachments', [DocumentOutputController::class, 'attachments'])->whereNumber('id');
    Route::post('documents/{id}/attachments', [DocumentOutputController::class, 'attach'])->whereNumber('id');
    Route::get('documents/{id}/attachments/{attachmentId}', [DocumentOutputController::class, 'downloadAttachment'])->whereNumber('id')->whereNumber('attachmentId');
    Route::delete('documents/{id}/attachments/{attachmentId}', [DocumentOutputController::class, 'deleteAttachment'])->whereNumber('id')->whereNumber('attachmentId');
    Route::get('documents/{id}/custom-fields', [DocumentOutputController::class, 'customValues'])->whereNumber('id');
    Route::put('documents/{id}/custom-fields', [DocumentOutputController::class, 'setCustomValues'])->whereNumber('id');
    Route::get('business-settings', [BusinessSettingsController::class, 'show']);
    Route::put('business-settings', [BusinessSettingsController::class, 'save']);
    Route::put('business-settings/branches/{id}/parent', [BusinessSettingsController::class, 'moveBranch'])->whereNumber('id');
    Route::put('business-settings/notification-templates/{kind}', [BusinessSettingsController::class, 'saveNotificationTemplate']);
    Route::delete('business-settings/notification-templates/{kind}', [BusinessSettingsController::class, 'deleteNotificationTemplate']);
    Route::put('business-settings/retention', [BusinessSettingsController::class, 'saveRetention']);
    Route::delete('business-settings/retention/{entity}', [BusinessSettingsController::class, 'deleteRetention']);
    Route::put('business-settings/rate-feed', [BusinessSettingsController::class, 'saveRateFeed']);
    Route::post('business-settings/rate-feed/sync', [BusinessSettingsController::class, 'syncRates']);
    Route::get('setup-wizard', [BusinessSettingsController::class, 'wizard']);
    Route::put('setup-wizard/{key}', [BusinessSettingsController::class, 'skipStep']);
    Route::get('push/key', [BusinessSettingsController::class, 'pushKey']);
    Route::post('push/subscribe', [BusinessSettingsController::class, 'subscribe']);
    Route::post('push/unsubscribe', [BusinessSettingsController::class, 'unsubscribe']);
    Route::post('push/test', [BusinessSettingsController::class, 'pushTest']);
    Route::get('data-center/export/{entity}', [DataCenterController::class, 'export']);
    Route::get('data-center/template/{entity}', [DataCenterController::class, 'template']);
    Route::get('data-center/history', [DataCenterController::class, 'history']);
    Route::post('data-center/history/{id}/undo', [DataCenterController::class, 'undo'])->whereNumber('id');
    Route::post('data-center/migrate', [DataCenterController::class, 'migrate']);
    Route::get('custom-values/{entity}/{id}', [DataCenterController::class, 'customValues'])->whereNumber('id');
    Route::put('custom-values/{entity}/{id}', [DataCenterController::class, 'setCustomValues'])->whereNumber('id');
    Route::get('help-articles', [HelpCenterController::class, 'index']);
    Route::get('help-articles/{slug}', [HelpCenterController::class, 'show']);
    Route::get('franchise/overview', [HelpCenterController::class, 'franchise']);
    Route::get('system/logs', [SystemCenterController::class, 'logs']);
    Route::get('system/backup-remote', [SystemCenterController::class, 'backupRemote']);
    Route::put('system/backup-remote', [SystemCenterController::class, 'chooseBackupDisk']);
    Route::post('system/backups/{id}/copy', [SystemCenterController::class, 'copyBackup'])->whereNumber('id');
    Route::post('system/backups/{id}/restore', [SystemCenterController::class, 'restoreBackup'])->whereNumber('id');
    Route::get('system/push', [SystemCenterController::class, 'push']);
    Route::post('system/push/keys', [SystemCenterController::class, 'generatePushKeys']);
    Route::put('system/push/subject', [SystemCenterController::class, 'pushSubject']);
    Route::get('system/demo-companies', [SystemCenterController::class, 'demos']);
    Route::post('system/demo-companies', [SystemCenterController::class, 'createDemo']);
    Route::delete('system/demo-companies/{id}', [SystemCenterController::class, 'removeDemo'])->whereNumber('id');
    Route::get('system/franchise-groups', [SystemCenterController::class, 'groups']);
    Route::post('system/franchise-groups', [SystemCenterController::class, 'saveGroup']);
    Route::put('system/franchise-groups/{id}', [SystemCenterController::class, 'saveGroup'])->whereNumber('id');
    Route::delete('system/franchise-groups/{id}', [SystemCenterController::class, 'deleteGroup'])->whereNumber('id');
    Route::get('system/help-articles', [SystemCenterController::class, 'articles']);
    Route::post('system/help-articles', [SystemCenterController::class, 'saveArticle']);
    Route::put('system/help-articles/{id}', [SystemCenterController::class, 'saveArticle'])->whereNumber('id');
    Route::delete('system/help-articles/{id}', [SystemCenterController::class, 'deleteArticle'])->whereNumber('id');
    Route::get('system/retention', [SystemCenterController::class, 'retention']);
    Route::put('system/retention', [SystemCenterController::class, 'saveRetention']);
    Route::get('analytics-overview', [AnalyticsOverviewController::class, 'show']);
    Route::get('report-filters', [ReportFilterController::class, 'index']);
    Route::post('report-filters', [ReportFilterController::class, 'store']);
    Route::put('report-filters/{id}/favourite', [ReportFilterController::class, 'favourite'])->whereNumber('id');
    Route::delete('report-filters/{id}', [ReportFilterController::class, 'destroy'])->whereNumber('id');
    Route::get('report-builder/meta', [ReportBuilderController::class, 'meta']);
    Route::post('report-builder/preview', [ReportBuilderController::class, 'preview']);
    Route::get('report-builder', [ReportBuilderController::class, 'index']);
    Route::post('report-builder', [ReportBuilderController::class, 'store']);
    Route::put('report-builder/{id}', [ReportBuilderController::class, 'update'])->whereNumber('id');
    Route::delete('report-builder/{id}', [ReportBuilderController::class, 'destroy'])->whereNumber('id');
    Route::get('hrm/meta', [HrmController::class, 'meta']);
    Route::get('hrm/settings', [HrmController::class, 'settings']);
    Route::put('hrm/settings', [HrmController::class, 'saveSettings']);
    Route::get('hrm/employees', [HrmController::class, 'employees']);
    Route::post('hrm/employees', [HrmController::class, 'saveEmployee']);
    Route::get('hrm/employees/{id}', [HrmController::class, 'showEmployee'])->whereNumber('id');
    Route::put('hrm/employees/{id}', [HrmController::class, 'saveEmployee'])->whereNumber('id');
    Route::delete('hrm/employees/{id}', [HrmController::class, 'deleteEmployee'])->whereNumber('id');
    Route::post('hrm/employees/{id}/salary', [HrmController::class, 'saveSalary'])->whereNumber('id');
    Route::get('hrm/employees/{id}/documents', [HrmController::class, 'documents'])->whereNumber('id');
    Route::post('hrm/employees/{id}/documents', [HrmController::class, 'uploadDocument'])->whereNumber('id');
    Route::get('hrm/documents/expiring', [HrmController::class, 'expiring']);
    Route::get('hrm/documents/{id}/download', [HrmController::class, 'downloadDocument'])->whereNumber('id');
    Route::delete('hrm/documents/{id}', [HrmController::class, 'deleteDocument'])->whereNumber('id');
    Route::get('hrm/org-tree', [HrmController::class, 'orgTree']);
    Route::get('hrm/shifts', [HrmController::class, 'shifts']);
    Route::post('hrm/shifts', [HrmController::class, 'saveShift']);
    Route::put('hrm/shifts/{id}', [HrmController::class, 'saveShift'])->whereNumber('id');
    Route::delete('hrm/shifts/{id}', [HrmController::class, 'deleteShift'])->whereNumber('id');
    Route::get('hrm/roster', [HrmController::class, 'roster']);
    Route::put('hrm/roster', [HrmController::class, 'saveRoster']);
    Route::get('hrm/leave-types', [HrmController::class, 'leaveTypes']);
    Route::post('hrm/leave-types', [HrmController::class, 'saveLeaveType']);
    Route::put('hrm/leave-types/{id}', [HrmController::class, 'saveLeaveType'])->whereNumber('id');
    Route::get('hrm/components', [HrmController::class, 'components']);
    Route::post('hrm/components', [HrmController::class, 'saveComponent']);
    Route::put('hrm/components/{id}', [HrmController::class, 'saveComponent'])->whereNumber('id');
    Route::get('hrm/rates', [HrmController::class, 'rates']);
    Route::post('hrm/rates', [HrmController::class, 'saveRate']);
    Route::put('hrm/rates/{id}', [HrmController::class, 'saveRate'])->whereNumber('id');
    Route::delete('hrm/rates/{id}', [HrmController::class, 'deleteRate'])->whereNumber('id');
    Route::post('hrm/tax-slabs', [HrmController::class, 'saveSlab']);
    Route::put('hrm/tax-slabs/{id}', [HrmController::class, 'saveSlab'])->whereNumber('id');
    Route::delete('hrm/tax-slabs/{id}', [HrmController::class, 'deleteSlab'])->whereNumber('id');
    Route::get('hrm/attendance', [HrmAttendanceController::class, 'index']);
    Route::post('hrm/attendance', [HrmAttendanceController::class, 'record']);
    Route::post('hrm/attendance/import', [HrmAttendanceController::class, 'import']);
    Route::post('hrm/attendance/process-punches', [HrmAttendanceController::class, 'processPunches']);
    Route::get('hrm/devices', [HrmAttendanceController::class, 'devices']);
    Route::post('hrm/devices', [HrmAttendanceController::class, 'createDevice']);
    Route::delete('hrm/devices/{id}', [HrmAttendanceController::class, 'deleteDevice'])->whereNumber('id');
    Route::get('hrm/leave-requests', [HrmAttendanceController::class, 'leaveRequests']);
    Route::post('hrm/leave-requests', [HrmAttendanceController::class, 'requestLeave']);
    Route::get('hrm/leave-balance', [HrmAttendanceController::class, 'balance']);
    Route::post('hrm/leave-requests/{id}/cancel', [HrmAttendanceController::class, 'cancelLeave'])->whereNumber('id');
    Route::post('leave-requests/{id}/approve', [HrmAttendanceController::class, 'approveLeave'])->whereNumber('id');
    Route::post('leave-requests/{id}/reject', [HrmAttendanceController::class, 'rejectLeave'])->whereNumber('id');
    Route::get('hrm/bonuses', [HrmPayrollController::class, 'bonuses']);
    Route::post('hrm/bonuses', [HrmPayrollController::class, 'saveBonus']);
    Route::delete('hrm/bonuses/{id}', [HrmPayrollController::class, 'deleteBonus'])->whereNumber('id');
    Route::get('hrm/loans', [HrmPayrollController::class, 'loans']);
    Route::post('hrm/loans', [HrmPayrollController::class, 'saveLoan']);
    Route::post('hrm/loans/{id}/close', [HrmPayrollController::class, 'closeLoan'])->whereNumber('id');
    Route::get('hrm/payroll', [HrmPayrollController::class, 'runs']);
    Route::get('hrm/payroll/preview', [HrmPayrollController::class, 'preview']);
    Route::post('hrm/payroll', [HrmPayrollController::class, 'createRun']);
    Route::get('hrm/payroll/{id}', [HrmPayrollController::class, 'showRun'])->whereNumber('id');
    Route::post('hrm/payroll/{id}/recalculate', [HrmPayrollController::class, 'recalculate'])->whereNumber('id');
    Route::post('hrm/payroll/{id}/cancel', [HrmPayrollController::class, 'cancelRun'])->whereNumber('id');
    Route::post('payroll-runs/{id}/approve', [HrmPayrollController::class, 'approveRun'])->whereNumber('id');
    Route::post('hrm/payroll/{id}/post', [HrmPayrollController::class, 'postRun'])->whereNumber('id');
    Route::get('hrm/payroll/{id}/bank-file', [HrmPayrollController::class, 'bankFile'])->whereNumber('id');
    Route::get('hrm/payslips/{lineId}', [HrmPayrollController::class, 'payslip'])->whereNumber('lineId');
    Route::post('hrm/payslips/{lineId}/email', [HrmPayrollController::class, 'emailPayslip'])->whereNumber('lineId');
    Route::get('manufacturing/products', [ManufacturingController::class, 'products']);
    Route::get('manufacturing/settings', [ManufacturingController::class, 'settings']);
    Route::put('manufacturing/settings', [ManufacturingController::class, 'saveSettings']);
    Route::get('manufacturing/boms', [ManufacturingController::class, 'boms']);
    Route::post('manufacturing/boms', [ManufacturingController::class, 'saveBom']);
    Route::get('manufacturing/boms/{id}', [ManufacturingController::class, 'showBom'])->whereNumber('id');
    Route::put('manufacturing/boms/{id}', [ManufacturingController::class, 'saveBom'])->whereNumber('id');
    Route::delete('manufacturing/boms/{id}', [ManufacturingController::class, 'deleteBom'])->whereNumber('id');
    Route::post('manufacturing/boms/{id}/new-version', [ManufacturingController::class, 'newBomVersion'])->whereNumber('id');
    Route::post('manufacturing/boms/{id}/archive', [ManufacturingController::class, 'archiveBom'])->whereNumber('id');
    Route::post('boms/{id}/approve', [ManufacturingController::class, 'activateBom'])->whereNumber('id');
    Route::get('manufacturing/plans', [ManufacturingController::class, 'plans']);
    Route::post('manufacturing/plans', [ManufacturingController::class, 'savePlan']);
    Route::delete('manufacturing/plans/{id}', [ManufacturingController::class, 'deletePlan'])->whereNumber('id');
    Route::get('manufacturing/work-orders', [ManufacturingController::class, 'workOrders']);
    Route::post('manufacturing/work-orders', [ManufacturingController::class, 'createWorkOrder']);
    Route::get('manufacturing/work-orders/{id}', [ManufacturingController::class, 'showWorkOrder'])->whereNumber('id');
    Route::post('work-orders/{id}/approve', [ManufacturingController::class, 'releaseWorkOrder'])->whereNumber('id');
    Route::post('manufacturing/work-orders/{id}/cancel', [ManufacturingController::class, 'cancelWorkOrder'])->whereNumber('id');
    Route::post('manufacturing/work-orders/{id}/issue', [ManufacturingController::class, 'issue'])->whereNumber('id');
    Route::post('manufacturing/work-orders/{id}/labor', [ManufacturingController::class, 'labor'])->whereNumber('id');
    Route::post('manufacturing/work-orders/{id}/standard-labor', [ManufacturingController::class, 'standardLabor'])->whereNumber('id');
    Route::post('manufacturing/work-orders/{id}/scrap', [ManufacturingController::class, 'scrap'])->whereNumber('id');
    Route::post('manufacturing/work-orders/{id}/checks', [ManufacturingController::class, 'check'])->whereNumber('id');
    Route::post('manufacturing/work-orders/{id}/receive', [ManufacturingController::class, 'receive'])->whereNumber('id');
    Route::post('manufacturing/work-orders/{id}/close', [ManufacturingController::class, 'close'])->whereNumber('id');
    Route::get('service/meta', [ServiceJobController::class, 'meta']);
    Route::get('service/jobs', [ServiceJobController::class, 'index']);
    Route::get('service/my-jobs', [ServiceJobController::class, 'myJobs']);
    Route::post('service/jobs', [ServiceJobController::class, 'store']);
    Route::get('service/jobs/{id}', [ServiceJobController::class, 'show'])->whereNumber('id');
    Route::put('service/jobs/{id}', [ServiceJobController::class, 'update'])->whereNumber('id');
    Route::post('service/jobs/{id}/assign', [ServiceJobController::class, 'assign'])->whereNumber('id');
    Route::post('service/jobs/{id}/status', [ServiceJobController::class, 'status'])->whereNumber('id');
    Route::post('service/jobs/{id}/parts', [ServiceJobController::class, 'addPart'])->whereNumber('id');
    Route::delete('service/jobs/{id}/parts/{partId}', [ServiceJobController::class, 'removePart'])->whereNumber('id')->whereNumber('partId');
    Route::post('service/jobs/{id}/invoice', [ServiceJobController::class, 'invoice'])->whereNumber('id');
    Route::post('service/jobs/{id}/signature', [ServiceJobController::class, 'signature'])->whereNumber('id');
    Route::get('service/jobs/{id}/report', [ServiceJobController::class, 'report'])->whereNumber('id');
    Route::get('project-work/{projectId}', [ProjectWorkController::class, 'show'])->whereNumber('projectId');
    Route::post('project-work/{projectId}/milestones', [ProjectWorkController::class, 'saveMilestone'])->whereNumber('projectId');
    Route::put('project-work/{projectId}/milestones/{id}', [ProjectWorkController::class, 'saveMilestone'])->whereNumber('projectId')->whereNumber('id');
    Route::post('project-work/{projectId}/milestones/{id}/status', [ProjectWorkController::class, 'milestoneStatus'])->whereNumber('projectId')->whereNumber('id');
    Route::delete('project-work/{projectId}/milestones/{id}', [ProjectWorkController::class, 'deleteMilestone'])->whereNumber('projectId')->whereNumber('id');
    Route::post('project-work/{projectId}/tasks', [ProjectWorkController::class, 'saveTask'])->whereNumber('projectId');
    Route::put('project-work/{projectId}/tasks/{id}', [ProjectWorkController::class, 'saveTask'])->whereNumber('projectId')->whereNumber('id');
    Route::post('project-work/{projectId}/tasks/{id}/status', [ProjectWorkController::class, 'taskStatus'])->whereNumber('projectId')->whereNumber('id');
    Route::delete('project-work/{projectId}/tasks/{id}', [ProjectWorkController::class, 'deleteTask'])->whereNumber('projectId')->whereNumber('id');
    Route::get('expense-claims', [ExpenseClaimController::class, 'index']);
    Route::post('expense-claims', [ExpenseClaimController::class, 'store']);
    Route::get('expense-claims/{id}', [ExpenseClaimController::class, 'show'])->whereNumber('id');
    Route::put('expense-claims/{id}', [ExpenseClaimController::class, 'update'])->whereNumber('id');
    Route::post('expense-claims/{id}/submit', [ExpenseClaimController::class, 'submit'])->whereNumber('id');
    Route::post('expense-claims/{id}/cancel', [ExpenseClaimController::class, 'cancel'])->whereNumber('id');
    Route::post('expense-claims/{id}/approve', [ExpenseClaimController::class, 'approve'])->whereNumber('id');
    Route::post('expense-claims/{id}/reject', [ExpenseClaimController::class, 'reject'])->whereNumber('id');
    Route::post('expense-claims/{id}/pay', [ExpenseClaimController::class, 'pay'])->whereNumber('id');
    Route::post('expense-claims/{id}/receipts', [ExpenseClaimController::class, 'uploadReceipt'])->whereNumber('id');
    Route::get('expense-claims/{id}/receipts/{receiptId}', [ExpenseClaimController::class, 'downloadReceipt'])->whereNumber('id')->whereNumber('receiptId');
    Route::get('storefront-admin/settings', [StorefrontController::class, 'settings']);
    Route::put('storefront-admin/settings', [StorefrontController::class, 'saveSettings']);
    Route::get('storefront-admin/products', [StorefrontController::class, 'products']);
    Route::post('storefront-admin/products', [StorefrontController::class, 'saveProduct']);
    Route::get('storefront-admin/orders', [StorefrontController::class, 'orders']);
    Route::post('storefront-admin/orders/{id}/convert', [StorefrontController::class, 'convert'])->whereNumber('id');
    Route::post('storefront-admin/orders/{id}/reject', [StorefrontController::class, 'rejectOrder'])->whereNumber('id');
    Route::get('rental/settings', [RentalController::class, 'settings']);
    Route::put('rental/settings', [RentalController::class, 'saveSettings']);
    Route::get('rental/items', [RentalController::class, 'items']);
    Route::post('rental/items', [RentalController::class, 'saveItem']);
    Route::put('rental/items/{id}', [RentalController::class, 'saveItem'])->whereNumber('id');
    Route::get('rental/calendar', [RentalController::class, 'calendar']);
    Route::get('rental/availability', [RentalController::class, 'availability']);
    Route::get('rental/bookings', [RentalController::class, 'bookings']);
    Route::post('rental/bookings', [RentalController::class, 'book']);
    Route::get('rental/bookings/{id}', [RentalController::class, 'showBooking'])->whereNumber('id');
    Route::post('rental/bookings/{id}/cancel', [RentalController::class, 'cancel'])->whereNumber('id');
    Route::post('rental/bookings/{id}/pickup', [RentalController::class, 'pickup'])->whereNumber('id');
    Route::post('rental/bookings/{id}/return', [RentalController::class, 'return'])->whereNumber('id');
    Route::get('report-builder/{id}/run', [ReportBuilderController::class, 'run'])->whereNumber('id');
    Route::get('report-schedules', [ReportScheduleController::class, 'index']);
    Route::post('report-schedules', [ReportScheduleController::class, 'store']);
    Route::put('report-schedules/{id}', [ReportScheduleController::class, 'toggle'])->whereNumber('id');
    Route::delete('report-schedules/{id}', [ReportScheduleController::class, 'destroy'])->whereNumber('id');
    Route::post('report-schedules/{id}/run', [ReportScheduleController::class, 'runNow'])->whereNumber('id');
    Route::get('report-schedules/{id}/runs', [ReportScheduleController::class, 'runs'])->whereNumber('id');
    Route::get('sales-kpi/statement', [SalesKpiController::class, 'statement']);
    Route::get('sales-kpi/targets', [SalesKpiController::class, 'targets']);
    Route::post('sales-kpi/targets', [SalesKpiController::class, 'setTarget']);
    Route::get('sales-kpi/tiers', [SalesKpiController::class, 'tiers']);
    Route::post('sales-kpi/tiers', [SalesKpiController::class, 'storeTier']);
    Route::delete('sales-kpi/tiers/{id}', [SalesKpiController::class, 'destroyTier'])->whereNumber('id');

    Route::get('sales-routes', [SalesRouteController::class, 'index']);
    Route::get('sales-routes/sheet', [SalesRouteController::class, 'sheet']);
    Route::post('sales-routes', [SalesRouteController::class, 'store']);
    Route::put('sales-routes/{id}', [SalesRouteController::class, 'update'])->whereNumber('id');
    Route::delete('sales-routes/{id}', [SalesRouteController::class, 'destroy'])->whereNumber('id');
    Route::post('sales-routes/{id}/visit', [SalesRouteController::class, 'visit'])->whereNumber('id');

    Route::get('recurring-invoices', [RecurringInvoiceController::class, 'index']);
    Route::post('recurring-invoices', [RecurringInvoiceController::class, 'store']);
    Route::post('recurring-invoices/generate', [RecurringInvoiceController::class, 'generate']);
    Route::get('recurring-invoices/{id}', [RecurringInvoiceController::class, 'show'])->whereNumber('id');
    Route::put('recurring-invoices/{id}', [RecurringInvoiceController::class, 'update'])->whereNumber('id');
    Route::delete('recurring-invoices/{id}', [RecurringInvoiceController::class, 'destroy'])->whereNumber('id');

    Route::get('installment-plans', [InstallmentPlanController::class, 'index']);
    Route::post('installment-plans', [InstallmentPlanController::class, 'store']);
    Route::get('installment-plans/{id}', [InstallmentPlanController::class, 'show'])->whereNumber('id');
    Route::post('installment-plans/{id}/pay', [InstallmentPlanController::class, 'pay'])->whereNumber('id');
    Route::post('installment-plans/{id}/cancel', [InstallmentPlanController::class, 'cancel'])->whereNumber('id');

    Route::get('ecommerce-connections', [EcommerceConnectionController::class, 'index']);
    Route::post('ecommerce-connections', [EcommerceConnectionController::class, 'store']);
    Route::put('ecommerce-connections/{id}', [EcommerceConnectionController::class, 'update'])->whereNumber('id');
    Route::post('ecommerce-connections/{id}/sync', [EcommerceConnectionController::class, 'sync'])->whereNumber('id');
    Route::get('ecommerce-connections/{id}/orders', [EcommerceConnectionController::class, 'orders'])->whereNumber('id');
    Route::post('ecommerce-connections/{id}/orders/{orderId}/retry', [EcommerceConnectionController::class, 'retry'])->whereNumber('id')->whereNumber('orderId');

    Route::get('messaging-settings', [MessagingSettingController::class, 'index']);
    Route::put('messaging-settings/{channel}', [MessagingSettingController::class, 'update']);

    Route::get('opening-stock', [OpeningStockController::class, 'index']);
    Route::post('opening-stock', [OpeningStockController::class, 'store']);
    /* Product */

    /* Variation */
    Route::post('/variations/import', [VariationController::class, 'import']);
    Route::get('variations/trash', [VariationController::class, 'trash']);
    Route::resource('variations', VariationController::class);
    Route::get('/fetchvariations', [VariationController::class, 'fetch']);
    Route::post('/variations/statusupdate', [VariationController::class, 'updatestatus']);
    Route::post('/variations/duplicate', [VariationController::class, 'duplicate']);
    Route::post('/variations/bulk_delete', [VariationController::class, 'bulk_delete']);
    Route::post('variations/bulk_delete_per', [VariationController::class, 'bulk_delete_per']);
    Route::post('variations/restore_records', [VariationController::class, 'restore_records']);
    /* Variation */

    /* Unit */
    Route::post('/units/import', [UnitController::class, 'import']);
    Route::post('units/check-name', [UnitController::class, 'checkName']);
    Route::get('units/trash', [UnitController::class, 'trash']);
    Route::resource('units', UnitController::class);
    Route::get('/fetchunits', [UnitController::class, 'fetch']);
    Route::post('/units/statusupdate', [UnitController::class, 'updatestatus']);
    Route::post('/units/duplicate', [UnitController::class, 'duplicate']);
    Route::post('/units/bulk_delete', [UnitController::class, 'bulk_delete']);
    Route::post('units/bulk_delete_per', [UnitController::class, 'bulk_delete_per']);
    Route::post('units/restore_records', [UnitController::class, 'restore_records']);
    /* Unit */

    /* User */
    Route::post('users/check-identity', [UserController::class, 'checkIdentity']);
    Route::get('users/trash', [UserController::class, 'trash']);
    Route::resource('users', UserController::class);
    Route::get('/fetchusers', [UserController::class, 'fetchusers']);
    Route::post('/users/statusupdate', [UserController::class, 'updatestatus']);
    Route::post('/users/import', [UserController::class, 'import']);
    Route::post('/users/duplicate', [UserController::class, 'duplicate']);
    Route::post('/users/bulk_delete', [UserController::class, 'bulk_delete']);
    Route::post('users/bulk_delete_per', [UserController::class, 'bulk_delete_per']);
    Route::post('users/restore_records', [UserController::class, 'restore_records']);
    Route::post('users/{id}/send-credentials', [UserController::class, 'sendCredentials']);
    /* User */
});

/*
 * Public REST API (Integrations & Open API) — a separate, versioned surface for external consumers,
 * unlike the SPA-only API above (which has no version prefix because the SPA and its backend always
 * ship together). Authenticated with the same Sanctum personal-access-token system as "Settings > API
 * Keys" (ApiKeyController) — a key acts as its owner, so every request here is scoped to that owner's
 * company exactly like the rest of the app. Read-only for this build: Products, Customers, Invoices.
 */
Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:public-api', LogPublicApiRequest::class])->group(function () {
    Route::get('products', [PublicApiProductController::class, 'index']);
    Route::get('products/{id}', [PublicApiProductController::class, 'show']);
    Route::get('customers', [PublicApiCustomerController::class, 'index']);
    Route::get('customers/{id}', [PublicApiCustomerController::class, 'show']);
    Route::get('invoices', [PublicApiInvoiceController::class, 'index']);
    Route::get('invoices/{id}', [PublicApiInvoiceController::class, 'show']);
});
