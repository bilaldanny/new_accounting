<?php

use App\Models\Contact;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;

/**
 * Offline mode in a real browser (design: docs/offline-mode-design.md): every kind of entry is made with the network switched off at the
 * browser, kept on the device with a provisional number, and sent exactly once when the network is back; a refusal waits for a decision;
 * signing out asks first; another user never sees the queue; a page that needs the internet says so kindly.
 *
 * Run with the inline environment (see tests/DuskTestCase.php): the server and database are throw-away.
 */
function offlineModeNetwork(Browser $browser, bool $offline): void
{
    $browser->driver->executeCustomCommand('/session/:sessionId/chromium/network_conditions', 'POST', [
        'network_conditions' => ['offline' => $offline, 'latency' => 0, 'download_throughput' => -1, 'upload_throughput' => -1],
    ]);
}

/** @return array<string, int> */
function offlineModeWorld(array $world): array
{
    $cash = DB::table('chart_of_accounts')->where('company_id', $world['company_id'])->where('name', 'Dusk Offline Cash')->value('id')
        ?? insertPurchaseChartAccount($world, '111-00888', 'Dusk Offline Cash', 'dr', false);

    if (! DB::table('chart_of_account_mappings')->where('company_id', $world['company_id'])->where('key', 'cash')->exists()) {
        insertPurchaseAccountMapping($world, 'Cash', 'cash', $cash);
    }

    $expense = DB::table('chart_of_accounts')->where('company_id', $world['company_id'])->where('name', 'Dusk Offline Expense')->value('id')
        ?? insertPurchaseChartAccount($world, '601-00888', 'Dusk Offline Expense', 'dr', true);
    DB::table('products')->where('id', $world['product_id'])->update(['active' => 1]);

    return $world + ['cash_id' => (int) $cash, 'expense_id' => (int) $expense, 'supplier_id' => (int) DB::table('contacts')->where('company_id', $world['company_id'])->where('user_type', 'supplier')->value('id')];
}

/** Opens the Offline entries page online, waits until the reference data and the page itself are kept on the device. */
function offlineModePrime(Browser $browser): void
{
    // the browser is shared by the tests of a run: start from an empty device (no queue, no cached reference data)
    $browser->visit('/offline')->waitFor('[data-test="offline-form"]', 40);
    $browser->driver->executeAsyncScript('const done = arguments[arguments.length - 1]; const r = indexedDB.deleteDatabase("app-offline"); r.onsuccess = r.onerror = r.onblocked = () => done(true);');
    $browser->refresh()->waitFor('[data-test="offline-form"]', 40);
    $browser->waitUsing(40, 1, fn (): bool => ! str_contains((string) $browser->text('[data-test="offline-reference-age"]'), 'not loaded'));
    $browser->driver->executeAsyncScript('const done = arguments[arguments.length - 1]; navigator.serviceWorker.ready.then(() => done(true));');
    $browser->waitUsing(40, 1, fn (): bool => (bool) $browser->driver->executeAsyncScript(
        'const done = arguments[arguments.length - 1]; caches.keys().then(async (keys) => { let shell = false; let assets = 0; for (const k of keys) { for (const r of await (await caches.open(k)).keys()) { if (new URL(r.url).pathname === "/offline") { shell = true; } else { assets++; } } } done(shell && assets > 3); });'
    ));
}

/** Waits for the page's own dialog, answers it, and gives back what it said. */
function offlineModeDialog(Browser $browser, ?string $typed = null, bool $accept = true): string
{
    $deadline = microtime(true) + 15;

    do {
        try {
            $alert = $browser->driver->switchTo()->alert();
            $text = $alert->getText();

            if ($typed !== null) {
                $alert->sendKeys($typed);
            }

            $accept ? $alert->accept() : $alert->dismiss();

            return $text;
        } catch (Throwable) {
            usleep(200_000);
        }
    } while (microtime(true) < $deadline);

    throw new RuntimeException('The page opened no dialog.');
}

/** Waits until the entries table shows the text (read in one script call: the table redraws as entries change, so a held element goes stale). */
function offlineModeSees(Browser $browser, string $text): void
{
    $browser->waitUsing(20, 1, fn (): bool => str_contains((string) $browser->driver->executeScript('var t = document.querySelector("[data-test=offline-entries]"); return t ? t.innerText : "";'), $text), 'The entries table never showed: '.$text);
}

function offlineModePending(Browser $browser): string
{
    return trim((string) $browser->text('[data-test="offline-pending-count"]'));
}

function offlineModeSave(Browser $browser, int $expectedWaiting): void
{
    $browser->click('[data-test="offline-save"]');
    $browser->waitUsing(15, 1, fn (): bool => str_starts_with(offlineModePending($browser), $expectedWaiting.' waiting'));
}

