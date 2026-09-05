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
    if (! request()->user()?->hasRole('superadmin')) {
        abort(403);
    }

    $exitCode = 0;

    switch ($cmd) {
        case 'clear':
            Artisan::call('config:clear');
            Artisan::call('cache:clear');
            Artisan::call('route:clear');
            Artisan::call('view:clear');
            $exitCode = Artisan::call('optimize:clear');
            break;

        case 'cached':
            $exitCode = Artisan::call('config:cache');
            break;

        default:
            abort(404);
    }

    return $exitCode;
})->middleware('auth');
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
Route::post('checkSMTP', [HomeController::class, 'check_smtp'])->middleware('auth')->name('checkSMTP');
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

    Route::get('business/settings', function () {
        return Inertia::render('company/setting');
    })->name('business.settings');

    Route::get('software/setting', [SettingController::class, 'index'])->name('software.setting');
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
        return Inertia::render('contact/supplier/index');
    })->name('supplier');

    Route::get('supplier/trash', function () {
        return Inertia::render('contact/supplier/trash');
    })->name('supplier.trash');

    Route::get('supplier/{id}/view', function ($id) {
        return Inertia::render('contact/supplier/view', ['id' => $id]);
    })->name('supplier.view');
    /* Supplier */

    /* Bank */
    Route::get('bank', function () {
        return Inertia::render('contact/bank/index');
    })->name('bank');

    Route::get('bank/trash', function () {
        return Inertia::render('contact/bank/trash');
    })->name('bank.trash');

    Route::get('bank/{id}/view', function ($id) {
        return Inertia::render('contact/bank/view', ['id' => $id]);
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

    /* Brand */
    Route::get('brand', function () {
        return Inertia::render('brand/index');
    })->name('brand');

    Route::get('brand/trash', function () {
        return Inertia::render('brand/trash');
    })->name('brand.trash');
    /* Brand */

    /* Unit */
    Route::get('unit', function () {
        return Inertia::render('unit/index');
    })->name('unit');

    Route::get('unit/trash', function () {
        return Inertia::render('unit/trash');
    })->name('unit.trash');
    /* Unit */

    /* Warranty */
    Route::get('warranty', function () {
        return Inertia::render('warranty/index');
    })->name('warranty');

    Route::get('warranty/trash', function () {
        return Inertia::render('warranty/trash');
    })->name('warranty.trash');
    /* Warranty */

    /* Category */
    Route::get('category', function () {
        return Inertia::render('category/index');
    })->name('category');

    Route::get('category/trash', function () {
        return Inertia::render('category/trash');
    })->name('category.trash');
    /* Category */

    /* Item Type */
    Route::get('itemtype', function () {
        return Inertia::render('itemtype/index');
    })->name('itemtype');

    Route::get('itemtype/trash', function () {
        return Inertia::render('itemtype/trash');
    })->name('itemtype.trash');
    /* Item Type */

    /* Purchase */
    Route::get('purchase', function () {
        return Inertia::render('purchase/index');
    })->name('purchase');

    Route::get('purchase/add', function () {
        return Inertia::render('purchase/add');
    })->name('purchase.add');

    Route::get('purchase/return', function () {
        return Inertia::render('purchase/purchasereturn/index');
    })->name('purchase.return');

    Route::get('purchase/return/add', function () {
        return Inertia::render('purchase/purchasereturn/add');
    })->name('purchase.return.add');

    Route::get('purchase/return/trash', function () {
        return Inertia::render('purchase/purchasereturn/trash');
    })->name('purchase.return.trash');

    Route::get('purchase/return/{id}/edit', function ($id) {
        return Inertia::render('purchase/purchasereturn/edit', ['id' => $id]);
    })->name('purchase.return.edit');

    Route::get('purchase/return/{id}/view', function ($id) {
        return Inertia::render('purchase/purchasereturn/view', ['id' => $id]);
    })->name('purchase.return.view');

    Route::get('purchase/approval', function () {
        return Inertia::render('approval/purchase/index');
    })->name('purchase.approval');

    Route::get('purchase/approval/{id}/view', function ($id) {
        return Inertia::render('approval/purchase/view', [
            'id' => $id,
            'returnTo' => '/purchase/approval',
            'listTitle' => 'Purchase Approval',
        ]);
    })->name('purchase.approval.view');

    Route::get('purchase/{id}/edit', function ($id) {
        return Inertia::render('purchase/edit', ['id' => $id]);
    })->name('purchase.edit');

    Route::get('purchase/{id}/view', function ($id) {
        return Inertia::render('approval/purchase/view', [
            'id' => $id,
            'returnTo' => '/purchase',
            'listTitle' => 'Purchase Management',
        ]);
    })->name('purchase.view');

    Route::get('purchase/trash', function () {
        return Inertia::render('purchase/trash');
    })->name('purchase.trash');
    /* Purchase */

    /* Receiving Note */
    Route::get('receivingnote', function () {
        return Inertia::render('purchase/receivingnote/index');
    })->name('receivingnote');

    Route::get('receivingnote/add', function () {
        return Inertia::render('purchase/receivingnote/add');
    })->name('receivingnote.add');

    Route::get('receivingnote/trash', function () {
        return Inertia::render('purchase/receivingnote/trash');
    })->name('receivingnote.trash');

    Route::get('receivingnote/{id}/edit', function ($id) {
        return Inertia::render('purchase/receivingnote/edit', ['id' => $id]);
    })->name('receivingnote.edit');

    Route::get('receivingnote/{id}/view', function ($id) {
        return Inertia::render('purchase/receivingnote/view', ['id' => $id]);
    })->name('receivingnote.view');
    /* Receiving Note */

    /* Sell */
    Route::get('sell', function () {
        return Inertia::render('sell/index');
    })->name('sell');

    Route::get('sell/add', function () {
        return Inertia::render('sell/add');
    })->name('sell.add');

    Route::get('sell/trash', function () {
        return Inertia::render('sell/trash');
    })->name('sell.trash');

    Route::get('sell/approval', function () {
        return Inertia::render('approval/sell/index');
    })->name('sell.approval');

    Route::get('sell/approval/{id}/view', function ($id) {
        return Inertia::render('approval/sell/view', [
            'id' => $id,
            'returnTo' => '/sell/approval',
            'listTitle' => 'Sell Approval',
        ]);
    })->name('sell.approval.view');

    Route::get('sell/{id}/edit', function ($id) {
        return Inertia::render('sell/edit', ['id' => $id]);
    })->name('sell.edit');

    Route::get('sell/{id}/view', function ($id) {
        return Inertia::render('approval/sell/view', [
            'id' => $id,
            'returnTo' => '/sell',
            'listTitle' => 'Sell Management',
        ]);
    })->name('sell.view');
    /* Sell */

    /* Issue Note */
    Route::get('issuenote', function () {
        return Inertia::render('sell/issuenote/index');
    })->name('issuenote');

    Route::get('issuenote/add', function () {
        return Inertia::render('sell/issuenote/add');
    })->name('issuenote.add');

    Route::get('issuenote/trash', function () {
        return Inertia::render('sell/issuenote/trash');
    })->name('issuenote.trash');

    Route::get('issuenote/{id}/edit', function ($id) {
        return Inertia::render('sell/issuenote/edit', ['id' => $id]);
    })->name('issuenote.edit');

    Route::get('issuenote/{id}/view', function ($id) {
        return Inertia::render('sell/issuenote/view', ['id' => $id]);
    })->name('issuenote.view');
    /* Issue Note */

    /* Product */
    Route::get('product', function () {
        return Inertia::render('product/index');
    })->name('product');

    Route::get('product/add', function () {
        return Inertia::render('product/add');
    })->name('product.add');

    Route::get('product/{id}/edit', function ($id) {
        return Inertia::render('product/edit', ['id' => $id]);
    })->name('product.edit');

    Route::get('product/trash', function () {
        return Inertia::render('product/trash');
    })->name('product.trash');
    /* Product */

    /* Journal Entry */
    Route::get('journalentry', function () {
        return Inertia::render('journalentry/index');
    })->name('journalentry');

    Route::get('journalentry/add', function () {
        return Inertia::render('journalentry/add');
    })->name('journalentry.add');

    Route::get('journalentry/{id}/edit', function ($id) {
        return Inertia::render('journalentry/edit', ['id' => $id]);
    })->name('journalentry.edit');

    Route::get('journalentry/{id}/view', function ($id) {
        return Inertia::render('journalentry/view', ['id' => $id]);
    })->name('journalentry.view');
    /* Journal Entry */

    /* Variation */
    Route::get('variation', function () {
        return Inertia::render('variation/index');
    })->name('variation');

    Route::get('variation/trash', function () {
        return Inertia::render('variation/trash');
    })->name('variation.trash');
    /* Variation */

    /* Customer Group */
    Route::get('customer-group', function () {
        return Inertia::render('contact/customer-group/index');
    })->name('customer-group');

    Route::get('customer-group/trash', function () {
        return Inertia::render('contact/customer-group/trash');
    })->name('customer-group.trash');
    /* Customer Group */

    /* Customer */
    Route::get('customer', function () {
        return Inertia::render('contact/customer/index');
    })->name('customer');

    Route::get('customer/trash', function () {
        return Inertia::render('contact/customer/trash');
    })->name('customer.trash');

    Route::get('customer/{id}/view', function ($id) {
        return Inertia::render('contact/customer/view', ['id' => $id]);
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

    /* Country */
    Route::get('country', function () {
        return Inertia::render('country/index');
    })->name('country');

    Route::get('country/trash', function () {
        return Inertia::render('country/trash');
    })->name('country.trash');
    /* Country */

    /* State */
    Route::get('state', function () {
        return Inertia::render('state/index');
    })->name('state');

    Route::get('state/trash', function () {
        return Inertia::render('state/trash');
    })->name('state.trash');
    /* State */

    /* City */
    Route::get('city', function () {
        return Inertia::render('city/index');
    })->name('city');

    Route::get('city/trash', function () {
        return Inertia::render('city/trash');
    })->name('city.trash');
    /* City */

    /* Setting */
    Route::get('setting', function () {
        return Inertia::render('company/setting');
    })->name('setting');
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
