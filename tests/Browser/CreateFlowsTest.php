<?php

use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;

/**
 * One real "create" through the screen for the main new pages: type into the form, press save, see the record in the list
 * and in the database.
 */
test('a lead can be created from the Leads form', function () {
    $name = 'Dusk Lead '.uniqid();

    $this->browse(function (Browser $browser) use ($name) {
        $this->signIn($browser, $this->adminUser())->visit('/leads/add')->waitFor('input[name=name]', 30);
        duskTypeInto($browser, 'input[name=name]', 0, $name);
        duskTypeInto($browser, 'input[name=email]', 0, 'dusk-lead@example.com');
        duskClickText($browser, 'Save & Close');

        $browser->waitForLocation('/leads', 20)->waitForText($name, 20)->assertSee($name);
        $this->assertNoBrowserErrors($browser);
    });

    expect(DB::table('leads')->where('name', $name)->exists())->toBeTrue();
});

test('an opportunity can be created from the Opportunities form', function () {
    $name = 'Dusk Deal '.uniqid();

    $this->browse(function (Browser $browser) use ($name) {
        $this->signIn($browser, $this->adminUser())->visit('/opportunities/add')->waitFor('input[name=name]', 30);
        duskTypeInto($browser, 'input[name=name]', 0, $name);
        duskClickText($browser, 'Save & Close');

        $browser->waitForLocation('/opportunities', 20)->waitForText($name, 20)->assertSee($name);
    });

    expect(DB::table('opportunities')->where('name', $name)->exists())->toBeTrue();
});

test('an asset category can be created with its three accounts', function () {
    $world = $this->world();
    $asset = insertPurchaseChartAccount($world, '120-90001', 'Dusk Equipment', 'dr', false);
    $accumulated = insertPurchaseChartAccount($world, '120-90002', 'Dusk Accumulated Depreciation', 'cr', false);
    $expense = insertPurchaseChartAccount($world, '430-90003', 'Dusk Depreciation Expense', 'dr', true);
    $name = 'Dusk Category '.uniqid();

    $this->browse(function (Browser $browser) use ($asset, $accumulated, $expense, $name) {
        $this->signIn($browser, $this->adminUser())->visit('/assetcategory')->waitForText('Asset Categories', 30);
        duskClickText($browser, 'New category');
        duskTypeInto($browser, duskTextInputs(), 0, $name);
        duskTypeInto($browser, 'form input[type=number]', 0, '60');
        duskSelectOption($browser, 'form select', 1, (string) $asset);
        duskSelectOption($browser, 'form select', 2, (string) $accumulated);
        duskSelectOption($browser, 'form select', 3, (string) $expense);
        duskClickText($browser, 'Save category');

        $browser->waitForText($name, 20)->assertSee($name);
        $this->assertNoBrowserErrors($browser);
    });

    expect(DB::table('asset_categories')->where('name', $name)->exists())->toBeTrue();
});

test('a cost center can be created', function () {
    $code = 'DK'.random_int(1000, 9999);

    $this->browse(function (Browser $browser) use ($code) {
        $this->signIn($browser, $this->adminUser())->visit('/costcenter')->waitForText('Cost Centers', 30);
        duskClickText($browser, 'New cost center');
        duskTypeInto($browser, duskTextInputs(), 0, $code);
        duskTypeInto($browser, duskTextInputs(), 1, 'Dusk Cost Center');
        duskClickText($browser, 'Save cost center');

        $browser->waitForText($code, 20)->assertSee($code);
    });

    expect(DB::table('cost_centers')->where('code', $code)->exists())->toBeTrue();
});