test('every kind of entry made with the network off is kept on the device and sent exactly once', function () {
    $world = offlineModeWorld($this->world());
    $invoice = createSellRecord($world, ['invoice_no' => 'INV-DUSK-OFF', 'final_amount' => 120, 'contact_id' => $world['customer_id']]);
    $snapshot = kbSnapshot();
    DB::table('offline_sync_entries')->delete();
    $sells = Transaction::query()->where('type', 'sell')->count();
    $purchases = Transaction::query()->where('type', 'purchaseorder')->count();
    $contacts = Contact::query()->count();

    try {
        $this->browse(function (Browser $browser) use ($world, $invoice, $sells, $purchases, $contacts) {
            $this->signIn($browser, $this->adminUser());
            offlineModePrime($browser);
            offlineModeNetwork($browser, true);
            $browser->refresh()->waitFor('[data-test="offline-form"]', 40)->waitFor('[data-test="offline-chip"]', 20);
            $browser->waitUsing(20, 1, fn (): bool => str_contains((string) $browser->text('[data-test="offline-chip"]'), 'Offline'));

            // 1. a sales invoice paid on the spot
            $browser->select('#offline-kind', 'sale')->select('#offline-contact', (string) $world['customer_id'])->select('select[aria-label="Product 1"]', (string) $world['variation_id']);
            $browser->check('#offline-paynow')->select('#offline-account', (string) $world['cash_id']);
            offlineModeSave($browser, 1);

            // 2. a purchase on credit
            $browser->select('#offline-kind', 'purchase')->select('#offline-contact', (string) $world['supplier_id'])->select('select[aria-label="Product 1"]', (string) $world['variation_id']);
            offlineModeSave($browser, 2);

            // 3. a receipt against an existing invoice
            $browser->select('#offline-kind', 'payment')->select('#offline-invoice', (string) $invoice->id)->select('#offline-account', (string) $world['cash_id']);
            offlineModeSave($browser, 3);

            // 4. an expense
            $browser->select('#offline-kind', 'expense')->select('#offline-expense-account', (string) $world['expense_id'])->type('#offline-expense-amount', '55')->type('#offline-description', 'Dusk offline tea')->select('#offline-account', (string) $world['cash_id']);
            offlineModeSave($browser, 4);

            // 5. a new customer
            $browser->select('#offline-kind', 'contact')->type('#offline-first-name', 'Dusk')->type('#offline-name', 'Dusk Offline Customer')->type('#offline-mobile', '03001234000')->type('#offline-address', 'Street 9')->type('#offline-ntn', '9999999');
            offlineModeSave($browser, 5);

            // 6. a stock adjustment (sent as pending)
            $browser->select('#offline-kind', 'stock_adjustment')->select('select[aria-label="Product 1"]', (string) $world['variation_id'])->type('#offline-note', 'Dusk count');
            offlineModeSave($browser, 6);

            // kept on the device with provisional numbers; nothing reached the server
            offlineModeSees($browser, 'provisional');
            $refs = $browser->driver->executeScript('return Array.from(document.querySelectorAll("[data-test=offline-entries] [data-test=offline-ref]")).map((span) => span.textContent.trim());');
            expect($refs)->toHaveCount(6)->and(array_unique($refs))->toHaveCount(6);
            foreach ($refs as $ref) {
                expect($ref)->toStartWith('OFF-');
            }
            expect(DB::table('offline_sync_entries')->count())->toBe(0)
                ->and(Transaction::query()->where('type', 'sell')->count())->toBe($sells)
                ->and(Transaction::query()->where('type', 'purchaseorder')->count())->toBe($purchases);

            // the network is back: the engine sends them on its own
            offlineModeNetwork($browser, false);
            $browser->waitUsing(90, 1, fn (): bool => str_starts_with(offlineModePending($browser), '0 waiting') && DB::table('offline_sync_entries')->where('status', 'synced')->count() === 6);
            $browser->waitFor('[data-test="offline-entries"] [data-test="offline-status"][data-status="synced"]', 20);

            expect(Transaction::query()->where('type', 'sell')->count())->toBe($sells + 1)
                ->and(Transaction::query()->where('type', 'purchaseorder')->count())->toBe($purchases + 1)
                ->and(Contact::query()->count())->toBe($contacts + 1)
                ->and(DB::table('offline_sync_entries')->whereNotNull('real_ref')->count())->toBe(6)
                ->and(DB::table('t_accounts')->where('comments', 'like', '%Dusk offline tea%')->count())->toBe(1)
                ->and(Transaction::query()->findOrFail($invoice->id)->payment_status)->toBe('paid')
                ->and(Transaction::query()->where('additional_note', 'like', '%Dusk count%')->value('status'))->toBe('pending');

            // sending one again (a retried request) is recognised by its client id and posts nothing
            $entry = DB::table('offline_sync_entries')->where('kind', 'contact')->first();
            $again = duskRequest($browser, 'POST', '/api/offline/sync', ['client_uuid' => $entry->client_uuid, 'kind' => 'contact', 'payload' => json_decode($entry->payload, true)]);
            expect($again['status'])->toBe(200)->and($again['body']['repeat'])->toBeTrue()->and($again['body']['real_ref'])->toBe($entry->real_ref)
                ->and(Contact::query()->count())->toBe($contacts + 1)->and(DB::table('offline_sync_entries')->count())->toBe(6);
        });
    } finally {
        kbRestore($snapshot);
        DB::table('offline_sync_entries')->delete();
        DB::table('transactions')->where('id', $invoice->id)->delete();
    }
});

