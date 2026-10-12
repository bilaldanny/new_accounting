<?php

use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;

/**
 * Task D in a real browser: the offline terminal is opened online (the service worker keeps the page and the catalog is saved to
 * IndexedDB), the network is switched off at the browser, a sale is rung up and printed with a provisional number, the network
 * comes back and the sale is replayed once to the server.
 *
 * Run with the inline environment (see tests/DuskTestCase.php): the server and database are throw-away.
 */
function offlineNetwork(Browser $browser, bool $offline): void
{
    $browser->driver->executeCustomCommand('/session/:sessionId/chromium/network_conditions', 'POST', [
        'network_conditions' => ['offline' => $offline, 'latency' => 0, 'download_throughput' => -1, 'upload_throughput' => -1],
    ]);
}

test('a sale rung up with the network off is kept on the device and posted once when the network returns', function () {
    $world = $this->world();
    $cash = insertPurchaseChartAccount($world, '111-00777', 'Dusk Till Cash', 'dr', false);
    insertPurchaseAccountMapping($world, 'Cash', 'cash', $cash);
    DB::table('product_details')->where('id', $world['variation_id'])->update(['default_sell_price' => 75]);
    DB::table('products')->where('id', $world['product_id'])->update(['active' => 1]);
    $sales = Transaction::query()->where('type', 'sell')->count();
    $payments = Payment::query()->count();

    $this->browse(function (Browser $browser) use ($world, $sales) {
        $this->signIn($browser, $this->adminUser())->visit('/sell/pos/offline');
        $browser->waitFor('@offline-pos', 30)->waitForTextIn('@catalog-note', 'Catalog saved', 30)->assertSeeIn('@network-state', 'Online');

        // the service worker takes over and keeps the page and its files; reload once so the page itself is cached
        $browser->waitUsing(30, 1, fn (): bool => (bool) $browser->driver->executeScript('return navigator.serviceWorker.controller !== null || document.readyState === "complete";'));
        $browser->driver->executeAsyncScript('const done = arguments[arguments.length - 1]; navigator.serviceWorker.ready.then(() => done(true));');
        $browser->refresh()->waitFor('@offline-pos', 30);
        $browser->waitUsing(30, 1, fn (): bool => (bool) $browser->driver->executeAsyncScript('const done = arguments[arguments.length - 1]; caches.keys().then(async (keys) => { let n = 0; for (const k of keys) { n += (await (await caches.open(k)).keys()).length; } done(n > 3); });'));

        offlineNetwork($browser, true);
        $browser->refresh()->waitFor('@offline-pos', 30)->waitUsing(20, 1, fn (): bool => str_contains((string) $browser->text('@network-state'), 'Offline'));
        $browser->waitFor('@product-'.$world['variation_id'], 20)->click('@product-'.$world['variation_id']);
        $browser->assertSeeIn('@cart-total', '75.00')->click('@complete-sale')->waitFor('@receipt', 20);

        $receipt = trim((string) $browser->text('@receipt-no'));
        expect($receipt)->toStartWith('OFF-');
        $browser->assertSeeIn('@waiting-count', '1 waiting');

        // nothing reached the server while the network was off
        expect(Transaction::query()->where('type', 'sell')->count())->toBe($sales);

        offlineNetwork($browser, false);
        $browser->waitUsing(45, 1, fn (): bool => str_contains((string) $browser->text('@waiting-count'), '0 waiting'));
        $browser->waitForTextIn('@queue-table', 'Synced', 30);
        // the app's own background polling fails while the network is off, by design; anything else is a real error
        $errors = collect($browser->driver->manage()->getLog('browser'))
            ->filter(fn (array $entry): bool => $entry['level'] === 'SEVERE')
            ->map(fn (array $entry): string => (string) $entry['message'])
            ->reject(fn (string $message): bool => str_contains($message, 'ERR_INTERNET_DISCONNECTED') || str_contains($message, 'Failed to fetch') || str_contains($message, 'favicon'))
            ->values()->all();
        $this->assertSame([], $errors, 'The terminal logged browser errors.');

        $row = DB::table('pos_offline_sales')->where('offline_receipt_no', $receipt)->first();
        expect($row)->not->toBeNull()->and($row->status)->toBe('synced')->and($row->invoice_no)->not->toBeNull()->and($row->oversold)->not->toBeNull();
    });

    expect(Transaction::query()->where('type', 'sell')->count())->toBe($sales + 1)
        ->and(Payment::query()->count())->toBe($payments + 1)
        ->and(DB::table('pos_offline_sales')->count())->toBe(1);
});
