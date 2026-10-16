<?php

use App\Services\StockTracking;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;

/**
 * A serial tracked product scanned at the till asks for its serial numbers (typed or scanned) before the sale, refuses to go on
 * without one per piece, and asks again when the quantity goes up.
 */
test('scanning a serial tracked product asks for its serial numbers', function () {
    $world = $this->world();
    $code = '8965000'.random_int(100000, 999999);
    DB::table('products')->where('id', $world['product_id'])->update(['active' => 1, 'tracking_type' => 'serial']);
    DB::table('product_barcodes')->insert(['company_id' => $world['company_id'], 'product_id' => $world['product_id'], 'variation_id' => null, 'barcode' => $code, 'label' => 'Unit', 'created_at' => now(), 'updated_at' => now()]);
    $purchase = DB::table('transactions')->insertGetId([
        'company_id' => $world['company_id'], 'branch_id' => $world['branch_id'], 'contact_id' => $world['contact_id'], 'invoice_no' => 'TRK-'.uniqid(), 'type' => 'purchaseorder',
        'status' => 'received', 'payment_status' => 'due', 'transaction_date' => now()->subDay()->toDateString(), 'final_amount' => 1000, 'total_item' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('purchase_lines')->insert([
        'transaction_id' => $purchase, 'product_id' => $world['product_id'], 'variation_id' => $world['variation_id'], 'unit_id' => $world['unit_id'], 'quantity' => 20, 'quantity_received' => 20,
        'qunatity_sold' => 0, 'quantity_returned' => 0, 'quantity_adjustment' => 0, 'purchase_rate' => 10, 'pp_without_discount' => 10, 'discount_percent' => 0, 'packing_qty' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    StockTracking::forgetSchemaMemo();
    app(StockTracking::class)->registerSerials((int) $world['company_id'], (int) $world['product_id'], null, (int) $world['branch_id'], ['SN-A1', 'SN-A2', 'SN-A3'], 'dusk');

    // the world is shared by the browser tests: the product goes back to untracked when this test ends, pass or fail
    $this->beforeApplicationDestroyed(function () use ($world) {
        DB::table('products')->where('id', $world['product_id'])->update(['tracking_type' => 'none']);
    });

    $this->browse(function (Browser $browser) use ($code) {
        $this->signIn($browser, $this->adminUser())->visit('/sell/pos/add')->waitFor('[data-test="pos-search"]', 40);
        $browser->type('[data-test="pos-search"]', $code)->keys('[data-test="pos-search"]', '{enter}');
        $browser->waitFor('[data-test="pos-tracking"]', 20)->assertSee('Serial numbers')->assertSee('1 serial number needed');

        // Done is refused with no serial typed
        $browser->click('[data-test="pos-tracking-done"]');
        $browser->assertPresent('[data-test="pos-tracking"]');

        // a serial typed (or scanned, which ends in Enter) fills the line
        $browser->type('.tracking-input textarea, [data-test="pos-tracking"] textarea', "SN-A1\n");
        $browser->waitForText('(1 of 1)', 10);
        $browser->click('[data-test="pos-tracking-done"]')->waitUntilMissing('[data-test="pos-tracking"]', 10);

        // + on the line asks for one more serial
        $browser->driver->executeScript('document.activeElement.blur();');
        $browser->driver->getKeyboard()->sendKeys('+');
        $browser->waitFor('[data-test="pos-tracking"]', 10)->assertSee('2 serial numbers needed');

        $this->assertNoBrowserErrors($browser);
    });
});
