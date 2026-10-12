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
    'landed cost' => ['/landedcost', 'Landed Cost', null],
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
    'portal requests' => ['/portalrequests', 'Portal Requests', 'table'],
    'custom attributes' => ['/productattribute', 'Custom Attributes', 'table'],
    'product discount rules' => ['/productdiscountrule', 'Product Discount Rules', 'table'],
    'warranty claims' => ['/warrantyclaim', 'Warranty Claims', 'table'],
    'consignment stock' => ['/consignment', 'Consignment Stock', 'table'],
    'kits and bom' => ['/productbom', 'Kits & BOM', 'table'],
    'opening stock' => ['/openingstock', 'Opening Stock', 'table'],
    'stock reservations' => ['/stockreservation', 'Stock Reservations', 'table'],
    'pick pack put-away' => ['/warehousetask', 'Pick, Pack & Put-away', 'table'],
    'damage expired wastage' => ['/stockwriteoff', 'Damage, Expired & Wastage', 'table'],
    'inventory close' => ['/inventoryclose', 'Inventory Close', 'table'],
    'abc analysis report' => ['/report/abc-analysis', 'ABC Analysis', 'table'],
    'stock ageing report' => ['/report/stock-ageing', 'Stock Ageing', 'table'],
    'purchase approval rules' => ['/purchaseapprovalrule', 'Purchase Approval Rules', 'table'],
    'reorder suggestions' => ['/reordersuggestion', 'Reorder Suggestions', 'table'],
    'import purchases' => ['/importpurchase', 'Import Purchases', 'table'],
    'quality inspection' => ['/qualityinspection', 'Quality Inspection', 'table'],
    'supplier payments' => ['/supplierpayment', 'Supplier Payments', 'table'],
    'inbound shipments' => ['/inboundshipment', 'Inbound Shipments', 'table'],
    'supplier returns rma' => ['/rma', 'Supplier Returns (RMA)', 'table'],
    'supplier price comparison report' => ['/report/supplier-price-comparison', 'Supplier Price Comparison', 'table'],
    'quotations' => ['/quotation', 'Quotations & Proforma', 'table'],
    'sales orders' => ['/salesorder', 'Sales Orders', 'table'],
    'store credit' => ['/storecredit', 'Store Credit & Refunds', 'table'],
    'cheque management' => ['/chequemanagement', 'Cheque Management', 'table'],
    'cashiers' => ['/cashier', 'Cashiers', 'table'],
    'riders' => ['/rider', 'Riders', 'table'],
    'dispatch board' => ['/dispatch', 'Dispatch Board', 'table'],
    'sales kpi' => ['/saleskpi', 'Sales KPI & Commission', 'table'],
    'sales routes' => ['/salesroute', 'Sales Routes', 'table'],
    'recurring invoices' => ['/recurringinvoice', 'Recurring Invoices', 'table'],
    'installments' => ['/installment', 'Installments & Layaway', 'table'],
    'ecommerce sync' => ['/ecommerce', 'E-commerce Sync', 'table'],
    'messaging settings' => ['/messagingsetting', 'Messaging Settings', null],
    'import sales' => ['/salesimport', 'Import Sales', 'textarea'],
    'petty cash' => ['/pettycash', 'Petty Cash', 'table'],
    'cheque printing' => ['/chequeprint', 'Cheque Printing', 'table'],
    'guarantees and lcs' => ['/bankinstrument', 'Guarantees & Letters of Credit', 'table'],
    'loans' => ['/loan', 'Loans & Financing', 'table'],
    'tax payments' => ['/taxpayment', 'Tax Payments', 'table'],
    'payment terms' => ['/paymentterm', 'Payment Terms', 'table'],
    'advance allocation' => ['/allocation', 'Advance Allocation', null],
    'cash flow forecast' => ['/cashflowforecast', 'Cash Flow Forecast', 'table'],
    'year end close' => ['/yearclose', 'Year-End Close', 'table'],
    'inventory valuation' => ['/valuation', 'Inventory Valuation', 'table'],
    'journal schedules' => ['/journalschedule', 'Journal Schedules', 'table'],
    'bad debt' => ['/baddebt', 'Bad Debt', 'table'],
    'dunning and collections' => ['/dunning', 'Dunning & Collections', 'table'],
    'projects' => ['/project', 'Projects', 'table'],
    'foreign currency' => ['/foreigncurrency', 'Foreign Currency', 'table'],
    'inter-branch transfers' => ['/interbranch', 'Inter-Branch Transfers', 'table'],
    'offline pos sales' => ['/posoffline', 'Offline POS Sales', 'table'],
    'offline pos terminal' => ['/sell/pos/offline', 'Offline POS', 'table'],
    'approval center' => ['/approvalcenter', 'Approval Center', 'table'],
    'document center' => ['/documentcenter', 'Document Center', null],
    'business settings' => ['/businesssettings', 'Business Settings', null],
    'setup wizard' => ['/setup', 'Setup Wizard', null],
    'help center' => ['/help', 'Help Center', null],
    'franchise overview' => ['/franchise', 'Franchise Overview', null],
    'analytics' => ['/analytics', 'Analytics', null],
    'report builder' => ['/reportbuilder', 'Report Builder', null],
    'comparative profit and loss' => ['/report/profit-loss-comparative', 'Comparative Profit & Loss', null],
    'indirect cash flow' => ['/report/cash-flow-indirect', 'Cash Flow Statement (Indirect)', null],
    'sales by salesperson' => ['/report/sales-by-salesperson', 'Sales by Salesperson', 'table'],
    'profit by customer' => ['/report/profit-by-customer', 'Profit by Customer', 'table'],
    'tax liability' => ['/report/tax-liability', 'Tax Liability Reconciliation', null],
    'hr and payroll' => ['/hrm', 'HR and Payroll', null],
    'payroll register' => ['/report/payroll-register', 'Payroll Register', null],
    'statutory liability' => ['/report/statutory-liability', 'Statutory Liability', null],
    'attendance summary' => ['/report/attendance-summary', 'Attendance Summary', null],
    'loan balances' => ['/report/loan-balances', 'Employee Loan Balances', null],
    'manufacturing' => ['/manufacturing', 'Manufacturing', null],
    'production summary' => ['/report/production-summary', 'Production Summary', null],
    'work in progress' => ['/report/wip-report', 'Work in Progress', null],
    'material variance' => ['/report/material-variance', 'Material Variance', null],
    'production scrap' => ['/report/scrap-report', 'Production Scrap', null],
    'stock take variance' => ['/report/stock-take', 'Stock Take Variance', 'table'],
    'advances held' => ['/report/advance', 'Advances Held', 'table'],
    'tenant billing' => ['/tenant-billing', 'Subscription & Billing', 'table'],
    'integrations' => ['/integrations', 'Integrations', 'table'],
    'support tickets' => ['/support', 'Support Tickets', null],
    'security center' => ['/security', 'Security Center', 'table'],
    'lead allocation' => ['/leadallocation', 'Lead Allocation', 'table'],
    'subscription billing' => ['/subscriptionbilling', 'Subscription Billing', 'table'],
    'my notifications' => ['/my-notifications', 'My Notifications', null],
    'dashboard insights' => ['/dashboard/insights', 'Dashboard Insights', 'table'],
]);

dataset('platform pages', [
    'subscription plans' => ['/subscriptionplans', 'Subscription Plans', 'table'],
    'tenant directory' => ['/tenants', 'Tenant Directory', 'table'],
    'subscription invoices' => ['/subscriptioninvoices', 'Subscription Invoices', 'table'],
    'coupons' => ['/coupons', 'Coupons', 'table'],
    'system center' => ['/systemcenter', 'System Center', '.nav-tabs'],
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
