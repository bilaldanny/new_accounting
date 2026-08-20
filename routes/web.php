<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\SettingController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use UniSharp\LaravelFilemanager\Lfm;

/* Setting Cookie */
Route::post('set_cookie', function (Request $request) {
    Cookie::queue('PreviousURL', env('APP_URL').$request->myurl, 10);
});
/* Setting Cookie */

/* Clear Cache Route */
Route::get('/artisan/{cmd}', function ($cmd) {
    switch ($cmd) {
        case 'clear':
            $exitCode = Artisan::call('config:clear');
            $exitCode = Artisan::call('cache:clear');
            $exitCode = Artisan::call('route:clear');
            $exitCode = Artisan::call('view:clear');
            $exitCode = Artisan::call('optimize:clear');
            break;

        case 'cached':
            $exitCode = Artisan::call('config:cache');
            break;

        default:
            abort(404);
            break;
    }

    return $exitCode;
});
/* Clear Cache Route */

/* Checking Session Timeout */
Route::prefix('idle-timeout-alert')->middleware('auth')->group(function () {

    Route::get('check', [HomeController::class, 'check']);

    Route::post('ping', function (Request $request) {
        $request->session()->put('idle-timeout-alert.last_ping', now());

        return response()->json(config('session.lifetime') * 60);
    });
});
/* Checking Session Timeout */

/* Check Smtp Connection */
Route::post('checkSMTP', [HomeController::class, 'check_smtp'])->name('checkSMTP');
/* Check Smtp Connection */

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    /* Menu */
    Route::get('menu', function () {
        return Inertia::render('menu/index');
    })->name('menu');

    Route::get('menu/trash', function () {
        return Inertia::render('menu/trash');
    })->name('menu.trash');
    /* Menu */

    /* Role */
    Route::get('role', function () {
        return Inertia::render('role/index');
    })->name('role');

    Route::get('role/trash', function () {
        return Inertia::render('role/trash');
    })->name('role.trash');

    Route::get('/role/{id}/permission', function ($id) {
        return Inertia::render('role/permission', ['id' => $id]);
    })->name('role.permission');
    /* Role */

    /* User */
    Route::get('user', function () {
        return Inertia::render('user/index');
    })->name('user');

    Route::get('user/trash', function () {
        return Inertia::render('user/trash');
    })->name('user.trash');
    /* User */

    /* Company */
    Route::get('company', function () {
        return Inertia::render('company/index');
    })->name('company');

    Route::get('company/trash', function () {
        return Inertia::render('company/trash');
    })->name('company.trash');

    Route::get('company/setting', function () {
        return Inertia::render('company/setting');
    })->name('company.setting');

    Route::get('business/settings', [SettingController::class, 'index'])->name('business.settings');
    /* Company */

    /* Branch */
    Route::get('branch', function () {
        return Inertia::render('branch/index');
    })->name('branch');

    Route::get('branch/trash', function () {
        return Inertia::render('branch/trash');
    })->name('branch.trash');
    /* Branch */

    /* Department */
    Route::get('department', function () {
        return Inertia::render('department/index');
    })->name('department');

    Route::get('department/trash', function () {
        return Inertia::render('department/trash');
    })->name('department.trash');
    /* Department */

    /* Supplier */
    Route::get('supplier', function () {
        return Inertia::render('supplier/index');
    })->name('supplier');

    Route::get('supplier/trash', function () {
        return Inertia::render('supplier/trash');
    })->name('supplier.trash');

    Route::get('supplier/{id}/view', function ($id) {
        return Inertia::render('supplier/view', ['id' => $id]);
    })->name('supplier.view');
    /* Supplier */

    /* Bank */
    Route::get('bank', function () {
        return Inertia::render('bank/index');
    })->name('bank');

    Route::get('bank/trash', function () {
        return Inertia::render('bank/trash');
    })->name('bank.trash');

    Route::get('bank/{id}/view', function ($id) {
        return Inertia::render('bank/view', ['id' => $id]);
    })->name('bank.view');
    /* Bank */

    /* Chart Of Account */
    Route::get('chart-of-account', function () {
        return Inertia::render('chart-of-account/index');
    })->name('chart-of-account');
    /* Chart Of Account */

    /* Opening Balance */
    Route::get('opening-balance', function () {
        return Inertia::render('opening-balance/index');
    })->name('opening-balance');
    /* Opening Balance */

    /* Customer Group */
    Route::get('customer-group', function () {
        return Inertia::render('customer-group/index');
    })->name('customer-group');

    Route::get('customer-group/trash', function () {
        return Inertia::render('customer-group/trash');
    })->name('customer-group.trash');
    /* Customer Group */

    /* Customer */
    Route::get('customer', function () {
        return Inertia::render('customer/index');
    })->name('customer');

    Route::get('customer/trash', function () {
        return Inertia::render('customer/trash');
    })->name('customer.trash');

    Route::get('customer/{id}/view', function ($id) {
        return Inertia::render('customer/view', ['id' => $id]);
    })->name('customer.view');
    /* Customer */

    /* Currency */
    Route::get('currency', function () {
        return Inertia::render('currency/index');
    })->name('currency');

    Route::get('currency/trash', function () {
        return Inertia::render('currency/trash');
    })->name('currency.trash');
    /* Currency */

    /* Timezone */
    Route::get('timezone', function () {
        return Inertia::render('timezone/index');
    })->name('timezone');

    Route::get('timezone/trash', function () {
        return Inertia::render('timezone/trash');
    })->name('timezone.trash');
    /* Timezone */

    /* Setting */
    Route::get('setting', [SettingController::class, 'index'])->name('setting');
    Route::get('email_template', [SettingController::class, 'email_template'])->name('email_template');
    Route::post('email_setting/test-send', [SettingController::class, 'email_test_send'])->name('email_setting.test_send');
    /* Setting */
});

Route::middleware(['auth'])->prefix('api')->group(function () {
    Route::get('notifications/counts-by-type', fn () => response()->json([
        'counts_by_type' => [],
    ]));
});

Route::get('/403', function () {
    return Inertia::render('errors/403');
})->name('403');

require __DIR__.'/settings.php';

Route::group(['prefix' => 'laravel-filemanager', 'middleware' => ['web', 'auth']], function () {
    Lfm::routes();
});
