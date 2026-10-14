<?php

use Facebook\WebDriver\WebDriverKeys;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;

/**
 * The POS with a barcode scanner (a keyboard that types a code and presses Enter) and with the keyboard only: the search box is
 * focused when the till opens, an extra barcode adds its product, the same code again adds one more, a scan works with nothing
 * focused, an unknown code is said so, and the function keys open the key list and move to the search box.
 */
test('a scanned barcode adds its product, scanning again adds one more, and the keyboard alone drives the till', function () {
    $world = $this->world();
    $code = '8964000'.random_int(100000, 999999);
    DB::table('products')->where('id', $world['product_id'])->update(['active' => 1]);
    DB::table('product_barcodes')->insert(['company_id' => $world['company_id'], 'product_id' => $world['product_id'], 'variation_id' => null, 'barcode' => $code, 'label' => 'Carton', 'created_at' => now(), 'updated_at' => now()]);
    $purchase = DB::table('transactions')->insertGetId([
        'company_id' => $world['company_id'], 'branch_id' => $world['branch_id'], 'contact_id' => $world['contact_id'], 'invoice_no' => 'SCAN-'.uniqid(), 'type' => 'purchaseorder',
        'status' => 'received', 'payment_status' => 'due', 'transaction_date' => now()->subDay()->toDateString(), 'final_amount' => 1000, 'total_item' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('purchase_lines')->insert([
        'transaction_id' => $purchase, 'product_id' => $world['product_id'], 'variation_id' => $world['variation_id'], 'unit_id' => $world['unit_id'], 'quantity' => 50, 'quantity_received' => 50,
        'qunatity_sold' => 0, 'quantity_returned' => 0, 'quantity_adjustment' => 0, 'purchase_rate' => 10, 'pp_without_discount' => 10, 'discount_percent' => 0, 'packing_qty' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->browse(function (Browser $browser) use ($code) {
        $this->signIn($browser, $this->adminUser())->visit('/sell/pos/add')->waitFor('[data-test="pos-search"]', 40);

        // the till opens with the cursor in the search box
        $browser->waitUsing(10, 1, fn (): bool => (bool) $browser->driver->executeScript('return document.activeElement && document.activeElement.getAttribute("data-test") === "pos-search";'));

        // a scan into the search box: the extra barcode of the product
        $browser->type('[data-test="pos-search"]', $code)->keys('[data-test="pos-search"]', '{enter}');
        $browser->waitForTextIn('[data-test="pos-scan-status"]', 'added', 20);
        $browser->assertPresent('.pos-cart-line');
        expect($browser->driver->executeScript('return document.querySelectorAll(".pos-cart-line").length;'))->toBe(1);

        // a scan with no field focused (the scanner is not aimed): same product, so one more of it, not a second line
        $browser->driver->executeScript('document.activeElement.blur();');
        $browser->driver->getKeyboard()->sendKeys($code.WebDriverKeys::ENTER);
        $browser->waitUsing(15, 1, fn (): bool => (string) $browser->driver->executeScript('return document.querySelector(".pos-qty-stepper input").value;') === '2');
        expect($browser->driver->executeScript('return document.querySelectorAll(".pos-cart-line").length;'))->toBe(1);

        // an unknown code is said so
        $browser->type('[data-test="pos-search"]', '0000000000000')->keys('[data-test="pos-search"]', '{enter}');
        $browser->waitForTextIn('[data-test="pos-scan-status"]', 'No product has the code', 20);

        // the keyboard: F1 lists the keys, F2 goes back to the search box
        $browser->driver->executeScript('document.activeElement.blur();');
        $browser->driver->getKeyboard()->sendKeys(WebDriverKeys::F1);
        $browser->waitFor('[data-test="pos-shortcuts"]', 10);
        $browser->driver->getKeyboard()->sendKeys(WebDriverKeys::F2);
        $browser->waitUsing(10, 1, fn (): bool => (bool) $browser->driver->executeScript('return document.activeElement && document.activeElement.getAttribute("data-test") === "pos-search";'));

        $this->assertNoBrowserErrors($browser);
    });
});
