<?php

use App\Http\Controllers\BranchController;
use App\Http\Controllers\ChartOfAccountController;
use App\Http\Controllers\CityController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CompanySettingController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\FinancialYearController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\StateController;
use App\Http\Controllers\TaxController;
use App\Http\Controllers\TimezoneController;
use App\Http\Controllers\UserController;
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
    Route::get('company-settings/{companyId}', [CompanySettingController::class, 'show']);
    Route::put('company-settings/{companyId}', [CompanySettingController::class, 'update']);
    Route::get('fetchparentaccounts', [ChartOfAccountController::class, 'fetchParentAccounts']);
    Route::get('fetchchildaccounts', [ChartOfAccountController::class, 'fetchChildAccounts']);
    Route::get('fetchallaccounts', [ChartOfAccountController::class, 'fetchAllAccounts']);
    Route::get('fetchparentsaleaccounts', [ChartOfAccountController::class, 'fetchParentSaleAccounts']);
    Route::get('fetchparentpurchaseaccounts', [ChartOfAccountController::class, 'fetchParentPurchaseAccounts']);
    Route::get('fetchcustomers', [ContactController::class, 'fetchCustomers']);
    Route::post('taxes/statusupdate', [TaxController::class, 'updateStatus']);
    Route::post('taxes/bulk_delete', [TaxController::class, 'bulk_delete']);
    Route::get('fetchtaxes', [TaxController::class, 'fetch']);
    Route::resource('taxes', TaxController::class);
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
    Route::get('currencies/trash', [CurrencyController::class, 'trash']);
    Route::resource('currencies', CurrencyController::class);
    Route::post('/currencies/statusupdate', [CurrencyController::class, 'updatestatus']);
    Route::post('/currencies/bulk_delete', [CurrencyController::class, 'bulk_delete']);
    Route::post('currencies/bulk_delete_per', [CurrencyController::class, 'bulk_delete_per']);
    Route::post('currencies/restore_records', [CurrencyController::class, 'restore_records']);
    /* Currency */

    /* Timezone */
    Route::post('timezones/check-name', [TimezoneController::class, 'checkName']);
    Route::get('timezones/trash', [TimezoneController::class, 'trash']);
    Route::resource('timezones', TimezoneController::class);
    Route::post('/timezones/bulk_delete', [TimezoneController::class, 'bulk_delete']);
    Route::post('timezones/bulk_delete_per', [TimezoneController::class, 'bulk_delete_per']);
    Route::post('timezones/restore_records', [TimezoneController::class, 'restore_records']);
    /* Timezone */

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
    /* User */
});