test('a warehouse and then a location inside it can be created', function () {
    $name = 'Dusk Warehouse '.uniqid();

    $this->browse(function (Browser $browser) use ($name) {
        $this->signIn($browser, $this->adminUser())->visit('/warehouse/add')->waitFor('input[placeholder="Enter warehouse name*"]', 30);
        duskTypeInto($browser, 'input[placeholder="Enter warehouse name*"]', 0, $name);
        duskClickText($browser, 'Save & Close');
        $browser->waitForLocation('/warehouse', 20)->waitForText($name, 20);

        $warehouseId = (string) DB::table('warehouses')->where('name', $name)->value('id');
        $browser->visit('/warehouselocation')->waitForText('Warehouse Locations', 30);
        duskClickText($browser, 'New location');
        duskSelectOption($browser, 'form select', 0, $warehouseId);
        duskTypeInto($browser, duskTextInputs(), 0, 'Z-DK');
        duskTypeInto($browser, duskTextInputs(), 1, 'Dusk Zone');
        duskClickText($browser, 'Save location');

        $browser->waitForText('Dusk Zone', 20)->assertSee('Dusk Zone');
        $this->assertNoBrowserErrors($browser);
    });

    expect(DB::table('warehouse_locations')->where('name', 'Dusk Zone')->exists())->toBeTrue();
});

test('a tax exemption can be created for the customer', function () {
    $world = $this->world();
    $name = 'Dusk Exemption '.uniqid();

    $this->browse(function (Browser $browser) use ($world, $name) {
        $this->signIn($browser, $this->adminUser())->visit('/taxexemption')->waitForText('Tax Exemptions', 30);
        duskClickText($browser, 'New exemption');
        duskTypeInto($browser, duskTextInputs(), 0, $name);
        duskSelectOption($browser, 'form select', 1, (string) $world['customer_id']);
        duskClickText($browser, 'Save exemption');

        $browser->waitForText($name, 20)->assertSee($name);
    });

    expect(DB::table('tax_exemptions')->where('name', $name)->exists())->toBeTrue();
});

test('a batch can be registered for a batch tracked product and appears in the list', function () {
    $world = $this->world();
    DB::table('products')->where('id', $world['product_id'])->update(['tracking_type' => 'batch']);
    $batch = 'DK-'.random_int(1000, 9999);

    $this->browse(function (Browser $browser) use ($world, $batch) {
        $this->signIn($browser, $this->adminUser())->visit('/stocktracking')->waitForText('Serials & Batches', 30);
        duskClickText($browser, 'Batches & expiry');
        duskClickText($browser, 'Register batch');
        duskSelectOption($browser, 'select', 2, (string) $world['product_id']);
        duskSelectOption($browser, 'select', 3, (string) $world['branch_id']);
        duskTypeInto($browser, 'input[type=number]', 0, '5');
        $browser->waitFor('input[type=date]', 10);
        duskTypeInto($browser, 'form input:not([type])', 0, $batch);
        duskClickText($browser, 'Register');

        $browser->waitForText($batch, 20)->assertSee($batch);
        $this->assertNoBrowserErrors($browser);
    });

    expect(DB::table('stock_batches')->where('batch_no', $batch)->exists())->toBeTrue();
});

test('a coupon and a subscription plan can be created by the superadmin', function () {
    $this->world();
    $code = 'DUSK'.random_int(100, 999);
    $plan = 'DUSKPLAN'.random_int(100, 999);

    $this->browse(function (Browser $browser) use ($code, $plan) {
        $this->signIn($browser, $this->superadmin())->visit('/coupons/add')->waitFor('input[name=code]', 30);
        duskTypeInto($browser, 'input[name=code]', 0, $code);
        duskTypeInto($browser, 'input[name=value]', 0, '10');
        duskClickText($browser, 'Save & Close');
        $browser->waitForLocation('/coupons', 20)->waitForText($code, 20)->assertSee($code);

        $browser->visit('/subscriptionplans/add')->waitFor('input[name=name]', 30);
        duskTypeInto($browser, 'input[name=name]', 0, 'Dusk Plan');
        duskTypeInto($browser, 'input[name=code]', 0, $plan);
        duskTypeInto($browser, 'input[name=price]', 0, '1500');
        duskClickText($browser, 'Save & Close');
        $browser->waitForLocation('/subscriptionplans', 20)->waitForText('Dusk Plan', 20)->assertSee('Dusk Plan');
    });

    expect(DB::table('coupons')->where('code', $code)->exists())->toBeTrue()
        ->and(DB::table('subscription_plans')->where('code', $plan)->exists())->toBeTrue();
});
