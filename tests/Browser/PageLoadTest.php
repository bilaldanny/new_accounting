<?php

use Laravel\Dusk\Browser;

/**
 * Every page added in the last months opens in a real browser for a company user, shows its heading and its main element
 * (a table or a form) and logs no JavaScript error or failed request of ours.
 */
dataset('company pages', [
    'leads' => ['/leads', 'Leads', 'table'],
    'pipeline board' => ['/pipeline', 'Pipeline Board', null],
    'opportunities' => ['/opportunities', 'Opportunities', 'table'],
    'activities' => ['/activities', 'Activities', 'table'],
    'webhooks' => ['/webhooks', 'Webhooks', 'table'],
    'api logs' => ['/apilogs', 'API Logs', 'table'],
    'customer subscription plans' => ['/customersubscriptionplans', 'Subscription Plans', 'table'],
    'customer subscriptions' => ['/customersubscriptions', 'Customer Subscriptions', 'table'],
    'fixed assets' => ['/fixedasset', 'Fixed Assets', 'form'],
    'asset categories' => ['/assetcategory', 'Asset Categories', null],
    'depreciation' => ['/depreciation', 'Depreciation', 'form'],
    'cost centers' => ['/costcenter', 'Cost Centers', 'form'],
    'budgets' => ['/budget', 'Budgets', 'form'],
    'fixed asset approval' => ['/assetapproval', 'Fixed Asset Approval', 'form'],
    'bank reconciliation' => ['/bankreconciliation', 'Bank Reconciliation', 'form'],
    'landed cost' => ['/landedcost', 'Landed Cost', 'table'],
    'bulk price update' => ['/bulkpriceupdate', 'Bulk Price Update', 'form'],
    'warehouse stock report' => ['/report/warehouse-stock', 'Warehouse Stock', 'table'],
    'stock movement history' => ['/report/stock-movement-history', 'Stock Movement History', 'table'],
    'warehouse usage report' => ['/report/warehouse-usage', 'Warehouse Capacity & Usage', 'table'],
    'warehouse locations' => ['/warehouselocation', 'Warehouse Locations', null],
    'tax exemptions' => ['/taxexemption', 'Tax Exemptions', 'form'],
    'serials and batches' => ['/stocktracking', 'Serials & Batches', 'form'],
    'serial traceability report' => ['/report/serial-traceability', 'Serial / IMEI Traceability Log', 'table'],
    'batch expiry report' => ['/report/batch-expiry', 'Batch Expiry Report', 'table'],
    'budget vs actual report' => ['/report/budget-vs-actual', 'Budget vs Actual', 'table'],
    'cost center analysis report' => ['/report/cost-center-analysis', 'Cost Center & Department Analysis', 'table'],
    'purchase return approval' => ['/purchasereturn/approval', 'Purchase Return Approval', 'form'],
    'stock adjustment approval' => ['/stockadjustment/approval', 'Stock Adjustment Approval', 'form'],
    'stock transfer approval' => ['/stocktransfer/approval', 'Stock Transfer Approval', 'form'],
    'cash collection approval' => ['/cashcollection/approval', 'Cash Collection Approval', 'form'],
    'price list approval' => ['/pricelist/approval', 'Price List Approval', 'form'],
    'credit limit approval' => ['/creditlimit/approval', 'Credit Limit Approval', 'form'],
    'duplicate contacts' => ['/contacts/duplicates', 'Duplicate Contacts', null],
    'activity log' => ['/auditlogs', 'Activity Log', 'table'],
    'pos shifts' => ['/posshift', 'POS Shifts', null],
]);

dataset('platform pages', [
    'subscription plans' => ['/subscriptionplans', 'Subscription Plans', 'table'],
    'tenant directory' => ['/tenants', 'Tenant Directory', 'table'],
    'subscription invoices' => ['/subscriptioninvoices', 'Subscription Invoices', 'table'],
    'coupons' => ['/coupons', 'Coupons', 'table'],
]);

test('a company page opens with its heading and main element and without browser errors', function (string $path, string $heading, ?string $selector) {
    $this->browse(function (Browser $browser) use ($path, $heading, $selector) {
        $this->signIn($browser, $this->adminUser())
            ->visit($path)
            ->waitForText($heading, 30)
            ->assertSee($heading)
            ->assertDontSee('Server Error')
            ->assertDontSee('You do not have permission');

        if ($selector !== null) {
            $browser->waitFor($selector, 20)->assertPresent($selector);
        }

        $this->assertNoBrowserErrors($browser);
    });
})->with('company pages');

test('a platform page opens for the superadmin with its heading and table and without browser errors', function (string $path, string $heading, string $selector) {
    $this->browse(function (Browser $browser) use ($path, $heading, $selector) {
        $this->world();

        $this->signIn($browser, $this->superadmin())
            ->visit($path)
            ->waitForText($heading, 30)
            ->assertSee($heading)
            ->assertDontSee('Server Error')
            ->waitFor($selector, 20)
            ->assertPresent($selector);

        $this->assertNoBrowserErrors($browser);
    });
})->with('platform pages');

test('the company settings Tax tab opens with its registration card', function () {
    $this->browse(function (Browser $browser) {
        $this->signIn($browser, $this->adminUser())->visit('/company/setting')->waitForText('Business Setting', 30);
        duskClickText($browser, 'Tax');
        $browser->waitFor('[data-test="tax-registration-card"]', 20)->assertPresent('[data-test="tax-registration-card"]');

        $this->assertNoBrowserErrors($browser);
    });
});