test('a refused entry waits for a decision: edit and retry posts it, discard is audited', function () {
    $world = offlineModeWorld($this->world());
    $snapshot = kbSnapshot();
    DB::table('offline_sync_entries')->delete();
    $sells = Transaction::query()->where('type', 'sell')->count();
    // a customer of its own with a small limit and no other invoices (the shared customer carries the invoices of other tests)
    $limited = Contact::query()->create(['company_id' => $world['company_id'], 'branch_id' => $world['branch_id'], 'business_name' => 'Dusk Limited Customer', 'first_name' => 'Lim', 'address' => 'Nowhere', 'ntn_number' => '2222222', 'mobile' => '0300111', 'user_type' => 'customer', 'type' => 'local', 'code' => 'CU-DUSKLIM', 'credit_limit' => 1000, 'active' => true]);
    insertPurchaseChartAccount($world, '101-00099', 'Dusk Limited Customer', 'dr', false);
    $limited->forceFill(['customer_gl_id' => '101-00099', 'gl_id' => '101-00099', 'link_account' => true])->save();
    $gone = Contact::query()->create(['company_id' => $world['company_id'], 'branch_id' => $world['branch_id'], 'business_name' => 'Dusk Gone Customer', 'first_name' => 'Gone', 'address' => 'Nowhere', 'ntn_number' => '1111111', 'mobile' => '0300000', 'user_type' => 'customer', 'type' => 'local', 'code' => 'CU-DUSKGONE', 'active' => true]);

    try {
        $this->browse(function (Browser $browser) use ($world, $sells, $gone, $limited) {
            $this->signIn($browser, $this->adminUser());
            offlineModePrime($browser);
            offlineModeNetwork($browser, true);
            $browser->refresh()->waitFor('[data-test="offline-form"]', 40);

            // too big for the customer's credit limit
            $browser->select('#offline-kind', 'sale')->select('#offline-contact', (string) $limited->id)->select('select[aria-label="Product 1"]', (string) $world['variation_id']);
            $browser->type('input[aria-label="Quantity 1"]', '50');
            offlineModeSave($browser, 1);

            // the customer of this one is deleted before it reaches the server
            $browser->select('#offline-kind', 'sale')->select('#offline-contact', (string) $gone->id)->select('select[aria-label="Product 1"]', (string) $world['variation_id']);
            offlineModeSave($browser, 2);
            DB::table('contacts')->where('id', $gone->id)->update(['deleted_at' => now()]);

            offlineModeNetwork($browser, false);
            $browser->waitUsing(90, 1, fn (): bool => DB::table('offline_sync_entries')->where('status', 'conflict')->count() === 2);
            $browser->waitFor('[data-test="offline-conflict"]', 30);
            expect(DB::table('offline_sync_entries')->pluck('conflict_code')->sort()->values()->all())->toBe(['credit_limit', 'product_missing'])
                ->and(Transaction::query()->where('type', 'sell')->count())->toBe($sells);
            offlineModeSees($browser, 'credit limit');

            // discard the deleted-product one (reason and confirmation asked, audit log written)
            $row = DB::table('offline_sync_entries')->where('conflict_code', 'product_missing')->first();
            $browser->driver->executeScript('const button = Array.from(document.querySelectorAll("[data-test=offline-entries] tr")).find((tr) => tr.textContent.includes(arguments[0])).querySelector("[data-test=offline-discard]"); setTimeout(() => button.click(), 400);', [$row->provisional_ref]);
            expect(offlineModeDialog($browser, 'Dusk: the customer is gone'))->toContain('discarded');
            expect(offlineModeDialog($browser))->toBe('Discard '.$row->provisional_ref.'? It will never be posted.');
            $browser->waitUsing(20, 1, fn (): bool => DB::table('offline_sync_entries')->where('id', $row->id)->value('status') === 'discarded');
            $browser->waitUsing(10, 1, fn (): bool => DB::table('audit_logs')->where('event', 'offline_discarded')->count() === 1);
            expect(DB::table('audit_logs')->where('event', 'offline_discarded')->count())->toBe(1);

            // edit the credit-limit one down to a size that fits, and send it again
            // the table and the form redraw as the entry changes: act through one script call each so no element is held across a redraw
            $browser->waitFor('[data-test="offline-edit"]', 20);
            $browser->driver->executeScript('document.querySelector("[data-test=offline-edit]").click();');
            $browser->waitUsing(10, 1, fn (): bool => (string) $browser->driver->executeScript('var i = document.querySelector("input[aria-label=\\"Quantity 1\\"]"); return i ? i.value : "";') === '50');
            $browser->driver->executeScript('var i = document.querySelector("input[aria-label=\\"Quantity 1\\"]"); i.value = "2"; i.dispatchEvent(new Event("input", { bubbles: true }));');
            $browser->driver->executeScript('document.querySelector("[data-test=offline-save]").click();');
            try {
                $browser->waitUsing(30, 1, fn (): bool => DB::table('offline_sync_entries')->where('conflict_code', null)->where('status', 'synced')->count() === 1);
            } catch (Throwable $e) {
                throw new RuntimeException('the edited entry was not sent: '.json_encode(DB::table('offline_sync_entries')->get(['status', 'conflict_code', 'error'])->all()), 0, $e);
            }
            expect(Transaction::query()->where('type', 'sell')->count())->toBe($sells + 1);
        });
    } finally {
        kbRestore($snapshot);
        DB::table('offline_sync_entries')->delete();
    }
});

