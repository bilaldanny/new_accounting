<?php

use App\Http\Controllers\BranchController;
use App\Http\Controllers\CityController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\StateController;
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
    /* Company */

    /* Permission */
    Route::resource('permissions', PermissionController::class);
    Route::get('fetchpermissions', [PermissionController::class, 'fetch']);
    Route::post('permissions/statusupdate', [PermissionController::class, 'updatestatus']);
    /* Permission */

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
