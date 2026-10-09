<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentSettingController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PublicLeadCaptureController;
use App\Http\Controllers\Reports\AnalyticsReportController;
use App\Http\Controllers\Reports\FinancialReportController;
use App\Http\Controllers\Reports\LedgerReportController;
use App\Http\Controllers\Reports\PartyReportController;
use App\Http\Controllers\Reports\ProductReportController;
use App\Http\Controllers\Reports\StockReportController;
use App\Http\Controllers\Reports\TransactionReportController;
use App\Http\Controllers\SettingController;
use App\Http\Middleware\EnforcePageMenuPermission;
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

/* Public Lead Capture Form (no auth — embeddable on an external site) */
Route::get('capture/{code}', [PublicLeadCaptureController::class, 'show'])->name('leadcapture.show');
/* Public Lead Capture Form */

Route::middleware(['auth', 'verified', EnforcePageMenuPermission::class])->group(function () {
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
        return Inertia::render('product/brand/index');
    })->name('brand');

    Route::get('brand/trash', function () {
        return Inertia::render('product/brand/trash');
    })->name('brand.trash');
    /* Brand */

    /* Unit */
    Route::get('unit', function () {
        return Inertia::render('product/unit/index');
    })->name('unit');

    Route::get('unit/trash', function () {
        return Inertia::render('product/unit/trash');
    })->name('unit.trash');
    /* Unit */

    /* Warranty */
    Route::get('warranty', function () {
        return Inertia::render('product/warranty/index');
    })->name('warranty');

    Route::get('warranty/trash', function () {
        return Inertia::render('product/warranty/trash');
    })->name('warranty.trash');
    /* Warranty */

    /* Category */
    Route::get('category', function () {
        return Inertia::render('product/category/index');
    })->name('category');

    Route::get('category/trash', function () {
        return Inertia::render('product/category/trash');
    })->name('category.trash');
    /* Category */

    /* Item Type */
    Route::get('itemtype', function () {
        return Inertia::render('product/itemtype/index');
    })->name('itemtype');

    Route::get('itemtype/trash', function () {
        return Inertia::render('product/itemtype/trash');
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

    Route::get('purchase/payment', function () {
        return Inertia::render('purchase/payment/index');
    })->name('purchase.payment');

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

    /* Purchase Requisition */
    Route::get('purchaserequisition', function () {
        return Inertia::render('purchaserequisition/index');
    })->name('purchaserequisition');

    Route::get('purchaserequisition/add', function () {
        return Inertia::render('purchaserequisition/add');
    })->name('purchaserequisition.add');

    Route::get('purchaserequisition/{id}/edit', function ($id) {
        return Inertia::render('purchaserequisition/edit', ['id' => $id]);
    })->name('purchaserequisition.edit');

    Route::get('purchaserequisition/{id}/view', function ($id) {
        return Inertia::render('purchaserequisition/view', [
            'id' => $id,
            'returnTo' => '/purchaserequisition',
            'listTitle' => 'Purchase Requisition',
        ]);
    })->name('purchaserequisition.view');

    Route::get('purchaserequisition/approval', function () {
        return Inertia::render('approval/purchaserequisition/index');
    })->name('purchaserequisition.approval');

    Route::get('purchaserequisition/approval/{id}/view', function ($id) {
        return Inertia::render('purchaserequisition/view', [
            'id' => $id,
            'returnTo' => '/purchaserequisition/approval',
            'listTitle' => 'Purchase Requisition Approval',
        ]);
    })->name('purchaserequisition.approval.view');
    /* Purchase Requisition */

    /* Stock Transfer */
    Route::get('stocktransfer', function () {
        return Inertia::render('stocktransfer/index');
    })->name('stocktransfer');

    Route::get('stocktransfer/add', function () {
        return Inertia::render('stocktransfer/add');
    })->name('stocktransfer.add');

    Route::get('stocktransfer/{id}/edit', function ($id) {
        return Inertia::render('stocktransfer/edit', ['id' => $id]);
    })->name('stocktransfer.edit');

    Route::get('stocktransfer/trash', function () {
        return Inertia::render('stocktransfer/trash');
    })->name('stocktransfer.trash');
    /* Stock Transfer */

    /* Warehouse */
    Route::get('warehouse', function () {
        return Inertia::render('warehouse/index');
    })->name('warehouse');

    Route::get('warehouse/add', function () {
        return Inertia::render('warehouse/add');
    })->name('warehouse.add');

    Route::get('warehouse/{id}/edit', function ($id) {
        return Inertia::render('warehouse/edit', ['id' => $id]);
    })->name('warehouse.edit');

    Route::get('warehouse/trash', function () {
        return Inertia::render('warehouse/trash');
    })->name('warehouse.trash');
    /* Warehouse */

    /* Consumer */
    Route::get('consumer', function () {
        return Inertia::render('consumer/index');
    })->name('consumer');

    Route::get('consumer/add', function () {
        return Inertia::render('consumer/add');
    })->name('consumer.add');

    Route::get('consumer/{id}/edit', function ($id) {
        return Inertia::render('consumer/edit', ['id' => $id]);
    })->name('consumer.edit');

    Route::get('consumer/trash', function () {
        return Inertia::render('consumer/trash');
    })->name('consumer.trash');
    /* Consumer */

    /* Bank Issuer */
    Route::get('bankissuer', function () {
        return Inertia::render('bankissuer/index');
    })->name('bankissuer');

    Route::get('bankissuer/add', function () {
        return Inertia::render('bankissuer/add');
    })->name('bankissuer.add');

    Route::get('bankissuer/{id}/edit', function ($id) {
        return Inertia::render('bankissuer/edit', ['id' => $id]);
    })->name('bankissuer.edit');

    Route::get('bankissuer/trash', function () {
        return Inertia::render('bankissuer/trash');
    })->name('bankissuer.trash');
    /* Bank Issuer */

    /* Cash Collection */
    Route::get('cashcollection', function () {
        abortUnlessMenuPermission('/cashcollection');

        return Inertia::render('cashcollection/index');
    })->name('cashcollection');

    Route::get('cashcollection/add', function () {
        abortUnlessMenuPermission('/cashcollection/add');

        return Inertia::render('cashcollection/add');
    })->name('cashcollection.add');

    Route::get('cashcollection/{id}/edit', function ($id) {
        abortUnlessMenuPermission('/cashcollection/:id/edit');

        return Inertia::render('cashcollection/edit', ['id' => $id]);
    })->name('cashcollection.edit');

    Route::get('cashcollection/{id}/view', function ($id) {
        abortUnlessMenuPermission('/cashcollection/:id/view');

        return Inertia::render('cashcollection/view', ['id' => $id]);
    })->name('cashcollection.view');

    Route::get('cashcollection/trash', function () {
        abortUnlessMenuPermission('/cashcollection/restore');

        return Inertia::render('cashcollection/trash');
    })->name('cashcollection.trash');
    /* Cash Collection */

    /* Customer / supplier portal */
    Route::get('portal', function () {
        abort_unless(auth()->user()?->contact_id !== null, 403);

        return Inertia::render('portal/index');
    })->name('portal');

    Route::get('portalusers', function () {
        abortUnlessMenuPermission('/portalusers');

        return Inertia::render('portalusers/index');
    })->name('portalusers');
    /* Customer / supplier portal */

    /* Exchange rates */
    Route::get('currencyrate', function () {
        abortUnlessMenuPermission('/currencyrate');

        return Inertia::render('currencyrate/index');
    })->name('currencyrate');
    /* Exchange rates */

    /* API keys */
    Route::get('apikeys', function () {
        abortUnlessMenuPermission('/apikeys');

        return Inertia::render('apikey/index');
    })->name('apikeys');
    /* API keys */

    /* Backup */
    Route::get('backup', function () {
        abort_unless(auth()->user()?->hasRole('superadmin'), 403);
        abortUnlessMenuPermission('/backup');

        return Inertia::render('backup/index');
    })->name('backup');
    /* Backup */

    /* Document settings */
    Route::get('barcode/settings', fn () => app(DocumentSettingController::class)->page('barcode'))->name('barcode.settings');
    Route::get('invoice/settings', fn () => app(DocumentSettingController::class)->page('invoice'))->name('invoice.settings');
    Route::get('receipt/settings', fn () => app(DocumentSettingController::class)->page('receipt'))->name('receipt.settings');
    Route::get('checkout/settings', fn () => app(DocumentSettingController::class)->page('checkout'))->name('checkout.settings');
    /* Document settings */

    /* Stock Take */
    Route::get('stocktake', function () {
        abortUnlessMenuPermission('/stocktake');

        return Inertia::render('stocktake/index');
    })->name('stocktake');

    Route::get('stocktake/add', function () {
        abortUnlessMenuPermission('/stocktake/add');

        return Inertia::render('stocktake/add');
    })->name('stocktake.add');

    Route::get('stocktake/{id}/view', function ($id) {
        abortUnlessMenuPermission('/stocktake/:id/view');

        return Inertia::render('stocktake/view', ['id' => $id]);
    })->name('stocktake.view');

    Route::get('stocktake/trash', function () {
        abortUnlessMenuPermission('/stocktake/restore');

        return Inertia::render('stocktake/trash');
    })->name('stocktake.trash');
    /* Stock Take */

    /* Loyalty */
    Route::get('loyalty', function () {
        abortUnlessMenuPermission('/loyalty');

        return Inertia::render('loyalty/index');
    })->name('loyalty');

    Route::get('loyalty/settings', function () {
        abortUnlessMenuPermission('/loyalty/settings');

        return Inertia::render('loyalty/settings');
    })->name('loyalty.settings');

    Route::get('loyalty/{id}/view', function ($id) {
        abortUnlessMenuPermission('/loyalty/:id/view');

        return Inertia::render('loyalty/view', ['id' => $id]);
    })->name('loyalty.view');
    /* Loyalty */

    /* Discount */
    Route::get('discount', function () {
        abortUnlessMenuPermission('/discount');

        return Inertia::render('discount/index');
    })->name('discount');

    Route::get('discount/add', function () {
        abortUnlessMenuPermission('/discount/add');

        return Inertia::render('discount/add');
    })->name('discount.add');

    Route::get('discount/{id}/edit', function ($id) {
        abortUnlessMenuPermission('/discount/:id/edit');

        return Inertia::render('discount/edit', ['id' => $id]);
    })->name('discount.edit');

    Route::get('discount/{id}/view', function ($id) {
        abortUnlessMenuPermission('/discount/:id/view');

        return Inertia::render('discount/view', ['id' => $id]);
    })->name('discount.view');

    Route::get('discount/trash', function () {
        abortUnlessMenuPermission('/discount/restore');

        return Inertia::render('discount/trash');
    })->name('discount.trash');
    /* Discount */

    /* Gift Card */
    Route::get('giftcard', function () {
        abortUnlessMenuPermission('/giftcard');

        return Inertia::render('giftcard/index');
    })->name('giftcard');

    Route::get('giftcard/add', function () {
        abortUnlessMenuPermission('/giftcard/add');

        return Inertia::render('giftcard/add');
    })->name('giftcard.add');

    Route::get('giftcard/{id}/edit', function ($id) {
        abortUnlessMenuPermission('/giftcard/:id/edit');

        return Inertia::render('giftcard/edit', ['id' => $id]);
    })->name('giftcard.edit');

    Route::get('giftcard/{id}/view', function ($id) {
        abortUnlessMenuPermission('/giftcard/:id/view');

        return Inertia::render('giftcard/view', ['id' => $id]);
    })->name('giftcard.view');

    Route::get('giftcard/trash', function () {
        abortUnlessMenuPermission('/giftcard/restore');

        return Inertia::render('giftcard/trash');
    })->name('giftcard.trash');

    Route::get('giftcard/orphan-refunds', function () {
        abortUnlessMenuPermission('/giftcard/orphan-refunds');

        return Inertia::render('giftcard/orphan-refunds');
    })->name('giftcard.orphan-refunds');
    /* Gift Card */

    /* Transporter */
    Route::get('transporter', function () {
        abortUnlessMenuPermission('/transporter');

        return Inertia::render('transporter/index');
    })->name('transporter');

    Route::get('transporter/add', function () {
        abortUnlessMenuPermission('/transporter/add');

        return Inertia::render('transporter/add');
    })->name('transporter.add');

    Route::get('transporter/{id}/edit', function ($id) {
        abortUnlessMenuPermission('/transporter/:id/edit');

        return Inertia::render('transporter/edit', ['id' => $id]);
    })->name('transporter.edit');

    Route::get('transporter/{id}/view', function ($id) {
        abortUnlessMenuPermission('/transporter/:id/view');

        return Inertia::render('transporter/view', ['id' => $id]);
    })->name('transporter.view');

    Route::get('transporter/trash', function () {
        abortUnlessMenuPermission('/transporter/restore');

        return Inertia::render('transporter/trash');
    })->name('transporter.trash');
    /* Transporter */

    /* Commission Agent */
    Route::get('commissionagent', function () {
        abortUnlessMenuPermission('/commissionagent');

        return Inertia::render('commissionagent/index');
    })->name('commissionagent');

    Route::get('commissionagent/add', function () {
        abortUnlessMenuPermission('/commissionagent/add');

        return Inertia::render('commissionagent/add');
    })->name('commissionagent.add');

    Route::get('commissionagent/{id}/edit', function ($id) {
        abortUnlessMenuPermission('/commissionagent/:id/edit');

        return Inertia::render('commissionagent/edit', ['id' => $id]);
    })->name('commissionagent.edit');

    Route::get('commissionagent/{id}/view', function ($id) {
        abortUnlessMenuPermission('/commissionagent/:id/view');

        return Inertia::render('commissionagent/view', ['id' => $id]);
    })->name('commissionagent.view');

    Route::get('commissionagent/trash', function () {
        abortUnlessMenuPermission('/commissionagent/restore');

        return Inertia::render('commissionagent/trash');
    })->name('commissionagent.trash');
    /* Commission Agent */

    /* Leads (CRM) */
    Route::get('leads', function () {
        abortUnlessMenuPermission('/leads');

        return Inertia::render('lead/index');
    })->name('leads');

    Route::get('leads/add', function () {
        abortUnlessMenuPermission('/leads/add');

        return Inertia::render('lead/add');
    })->name('leads.add');

    Route::get('leads/{id}/edit', function ($id) {
        abortUnlessMenuPermission('/leads/:id/edit');

        return Inertia::render('lead/edit', ['id' => $id]);
    })->name('leads.edit');

    Route::get('leads/{id}/view', function ($id) {
        abortUnlessMenuPermission('/leads/:id/view');

        return Inertia::render('lead/view', ['id' => $id]);
    })->name('leads.view');

    Route::get('leads/trash', function () {
        abortUnlessMenuPermission('/leads/restore');

        return Inertia::render('lead/trash');
    })->name('leads.trash');
    /* Leads (CRM) */

    /* Lead Sources (CRM) */
    Route::get('leadsources', function () {
        abortUnlessMenuPermission('/leadsources');

        return Inertia::render('leadsource/index');
    })->name('leadsources');

    Route::get('leadsources/add', function () {
        abortUnlessMenuPermission('/leadsources/add');

        return Inertia::render('leadsource/add');
    })->name('leadsources.add');

    Route::get('leadsources/{id}/edit', function ($id) {
        abortUnlessMenuPermission('/leadsources/:id/edit');

        return Inertia::render('leadsource/edit', ['id' => $id]);
    })->name('leadsources.edit');

    Route::get('leadsources/{id}/view', function ($id) {
        abortUnlessMenuPermission('/leadsources/:id/view');

        return Inertia::render('leadsource/view', ['id' => $id]);
    })->name('leadsources.view');

    Route::get('leadsources/trash', function () {
        abortUnlessMenuPermission('/leadsources/restore');

        return Inertia::render('leadsource/trash');
    })->name('leadsources.trash');
    /* Lead Sources (CRM) */

    /* Pipeline Stages (CRM) */
    Route::get('pipelinestages', function () {
        abortUnlessMenuPermission('/pipelinestages');

        return Inertia::render('pipelinestage/index');
    })->name('pipelinestages');

    Route::get('pipelinestages/add', function () {
        abortUnlessMenuPermission('/pipelinestages/add');

        return Inertia::render('pipelinestage/add');
    })->name('pipelinestages.add');

    Route::get('pipelinestages/{id}/edit', function ($id) {
        abortUnlessMenuPermission('/pipelinestages/:id/edit');

        return Inertia::render('pipelinestage/edit', ['id' => $id]);
    })->name('pipelinestages.edit');

    Route::get('pipelinestages/{id}/view', function ($id) {
        abortUnlessMenuPermission('/pipelinestages/:id/view');

        return Inertia::render('pipelinestage/view', ['id' => $id]);
    })->name('pipelinestages.view');

    Route::get('pipelinestages/trash', function () {
        abortUnlessMenuPermission('/pipelinestages/restore');

        return Inertia::render('pipelinestage/trash');
    })->name('pipelinestages.trash');
    /* Pipeline Stages (CRM) */

    /* Opportunities (CRM) */
    Route::get('opportunities', function () {
        abortUnlessMenuPermission('/opportunities');

        return Inertia::render('opportunity/index');
    })->name('opportunities');

    Route::get('opportunities/add', function () {
        abortUnlessMenuPermission('/opportunities/add');

        return Inertia::render('opportunity/add', ['leadId' => request()->query('lead_id')]);
    })->name('opportunities.add');

    Route::get('opportunities/{id}/edit', function ($id) {
        abortUnlessMenuPermission('/opportunities/:id/edit');

        return Inertia::render('opportunity/edit', ['id' => $id]);
    })->name('opportunities.edit');

    Route::get('opportunities/{id}/view', function ($id) {
        abortUnlessMenuPermission('/opportunities/:id/view');

        return Inertia::render('opportunity/view', ['id' => $id]);
    })->name('opportunities.view');

    Route::get('opportunities/trash', function () {
        abortUnlessMenuPermission('/opportunities/restore');

        return Inertia::render('opportunity/trash');
    })->name('opportunities.trash');
    /* Opportunities (CRM) */

    /* Pipeline Board (CRM) */
    Route::get('pipeline', function () {
        abortUnlessMenuPermission('/pipeline');

        return Inertia::render('opportunity/pipeline');
    })->name('pipeline');
    /* Pipeline Board (CRM) */

    /* Activities (CRM) */
    Route::get('activities', function () {
        abortUnlessMenuPermission('/activities');

        return Inertia::render('activity/index');
    })->name('activities');

    Route::get('activities/add', function () {
        abortUnlessMenuPermission('/activities/add');

        return Inertia::render('activity/add', [
            'leadId' => request()->query('lead_id'),
            'opportunityId' => request()->query('opportunity_id'),
            'contactId' => request()->query('contact_id'),
        ]);
    })->name('activities.add');

    Route::get('activities/{id}/edit', function ($id) {
        abortUnlessMenuPermission('/activities/:id/edit');

        return Inertia::render('activity/edit', ['id' => $id]);
    })->name('activities.edit');

    Route::get('activities/{id}/view', function ($id) {
        abortUnlessMenuPermission('/activities/:id/view');

        return Inertia::render('activity/view', ['id' => $id]);
    })->name('activities.view');

    Route::get('activities/trash', function () {
        abortUnlessMenuPermission('/activities/restore');

        return Inertia::render('activity/trash');
    })->name('activities.trash');
    /* Activities (CRM) */

    /* CRM Analytics */
    Route::get('crmanalytics', function () {
        abortUnlessMenuPermission('/crmanalytics');

        return Inertia::render('crmanalytics/index');
    })->name('crmanalytics');
    /* CRM Analytics */

    /* Subscription Plans (SaaS Tenant Billing) */
    Route::get('subscriptionplans', function () {
        abortUnlessMenuPermission('/subscriptionplans');

        return Inertia::render('subscriptionplan/index');
    })->name('subscriptionplans');

    Route::get('subscriptionplans/add', function () {
        abortUnlessMenuPermission('/subscriptionplans/add');

        return Inertia::render('subscriptionplan/add');
    })->name('subscriptionplans.add');

    Route::get('subscriptionplans/{id}/edit', function ($id) {
        abortUnlessMenuPermission('/subscriptionplans/:id/edit');

        return Inertia::render('subscriptionplan/edit', ['id' => $id]);
    })->name('subscriptionplans.edit');
    /* Subscription Plans (SaaS Tenant Billing) */

    /* Coupons (SaaS Tenant Billing) */
    Route::get('coupons', function () {
        abortUnlessMenuPermission('/coupons');

        return Inertia::render('coupon/index');
    })->name('coupons');

    Route::get('coupons/add', function () {
        abortUnlessMenuPermission('/coupons/add');

        return Inertia::render('coupon/add');
    })->name('coupons.add');

    Route::get('coupons/{id}/edit', function ($id) {
        abortUnlessMenuPermission('/coupons/:id/edit');

        return Inertia::render('coupon/edit', ['id' => $id]);
    })->name('coupons.edit');
    /* Coupons (SaaS Tenant Billing) */

    /* Tenant Directory (SaaS Tenant Billing) */
    Route::get('tenants', function () {
        abortUnlessMenuPermission('/tenants');

        return Inertia::render('tenant/index');
    })->name('tenants');
    /* Tenant Directory (SaaS Tenant Billing) */

    /* Subscription Invoices (SaaS Tenant Billing) */
    Route::get('subscriptioninvoices', function () {
        abortUnlessMenuPermission('/subscriptioninvoices');

        return Inertia::render('subscriptioninvoice/index');
    })->name('subscriptioninvoices');
    /* Subscription Invoices (SaaS Tenant Billing) */

    /* Webhooks (Integrations & Open API) */
    Route::get('webhooks', function () {
        abortUnlessMenuPermission('/webhooks');

        return Inertia::render('webhook/index');
    })->name('webhooks');
    /* Webhooks (Integrations & Open API) */

    /* API Logs (Integrations & Open API) */
    Route::get('apilogs', function () {
        abortUnlessMenuPermission('/apilogs');

        return Inertia::render('apilog/index');
    })->name('apilogs');
    /* API Logs (Integrations & Open API) */

    /* Customer Subscription Plans (Customer Subscription Module) */
    Route::get('customersubscriptionplans', function () {
        abortUnlessMenuPermission('/customersubscriptionplans');

        return Inertia::render('customersubscriptionplan/index');
    })->name('customersubscriptionplans');

    Route::get('customersubscriptionplans/add', function () {
        abortUnlessMenuPermission('/customersubscriptionplans/add');

        return Inertia::render('customersubscriptionplan/add');
    })->name('customersubscriptionplans.add');

    Route::get('customersubscriptionplans/{id}/edit', function ($id) {
        abortUnlessMenuPermission('/customersubscriptionplans/:id/edit');

        return Inertia::render('customersubscriptionplan/edit', ['id' => $id]);
    })->name('customersubscriptionplans.edit');
    /* Customer Subscription Plans (Customer Subscription Module) */

    /* Customer Subscriptions (Customer Subscription Module) */
    Route::get('customersubscriptions', function () {
        abortUnlessMenuPermission('/customersubscriptions');

        return Inertia::render('customersubscription/index');
    })->name('customersubscriptions');
    /* Customer Subscriptions (Customer Subscription Module) */

    /* Customer Subscription Invoices (Customer Subscription Module) */
    Route::get('customersubscriptioninvoices', function () {
        abortUnlessMenuPermission('/customersubscriptioninvoices');

        return Inertia::render('customersubscriptioninvoice/index');
    })->name('customersubscriptioninvoices');
    /* Customer Subscription Invoices (Customer Subscription Module) */

    /* Customer Subscription Analytics (Customer Subscription Module) */
    Route::get('customersubscriptionanalytics', function () {
        abortUnlessMenuPermission('/customersubscriptionanalytics');

        return Inertia::render('customersubscriptionanalytics/index');
    })->name('customersubscriptionanalytics');
    /* Customer Subscription Analytics (Customer Subscription Module) */

    /* Price List */
    Route::get('pricelist', function () {
        return Inertia::render('pricelist/index');
    })->name('pricelist');

    Route::get('pricelist/add', function () {
        return Inertia::render('pricelist/add');
    })->name('pricelist.add');

    Route::get('pricelist/{id}/edit', function ($id) {
        return Inertia::render('pricelist/edit', ['id' => $id]);
    })->name('pricelist.edit');

    Route::get('pricelist/trash', function () {
        return Inertia::render('pricelist/trash');
    })->name('pricelist.trash');
    /* Price List */

    /* Print Label */
    Route::get('printlabel', function () {
        return Inertia::render('printlabel/index');
    })->name('printlabel');
    /* Print Label */

    /* Low Stock */
    Route::get('lowstock', function () {
        return Inertia::render('lowstock/index');
    })->name('lowstock');
    /* Low Stock */

    /* Reports */
    Route::get('report/ledger', function () {
        abortUnlessMenuPermission('/report/ledger');

        return Inertia::render('report/ledger');
    })->name('report.ledger');

    foreach (TransactionReportController::PERMISSIONS as $report => $path) {
        Route::get(ltrim($path, '/'), function () use ($report, $path) {
            abortUnlessMenuPermission($path);

            return Inertia::render('report/index', ['report' => $report]);
        })->name('report.'.$report);
    }

    foreach (PartyReportController::PERMISSIONS as $report => $path) {
        Route::get(ltrim($path, '/'), function () use ($report, $path) {
            abortUnlessMenuPermission($path);

            return Inertia::render('report/index', ['report' => $report]);
        })->name('report.'.$report);
    }

    foreach (ProductReportController::PERMISSIONS as $report => $path) {
        Route::get(ltrim($path, '/'), function () use ($report, $path) {
            abortUnlessMenuPermission($path);

            return Inertia::render('report/index', ['report' => $report]);
        })->name('report.'.$report);
    }

    foreach (StockReportController::PERMISSIONS as $report => $path) {
        Route::get(ltrim($path, '/'), function () use ($report, $path) {
            abortUnlessMenuPermission($path);

            return Inertia::render('report/index', ['report' => $report]);
        })->name('report.'.$report);
    }

    foreach (LedgerReportController::PERMISSIONS as $report => $path) {
        Route::get(ltrim($path, '/'), function () use ($report, $path) {
            abortUnlessMenuPermission($path);

            return Inertia::render('report/index', ['report' => $report]);
        })->name('report.'.$report);
    }

    foreach (AnalyticsReportController::PERMISSIONS as $report => $path) {
        Route::get(ltrim($path, '/'), function () use ($report, $path) {
            abortUnlessMenuPermission($path);

            return Inertia::render('report/index', ['report' => $report]);
        })->name('report.'.$report);
    }

    foreach (FinancialReportController::PERMISSIONS as $report => $path) {
        Route::get(ltrim($path, '/'), function () use ($report, $path) {
            abortUnlessMenuPermission($path);

            return Inertia::render('report/index', ['report' => $report]);
        })->name('report.'.$report);
    }
    /* Reports */

    /* Stock Adjustment */
    Route::get('stockadjustment', function () {
        return Inertia::render('stockadjustment/index');
    })->name('stockadjustment');

    Route::get('stockadjustment/add', function () {
        return Inertia::render('stockadjustment/add');
    })->name('stockadjustment.add');

    Route::get('stockadjustment/{id}/edit', function ($id) {
        return Inertia::render('stockadjustment/edit', ['id' => $id]);
    })->name('stockadjustment.edit');

    Route::get('stockadjustment/trash', function () {
        return Inertia::render('stockadjustment/trash');
    })->name('stockadjustment.trash');
    /* Stock Adjustment */

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

    Route::get('sell/payment', function () {
        return Inertia::render('sell/payment/index');
    })->name('sell.payment');

    Route::get('sell/return', function () {
        return Inertia::render('sell/return/index');
    })->name('sell.return');

    Route::get('sell/return/add', function () {
        return Inertia::render('sell/return/add');
    })->name('sell.return.add');

    Route::get('sell/return/trash', function () {
        return Inertia::render('sell/return/trash');
    })->name('sell.return.trash');

    Route::get('sell/return/{id}/edit', function ($id) {
        return Inertia::render('sell/return/edit', ['id' => $id]);
    })->name('sell.return.edit');

    Route::get('sell/return/{id}/view', function ($id) {
        return Inertia::render('sell/return/view', ['id' => $id]);
    })->name('sell.return.view');

    Route::get('sell/draft', function () {
        return Inertia::render('sell/draft');
    })->name('sell.draft');

    Route::get('sell/quotation', function () {
        return Inertia::render('sell/quotation');
    })->name('sell.quotation');

    Route::get('sell/shipment', function () {
        return Inertia::render('sell/shipment');
    })->name('sell.shipment');

    Route::get('sell/pos/add', function () {
        return Inertia::render('sell/addpos');
    })->name('sell.pos.add');

    Route::get('sell/pos/display', function () {
        return Inertia::render('sell/posdisplay');
    })->name('sell.pos.display');

    Route::get('sell/{id}/invoice', function ($id) {
        return Inertia::render('sell/invoice', ['id' => $id]);
    })->name('sell.invoice');

    Route::get('sell/{id}/receipt', function ($id) {
        return Inertia::render('sell/receipt', ['id' => $id]);
    })->name('sell.receipt');

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

    /* Payment */
    Route::get('acpayment', function () {
        return Inertia::render('payment/index');
    })->name('acpayment');

    Route::get('acpayment/add', function () {
        return Inertia::render('payment/add');
    })->name('acpayment.add');

    Route::get('acpayment/{id}/edit', function ($id) {
        return Inertia::render('payment/edit', ['id' => $id]);
    })->name('acpayment.edit');

    Route::get('acpayment/{id}/view', function ($id) {
        return Inertia::render('payment/view', ['id' => $id]);
    })->name('acpayment.view');
    /* Payment */

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

    Route::get('journalentry/approval', function () {
        return Inertia::render('approval/journalentry/index');
    })->name('journalentry.approval');

    Route::get('journalentry/approval/{id}/view', function ($id) {
        return Inertia::render('journalentry/view', [
            'id' => $id,
            'returnTo' => '/journalentry/approval',
            'listTitle' => 'Journal Entry Approval',
        ]);
    })->name('journalentry.approval.view');
    /* Journal Entry */

    /* Expense */
    Route::get('expense', function () {
        return Inertia::render('expense/index');
    })->name('expense');

    Route::get('expense/add', function () {
        return Inertia::render('expense/add');
    })->name('expense.add');

    Route::get('expense/{id}/edit', function ($id) {
        return Inertia::render('expense/edit', ['id' => $id]);
    })->name('expense.edit');

    Route::get('expense/{id}/view', function ($id) {
        return Inertia::render('expense/view', ['id' => $id]);
    })->name('expense.view');
    /* Expense */

    /* Deposit */
    Route::get('deposit', function () {
        return Inertia::render('deposit/index');
    })->name('deposit');

    Route::get('deposit/add', function () {
        return Inertia::render('deposit/add');
    })->name('deposit.add');

    Route::get('deposit/{id}/edit', function ($id) {
        return Inertia::render('deposit/edit', ['id' => $id]);
    })->name('deposit.edit');

    Route::get('deposit/{id}/view', function ($id) {
        return Inertia::render('deposit/view', ['id' => $id]);
    })->name('deposit.view');
    /* Deposit */

    /* Fund Transfer */
    Route::get('fundtransfer', function () {
        return Inertia::render('fundtransfer/index');
    })->name('fundtransfer');

    Route::get('fundtransfer/add', function () {
        return Inertia::render('fundtransfer/add');
    })->name('fundtransfer.add');

    Route::get('fundtransfer/{id}/edit', function ($id) {
        return Inertia::render('fundtransfer/edit', ['id' => $id]);
    })->name('fundtransfer.edit');

    Route::get('fundtransfer/{id}/view', function ($id) {
        return Inertia::render('fundtransfer/view', ['id' => $id]);
    })->name('fundtransfer.view');
    /* Fund Transfer */

    /* Credit/Debit Note */
    Route::get('creditdebitnote', function () {
        return Inertia::render('creditdebitnote/index');
    })->name('creditdebitnote');

    Route::get('creditdebitnote/add', function () {
        return Inertia::render('creditdebitnote/add');
    })->name('creditdebitnote.add');

    Route::get('creditdebitnote/{id}/edit', function ($id) {
        return Inertia::render('creditdebitnote/edit', ['id' => $id]);
    })->name('creditdebitnote.edit');

    Route::get('creditdebitnote/{id}/view', function ($id) {
        return Inertia::render('creditdebitnote/view', ['id' => $id]);
    })->name('creditdebitnote.view');
    /* Credit/Debit Note */

    /* Payment, Expense, Deposit, Fund Transfer and Credit/Debit Note Approval */
    foreach ([
        'acpayment' => ['payment', 'Payment Approval'],
        'expense' => ['expense', 'Expense Approval'],
        'deposit' => ['deposit', 'Deposit Approval'],
        'fundtransfer' => ['fundtransfer', 'Fund Transfer Approval'],
        'creditdebitnote' => ['creditdebitnote', 'Credit/Debit Note Approval'],
    ] as $path => [$page, $listTitle]) {
        Route::get($path.'/approval', function () use ($page) {
            return Inertia::render('approval/'.$page.'/index');
        })->name($path.'.approval');

        Route::get($path.'/approval/{id}/view', function ($id) use ($page, $path, $listTitle) {
            return Inertia::render($page.'/view', [
                'id' => $id,
                'returnTo' => '/'.$path.'/approval',
                'listTitle' => $listTitle,
            ]);
        })->name($path.'.approval.view');
    }
    /* Payment, Expense, Deposit and Fund Transfer Approval */

    /* Variation */
    Route::get('variation', function () {
        return Inertia::render('product/variation/index');
    })->name('variation');

    Route::get('variation/trash', function () {
        return Inertia::render('product/variation/trash');
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

    /* Approval Center (Phase 1, easy half) */
    Route::get('purchasereturn/approval', fn () => Inertia::render('approval/purchasereturn/index'))->name('purchasereturn.approval');
    Route::get('stockadjustment/approval', fn () => Inertia::render('approval/stockadjustment/index'))->name('stockadjustment.approval');
    Route::get('stocktransfer/approval', fn () => Inertia::render('approval/stocktransfer/index'))->name('stocktransfer.approval');
    Route::get('cashcollection/approval', fn () => Inertia::render('approval/cashcollection/index'))->name('cashcollection.approval');
    Route::get('pricelist/approval', fn () => Inertia::render('approval/pricelist/index'))->name('pricelist.approval');
    Route::get('creditlimit/approval', fn () => Inertia::render('approval/creditlimit/index'))->name('creditlimit.approval');
    /* Approval Center (Phase 1, easy half) */

    Route::get('auditlogs', fn () => Inertia::render('auditlog/index'))->name('auditlogs');
    Route::get('bulkpriceupdate', fn () => Inertia::render('bulkpriceupdate/index'))->name('bulkpriceupdate');
    Route::get('landedcost', fn () => Inertia::render('landedcost/index'))->name('landedcost');
    Route::get('bankreconciliation', fn () => Inertia::render('bankreconciliation/index'))->name('bankreconciliation');
    Route::get('bankreconciliation/{id}', fn ($id) => Inertia::render('bankreconciliation/view', ['id' => $id]))->name('bankreconciliation.view');
    Route::get('posshift', fn () => Inertia::render('posshift/index'))->name('posshift');
    Route::get('assetcategory', fn () => Inertia::render('assetcategory/index'))->name('assetcategory');
    Route::get('fixedasset', fn () => Inertia::render('fixedasset/index'))->name('fixedasset');
    Route::get('depreciation', fn () => Inertia::render('depreciation/index'))->name('depreciation');
    Route::get('costcenter', fn () => Inertia::render('costcenter/index'))->name('costcenter');
    Route::get('stocktracking', fn () => Inertia::render('stocktracking/index'))->name('stocktracking');
    Route::get('taxexemption', fn () => Inertia::render('taxexemption/index'))->name('taxexemption');
    Route::get('warehouselocation', fn () => Inertia::render('warehouselocation/index'))->name('warehouselocation');
    Route::get('budget', fn () => Inertia::render('budget/index'))->name('budget');
    Route::get('assetapproval', fn () => Inertia::render('approval/fixedasset/index'))->name('assetapprovals');

    /* Contacts: Duplicate Detection & Merge */
    Route::get('contacts/duplicates', function () {
        return Inertia::render('contact/duplicates/index');
    })->name('contacts.duplicates');
    /* Contacts: Duplicate Detection & Merge */

    /* Currency */
    Route::get('currency', function () {
        return Inertia::render('software/currency/index');
    })->name('currency');

    Route::get('currency/trash', function () {
        return Inertia::render('software/currency/trash');
    })->name('currency.trash');
    /* Currency */

    /* Timezone */
    Route::get('timezone', function () {
        return Inertia::render('software/timezone/index');
    })->name('timezone');

    Route::get('timezone/trash', function () {
        return Inertia::render('software/timezone/trash');
    })->name('timezone.trash');
    /* Timezone */

    /* Country */
    Route::get('country', function () {
        return Inertia::render('software/country/index');
    })->name('country');

    Route::get('country/trash', function () {
        return Inertia::render('software/country/trash');
    })->name('country.trash');
    /* Country */

    /* State */
    Route::get('state', function () {
        return Inertia::render('software/state/index');
    })->name('state');

    Route::get('state/trash', function () {
        return Inertia::render('software/state/trash');
    })->name('state.trash');
    /* State */

    /* City */
    Route::get('city', function () {
        return Inertia::render('software/city/index');
    })->name('city');

    Route::get('city/trash', function () {
        return Inertia::render('software/city/trash');
    })->name('city.trash');
    /* City */

    /* Tax / Financial Year: sidebar deep links into the Company Setting tabs */
    Route::get('tax', function () {
        return Inertia::render('company/setting', ['tab' => 'tax']);
    })->name('tax');

    Route::get('financialyear', function () {
        return Inertia::render('company/setting', ['tab' => 'financialYear']);
    })->name('financialyear');
    /* Tax / Financial Year */

    /* Setting */
    Route::get('setting', function () {
        return Inertia::render('company/setting');
    })->name('setting');
    Route::get('email_template', [SettingController::class, 'email_template'])->name('email_template');
    Route::post('email_setting/test-send', [SettingController::class, 'email_test_send'])->name('email_setting.test_send');
    /* Setting */
});

Route::middleware(['auth'])->prefix('api')->group(function () {
    Route::get('notifications/counts-by-type', [DashboardController::class, 'notifications']);
});

Route::get('/403', function () {
    return Inertia::render('errors/403');
})->name('403');

require __DIR__.'/settings.php';

Route::group(['prefix' => 'laravel-filemanager', 'middleware' => ['web', 'auth']], function () {
    Lfm::routes();
});