test('signing out asks while entries wait, a forced sign-out keeps them for that user only, and an online-only page says so kindly', function () {
    $world = offlineModeWorld($this->world());
    $snapshot = kbSnapshot();
    DB::table('offline_sync_entries')->delete();
    $contacts = Contact::query()->count();
    $other = createStaffUserForRole(Role::query()->findOrFail($world['role_id']), ['company_id' => $world['company_id'], 'branch_id' => $world['branch_id'], 'first_name' => 'Second', 'last_name' => 'Cashier']);

    try {
        $this->browse(function (Browser $browser) use ($other, $contacts) {
            $this->signIn($browser, $this->adminUser());
            offlineModePrime($browser);
            offlineModeNetwork($browser, true);
            $browser->refresh()->waitFor('[data-test="offline-form"]', 40);
            $browser->select('#offline-kind', 'contact')->type('#offline-first-name', 'Queue')->type('#offline-name', 'Dusk Queue Customer')->type('#offline-address', 'Street 8')->type('#offline-mobile', '0300555')->type('#offline-ntn', '8888888');
            offlineModeSave($browser, 1);

            // an online-only page: a friendly screen, not a broken page
            $browser->driver->executeScript('document.querySelector("a[href$=\'/dashboard\']").click();');
            $browser->waitFor('[data-test="offline-blocked"]', 20)->assertSee('This page needs the internet')->assertSeeIn('[data-test="offline-blocked-pending"]', '1 entry is waiting');
            $browser->click('[data-test="offline-blocked-close"]');

            // sign out with an entry waiting: asked first, and refusing keeps the user signed in
            $browser->driver->executeScript('setTimeout(() => document.querySelector("[data-test=logout-button]").click(), 400);');
            expect(offlineModeDialog($browser, null, false))->toBe('1 offline entry has not been sent to the server yet. They stay on this device and are sent the next time you sign in on it. Sign out anyway?');
            $browser->assertPresent('[data-test="offline-chip"]');
            expect(DB::table('offline_sync_entries')->count())->toBe(0);

            // the session ends while the entry still waits (a forced sign-out): it is kept, nothing is posted
            $browser->driver->manage()->deleteAllCookies();
            offlineModeNetwork($browser, false);
            $browser->waitUsing(60, 1, fn (): bool => str_contains((string) $browser->text('[data-test="offline-chip"]'), 'Sign in'));
            expect(Contact::query()->count())->toBe($contacts)->and(DB::table('offline_sync_entries')->count())->toBe(0);

            // another user of the same device and company does not see it
            $this->signIn($browser, $other);
            $browser->visit('/offline')->waitFor('[data-test="offline-form"]', 40)->pause(1500);
            offlineModeSees($browser, 'Nothing is waiting on this device.');
            expect(DB::table('offline_sync_entries')->count())->toBe(0);

            // the owner signs in again: the entry is sent, once
            $browser->logout();
            $browser->driver->manage()->deleteAllCookies();
            $browser->loginAs($this->adminUser())->visit('/offline')->waitFor('[data-test="offline-form"]', 40);
            $browser->waitUsing(90, 1, fn (): bool => DB::table('offline_sync_entries')->where('status', 'synced')->count() === 1);
            expect(Contact::query()->count())->toBe($contacts + 1);
        });
    } finally {
        $other->delete();
        kbRestore($snapshot);
        DB::table('offline_sync_entries')->delete();
    }
});
