<?php

use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;
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
});

Route::middleware(['auth'])->prefix('api')->group(function () {
    Route::get('notifications/counts-by-type', fn () => response()->json([
        'counts_by_type' => [],
    ]));
});

require __DIR__.'/settings.php';

Route::group(['prefix' => 'laravel-filemanager', 'middleware' => ['web', 'auth']], function () {
    Lfm::routes();
});
