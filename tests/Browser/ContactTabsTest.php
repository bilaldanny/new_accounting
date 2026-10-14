<?php

use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;

/**
 * The tabs of a customer page in a real browser: the ledger has its PDF, email and WhatsApp buttons, and Sales, Stock, Payments and
 * Documents show real data (the file attached to a sale is listed under Documents).
 */
test('the customer page tabs show sales, stock, payments and the files attached to sales', function () {
    $world = $this->world();
    $sale = createSellRecord($world, ['invoice_no' => 'TAB-'.random_int(1000, 9999), 'final_amount' => 900, 'transaction_date' => now()->toDateString(), 'attachment' => 'signed-bill-'.random_int(100, 999).'.pdf']);
    DB::table('payments')->insert([
        'transaction_id' => $sale->id, 'contact_id' => $world['contact_id'], 'amount' => 300, 'method' => 'cash', 'paid_on' => now()->toDateString(), 'is_return' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->browse(function (Browser $browser) use ($world, $sale) {
        $this->signIn($browser, $this->adminUser())->visit('/customer/'.$world['contact_id'].'/view')->waitForText('Account ledger', 40);

        // the ledger: the statement buttons, and the send panel opens with the party's own number
        $browser->waitFor('[data-test="ledger-pdf"]', 20)->assertPresent('[data-test="ledger-email"]')->assertPresent('[data-test="ledger-whatsapp"]');
        $browser->script('document.querySelector("[data-test=\\"ledger-whatsapp\\"]").scrollIntoView({block: "center"});');
        $browser->screenshot('contact-ledger-actions');
        $browser->click('[data-test="ledger-whatsapp"]')->waitFor('[data-test="ledger-send-panel"]', 10);
        expect($browser->value('#ledger-recipient'))->toBe('03007654321');

        // sales
        duskClickText($browser, 'Sales');
        $browser->waitFor('[data-test="contact-orders"]', 20)->waitForText($sale->invoice_no, 20)->assertSee('600.00');

        // payments
        duskClickText($browser, 'Payments');
        $browser->waitFor('[data-test="contact-payments"]', 20)->waitForText($sale->invoice_no, 20)->assertSee('300.00');

        // stock
        duskClickText($browser, 'Stock');
        $browser->waitFor('[data-test="contact-stock"]', 20)->waitForText('Products sold to this customer', 20);

        // documents: the file attached to the sale
        duskClickText($browser, 'Documents');
        $browser->waitFor('[data-test="attached-files"]', 20)->waitForText('Attached to sales', 20)->waitForText($sale->invoice_no, 20)->assertSee('.pdf');

        $this->assertNoBrowserErrors($browser);
    });
});
