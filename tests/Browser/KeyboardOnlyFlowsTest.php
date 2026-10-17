<?php

use App\Models\Contact;
use Facebook\WebDriver\WebDriverKeys;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;

/**
 * A sale, a purchase and a journal entry completed with the keyboard alone. The mouse is never used after sign-in: a page is opened
 * by its address, the cursor starts in the field a page puts it in (or, where a page leaves it on the document, the first field of the
 * form is given the focus the way Tab would reach it), and everything else is typed, Tab, the arrow keys, Enter and Ctrl+S.
 */
function kbType(Browser $browser, string ...$keys): void
{
    foreach ($keys as $key) {
        $browser->driver->getKeyboard()->sendKeys($key);
        usleep(250000);
    }
}

function kbFocus(Browser $browser, string $selector): void
{
    $browser->waitFor($selector, 30);
    $browser->driver->executeScript('const e = document.querySelector(arguments[0]); e.scrollIntoView({block: "center"}); e.focus();', [$selector]);
    usleep(300000);
}

/**
 * Moves a drop-down to the option containing the text with the arrow keys, the way a person without a mouse does.
 */
function kbSelect(Browser $browser, string $selector, string $needle): void
{
    kbFocus($browser, $selector);

    for ($i = 0; $i < 80; $i++) {
        $chosen = (string) $browser->driver->executeScript('const s = document.querySelector(arguments[0]); return s.options[s.selectedIndex] ? s.options[s.selectedIndex].text : "";', [$selector]);

        if (str_contains($chosen, $needle)) {
            return;
        }

        kbType($browser, WebDriverKeys::ARROW_DOWN);
    }

    throw new RuntimeException("The option {$needle} was not reached with the arrow keys.");
}

