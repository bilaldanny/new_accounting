<?php

use App\Http\Controllers\AccountBalanceController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ChartOfAccountController;
use App\Http\Controllers\CityController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanySettingController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerGroupController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\FinancialYearController;
use App\Http\Controllers\IssueNoteController;
use App\Http\Controllers\ItemTypeController;
use App\Http\Controllers\JournalEntryController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PurchaseApprovalController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\PurchaseReturnController;
use App\Http\Controllers\ReceivingNoteController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SellApprovalController;
use App\Http\Controllers\SellController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StateController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\TaxController;
use App\Http\Controllers\TimezoneController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VariationController;
use App\Http\Controllers\WarrantyController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/* Extras */
Route::post('fetchcountries', [CountryController::class, 'fetch']);
Route::post('fetchstates', [StateController::class, 'fetch']);
Route::post('fetchcities', [CityController::class, 'fetch']);
Route::get('fetchcurrencies', [CurrencyController::class, 'fetch']);
Route::get('fetchtimezones', [TimezoneController::class, 'fetch']);

Route::middleware(['auth:sanctum'])->group(function () {
    /* Menu */
    Route::get('menus/trash', [MenuController::class, 'trash']);
    Route::resource('menus', MenuController::class);
    Route::get('/fetchmenus', [MenuController::class, 'fetchmenus']);
    Route::get('/fetchpermenus', [MenuController::class, 'fetchpermenus']);
    Route::post('/menus/statusupdate', [MenuController::class, 'updatestatus']);
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
    Route::resource('taxes', TaxController::class);
    Route::get('fetchobaccounts', [ChartOfAccountController::class, 'fetchObAccounts']);
    Route::get('account-balances/fetch-balance', [AccountBalanceController::class, 'fetchBalance']);
    Route::post('account-balances', [AccountBalanceController::class, 'store']);
    Route::get('fetchfinancialyears', [FinancialYearController::class, 'fetch']);
    Route::resource('financialyears', FinancialYearController::class);
    /* Company */

    /* Permission */
    Route::resource('permissions', PermissionController::class);
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

    /* Supplier */
    Route::get('suppliers/trash', [SupplierController::class, 'trash']);
    Route::get('suppliers/generate-code', [SupplierController::class, 'generateCode']);
    Route::resource('suppliers', SupplierController::class);
    Route::post('/suppliers/statusupdate', [SupplierController::class, 'updatestatus']);
    Route::post('/suppliers/duplicate', [SupplierController::class, 'duplicate']);
    Route::post('/suppliers/{id}/link-coa', [SupplierController::class, 'linkCoa']);
    Route::post('/suppliers/bulk_delete', [SupplierController::class, 'bulk_delete']);
    Route::post('suppliers/bulk_delete_per', [SupplierController::class, 'bulk_delete_per']);
    Route::post('suppliers/restore_records', [SupplierController::class, 'restore_records']);
    Route::get('/fetchsuppliers', [SupplierController::class, 'fetch']);
    Route::get('/fetchcontactdetail', [SupplierController::class, 'contactDetail']);
    Route::get('/fetchledger', [ReportController::class, 'fetchLedger']);
    Route::post('/contact-ledger-watches', [ReportController::class, 'saveLedgerWatch']);
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
    /* Journal Entry */

    Route::get('purchases/search-products', [PurchaseController::class, 'searchProducts']);
    Route::get('purchases/trash', [PurchaseController::class, 'trash']);
    Route::resource('purchases', PurchaseController::class);
    Route::post('/purchases/statusupdate', [PurchaseController::class, 'updatestatus']);
    Route::post('/purchases/duplicate', [PurchaseController::class, 'duplicate']);
    Route::post('/purchases/bulk_delete', [PurchaseController::class, 'bulk_delete']);
    Route::post('purchases/bulk_delete_per', [PurchaseController::class, 'bulk_delete_per']);
    Route::post('purchases/restore_records', [PurchaseController::class, 'restore_records']);
    /* Purchase */

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
    Route::resource('sells', SellController::class);
    Route::post('/sells/statusupdate', [SellController::class, 'updatestatus']);
    Route::post('/sells/duplicate', [SellController::class, 'duplicate']);
    Route::post('/sells/bulk_delete', [SellController::class, 'bulk_delete']);
    Route::post('sells/bulk_delete_per', [SellController::class, 'bulk_delete_per']);
    Route::post('sells/restore_records', [SellController::class, 'restore_records']);
    /* Sell */

    /* Sell Approval */
    Route::get('sell-approvals', [SellApprovalController::class, 'index']);
    Route::get('sell-approvals/{id}', [SellApprovalController::class, 'show']);
    Route::post('sell-approvals/{id}/approve', [SellApprovalController::class, 'approve']);
    /* Sell Approval */

    /* Issue Note */
    Route::get('issue-notes/eligible-sells', [IssueNoteController::class, 'eligibleSells']);
    Route::get('issue-notes/sell/{id}', [IssueNoteController::class, 'sellLines']);
    Route::get('issue-notes/trash', [IssueNoteController::class, 'trash']);
    Route::resource('issue-notes', IssueNoteController::class);
    Route::post('issue-notes/bulk_delete', [IssueNoteController::class, 'bulk_delete']);
    Route::post('issue-notes/bulk_delete_per', [IssueNoteController::class, 'bulk_delete_per']);
    Route::post('issue-notes/restore_records', [IssueNoteController::class, 'restore_records']);
    /* Issue Note */

    /* Product */
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
