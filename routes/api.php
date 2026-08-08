<?php

use App\Http\Controllers\MenuController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\RoleController;
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
    Route::post('/menus/duplicate', [MenuController::class, 'duplicate']);
    Route::post('/menus/bulk_delete', [MenuController::class, 'bulk_delete']);
    Route::post('menus/bulk_delete_per', [MenuController::class, 'bulk_delete_per']);
    Route::post('menus/restore_records', [MenuController::class, 'restore_records']);
    Route::get('getpermissions', [MenuController::class, 'getpermission']);
    /* Menu */

    /* Role */
    Route::get('roles/trash', [RoleController::class, 'trash']);
    Route::resource('roles', RoleController::class);
    Route::get('/fetchroles', [RoleController::class, 'fetchroles']);
    Route::post('/roles/statusupdate', [RoleController::class, 'updatestatus']);
    Route::post('/roles/duplicate', [RoleController::class, 'duplicate']);
    Route::post('/roles/bulk_delete', [RoleController::class, 'bulk_delete']);
    Route::post('roles/bulk_delete_per', [RoleController::class, 'bulk_delete_per']);
    Route::post('roles/restore_records', [RoleController::class, 'restore_records']);
    /* Role */

    /* Permission */
    Route::resource('permissions', PermissionController::class);
    Route::get('fetchpermissions', [PermissionController::class, 'fetch']);
    Route::post('permissions/statusupdate', [PermissionController::class, 'updatestatus']);
    /* Permission */

    /* User */
    Route::get('users/trash', [UserController::class, 'trash']);
    Route::resource('users', UserController::class);
    Route::get('/fetchusers', [UserController::class, 'fetchusers']);
    Route::post('/users/statusupdate', [UserController::class, 'updatestatus']);
    Route::post('/users/duplicate', [UserController::class, 'duplicate']);
    Route::post('/users/bulk_delete', [UserController::class, 'bulk_delete']);
    Route::post('users/bulk_delete_per', [UserController::class, 'bulk_delete_per']);
    Route::post('users/restore_records', [UserController::class, 'restore_records']);
    /* User */
});