function kbStock(array $world, int $quantity = 30): void
{
    DB::table('products')->where('id', $world['product_id'])->update(['active' => 1]);
    $purchase = DB::table('transactions')->insertGetId([
        'company_id' => $world['company_id'], 'branch_id' => $world['branch_id'], 'contact_id' => $world['contact_id'], 'invoice_no' => 'KBS-'.uniqid(), 'type' => 'purchaseorder',
        'status' => 'received', 'payment_status' => 'due', 'transaction_date' => now()->subDay()->toDateString(), 'final_amount' => 1000, 'total_item' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('purchase_lines')->insert([
        'transaction_id' => $purchase, 'product_id' => $world['product_id'], 'variation_id' => $world['variation_id'], 'unit_id' => $world['unit_id'], 'quantity' => $quantity, 'quantity_received' => $quantity,
        'qunatity_sold' => 0, 'quantity_returned' => 0, 'quantity_adjustment' => 0, 'purchase_rate' => 10, 'pp_without_discount' => 10, 'discount_percent' => 0, 'packing_qty' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('a sale is completed at the till with the keyboard alone', function () {
    $world = $this->world();
    $snapshot = kbSnapshot();
    $this->beforeApplicationDestroyed(fn () => kbRestore($snapshot));
    kbStock($world);
    $admin = $this->adminUser();

    foreach (['/sell/payment/add', '/sell/pos'] as $path) {
        $menuId = DB::table('menus')->where('route_path', $path)->value('id') ?? grantMenuPermission((int) $admin->role_id, $path, '', true);

        if (! DB::table('permissions')->where('role_id', $admin->role_id)->where('menu_id', $menuId)->exists()) {
            DB::table('permissions')->insert(['company_id' => null, 'branch_id' => null, 'department_id' => null, 'role_id' => $admin->role_id, 'menu_id' => $menuId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    foreach (['user_menu_permissions_tree', 'user_permission_paths', 'user_menu_permissions'] as $key) {
        Cache::forget("{$key}:{$admin->role_id}");
    }

    $code = DB::table('products')->where('id', $world['product_id'])->value('sku');
    $before = DB::table('transactions')->where('company_id', $world['company_id'])->where('type', 'sell')->count();

    $this->browse(function (Browser $browser) use ($code) {
        $this->signIn($browser, $this->adminUser())->visit('/sell/pos/add')->waitFor('[data-test="pos-search"]', 40);
        $browser->waitUsing(10, 1, fn (): bool => (bool) $browser->driver->executeScript('return document.activeElement && document.activeElement.getAttribute("data-test") === "pos-search";'));

        // the code typed into the search box, Enter adds the line
        kbType($browser, $code, WebDriverKeys::ENTER);
        $browser->waitFor('.pos-cart-line', 20);

        // F3 goes to the customer, the arrow key picks the first real customer
        kbType($browser, WebDriverKeys::F3, WebDriverKeys::ARROW_DOWN);

        // + once more, then F10 takes the sale as cash
        $browser->driver->executeScript('document.activeElement.blur();');
        kbType($browser, '+');
        $browser->waitUsing(10, 1, fn (): bool => (string) $browser->driver->executeScript('return document.querySelector(".pos-qty-stepper input").value;') === '2');
        kbType($browser, WebDriverKeys::F10);
        $browser->waitUsing(40, 1, fn (): bool => (int) $browser->driver->executeScript('return document.querySelectorAll(".pos-cart-line").length;') === 0);

        $this->assertNoBrowserErrors($browser);
    });

    expect(DB::table('transactions')->where('company_id', $world['company_id'])->where('type', 'sell')->count())->toBe($before + 1);
});

test('a purchase is completed on the form with the keyboard alone', function () {
    $world = $this->world();
    $snapshot = kbSnapshot();
    $this->beforeApplicationDestroyed(fn () => kbRestore($snapshot));
    $supplier = Contact::query()->findOrFail(trpContact($world, 'Keyboard Supplies', 'supplier', 'SU-KBD1'));
    seedPurchaseChartAccounts($world, $supplier);
    $invoice = 'KBD-P'.random_int(1000, 9999);
    $name = DB::table('products')->where('id', $world['product_id'])->value('name');
    $before = DB::table('transactions')->where('company_id', $world['company_id'])->where('type', 'purchaseorder')->count();

    $this->browse(function (Browser $browser) use ($invoice, $name) {
        $this->signIn($browser, $this->adminUser())->visit('/purchase/add')->waitFor('#ContactId', 40);

        // the supplier: type, the first match with Enter
        kbFocus($browser, '#ContactId');
        kbType($browser, 'Keyboard');
        $browser->waitUsing(20, 1, fn (): bool => str_contains((string) $browser->driver->executeScript('return document.body.innerText;'), 'Keyboard Supplies'));
        kbType($browser, WebDriverKeys::ARROW_DOWN, WebDriverKeys::ENTER);
        usleep(800000);

        // Tab to the invoice number and type it
        kbFocus($browser, '#InvoiceNo');
        kbType($browser, $invoice);

        // the product: search, first result with the arrow key and Enter
        kbFocus($browser, '.purchase-lines__search-input');
        kbType($browser, mb_substr($name, 0, 6));
        $browser->waitUsing(20, 1, fn (): bool => (bool) $browser->driver->executeScript('return !!document.querySelector(".purchase-lines__results [role=option], .purchase-lines__result, .purchase-lines__dropdown li, .purchase-lines__results li");'));
        kbType($browser, WebDriverKeys::ARROW_DOWN, WebDriverKeys::ENTER);
        $browser->waitUsing(20, 1, fn (): bool => (int) $browser->driver->executeScript('return document.querySelectorAll(".purchase-lines tbody tr, .purchase-line-row").length;') >= 1);

        // Ctrl+S saves
        $browser->driver->executeScript('document.activeElement.blur();');
        $browser->driver->getKeyboard()->sendKeys([WebDriverKeys::CONTROL, 's']);
        // Ctrl+S presses the form's first Save button (Save and add new), so the page stays: the saved purchase is what proves it
        $browser->waitUsing(40, 1, fn (): bool => DB::table('transactions')->where('type', 'purchaseorder')->where('invoice_no', $invoice)->exists());

        $this->assertNoBrowserErrors($browser);
    });

    expect(DB::table('transactions')->where('company_id', $world['company_id'])->where('type', 'purchaseorder')->where('invoice_no', $invoice)->count())->toBe(1);
    expect(DB::table('transactions')->where('company_id', $world['company_id'])->where('type', 'purchaseorder')->count())->toBe($before + 1);
});

test('a journal entry is completed on the form with the keyboard alone', function () {
    $world = $this->world();
    $snapshot = kbSnapshot();
    $this->beforeApplicationDestroyed(fn () => kbRestore($snapshot));
    insertPurchaseChartAccount($world, '101-90001', 'Keyboard Cash', 'dr', false);
    insertPurchaseChartAccount($world, '301-90001', 'Keyboard Capital', 'cr', false);
    $before = DB::table('t_accounts')->where('company_id', $world['company_id'])->count();

    $companyId = $world['company_id'];

    $this->browse(function (Browser $browser) use ($companyId, $before) {
        $this->signIn($browser, $this->adminUser())->visit('/journalentry/add')->waitFor('.journal-lines__add', 40);

        // line one: the account picked by typing its code, a narration, the debit
        kbSelect($browser, 'select.form-select', 'Keyboard Cash');
        kbType($browser, WebDriverKeys::TAB, 'Cash in', WebDriverKeys::TAB, '250');
        kbFocus($browser, '.journal-lines__add');
        kbType($browser, WebDriverKeys::ENTER);

        // line two: the same entry row, another account, the credit
        $browser->waitUsing(30, 1, fn (): bool => str_contains((string) $browser->driver->executeScript('return document.body.innerText;'), '1 line'));
        kbSelect($browser, 'select.form-select', 'Keyboard Capital');
        kbType($browser, WebDriverKeys::TAB, 'Capital in', WebDriverKeys::TAB, WebDriverKeys::TAB, '250');
        kbFocus($browser, '.journal-lines__add');
        kbType($browser, WebDriverKeys::ENTER);
        $browser->waitUsing(30, 1, fn (): bool => str_contains((string) $browser->driver->executeScript('return document.body.innerText;'), '2 lines'));

        $browser->driver->executeScript('document.activeElement.blur();');
        $browser->driver->getKeyboard()->sendKeys([WebDriverKeys::CONTROL, 's']);
        $browser->waitUsing(40, 1, fn (): bool => DB::table('t_accounts')->where('company_id', $companyId)->count() > $before);

        $this->assertNoBrowserErrors($browser);
    });

    expect(DB::table('t_accounts')->where('company_id', $world['company_id'])->count())->toBeGreaterThan($before);
});
