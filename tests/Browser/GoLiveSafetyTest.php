<?php

use App\Models\Contact;
use App\Models\Menu;
use App\Models\Role;
use App\Models\Tax;
use App\Support\RolePresets;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;

/**
 * The go-live safeguards seen from a browser: the FBR disclaimer and stub-mode warning, the withholding tax account message
 * a user gets when nothing is mapped, and what a preset role is given.
 */
test('the FBR card shows the not-live disclaimer and a stub-mode warning when it is switched on without credentials', function () {
    $world = $this->world();
    DB::table('companies')->where('id', $world['company_id'])->update(['ntn_no' => '1234567-8']);

    $this->browse(function (Browser $browser) {
        $this->signIn($browser, $this->adminUser())->visit('/company/setting')->waitForText('Business Setting', 30);
        duskClickText($browser, 'Tax');

        $browser->waitFor('[data-test="fbr-warning"]', 20)
            ->assertSeeIn('[data-test="fbr-warning"]', 'LIVE/COMPLIANT nahi hai')
            ->assertSeeIn('[data-test="fbr-warning"]', 'tax consultant')
            ->assertMissing('[data-test="fbr-stub-mode"]');

        $browser->waitFor('#fbr-enabled', 10)->script('document.getElementById("fbr-enabled").scrollIntoView({block: "center"});');
        $browser->check('#fbr-enabled')->waitFor('[data-test="fbr-stub-mode"]', 10)->assertSeeIn('[data-test="fbr-stub-mode"]', 'Stub mode');
        duskClickText($browser, 'Save tax and FBR settings');
        $browser->pause(1500);

        // The disclaimer stays after saving: nothing here is a live connection.
        $browser->assertPresent('[data-test="fbr-warning"]')->assertPresent('[data-test="fbr-stub-mode"]');
        $this->assertNoBrowserErrors($browser);
    });

    expect((bool) DB::table('fbr_settings')->where('company_id', $world['company_id'])->value('enabled'))->toBeTrue();
});

test('a purchase with withholding tax is refused with a clear message until the payable account is mapped, then saves', function () {
    $world = $this->world();
    $supplier = Contact::query()->where('user_type', 'supplier')->firstOrFail();
    $tax = Tax::query()->create(['company_id' => $world['company_id'], 'name' => 'Dusk WHT', 'percentage' => 4, 'type' => 0, 'status' => true, 'kind' => 'withholding']);
    $payload = validPurchasePayload($world, ['contact_id' => $supplier->id, 'withholding_tax_id' => $tax->id]);

    $this->browse(function (Browser $browser) use ($world, $payload) {
        $this->signIn($browser, $this->adminUser())->visit('/dashboard')->waitForText('Dashboard', 30);

        $refused = duskRequest($browser, 'POST', '/api/purchases', $payload);
        expect($refused['status'])->toBe(422)
            ->and($refused['body']['errors']['withholding_tax_id'][0])->toContain('Withholding Tax Payable')->toContain('Settings > Company Settings > Link Accounts');

        $account = insertPurchaseChartAccount($world, '311-90050', 'Dusk WHT Payable', 'cr', false);
        insertPurchaseAccountMapping($world, 'Withholding Tax Payable', 'withholdingpayable', $account);

        $saved = duskRequest($browser, 'POST', '/api/purchases', $payload);
        expect($saved['status'])->toBe(200);
    });

    expect(DB::table('transactions')->where('withholding_tax_id', $tax->id)->exists())->toBeTrue();
});

test('a cashier preset role sees the till pages and not the books or the approvals', function () {
    $world = $this->world();
    $role = Role::query()->create(['name' => 'Cashier', 'company_id' => $world['company_id'], 'branch_id' => $world['branch_id'], 'is_active' => true]);
    RolePresets::grant((int) $role->id, (int) $world['company_id'], (int) $world['branch_id'], 'Cashier');
    $cashier = createStaffUserForRole($role, ['company_id' => $world['company_id'], 'branch_id' => $world['branch_id']]);

    $paths = Menu::permittedRoutePathsForRole((int) $role->id);
    expect($paths)->toContain('/posshift', '/cashcollection')->not->toContain('/journalentry', '/journalentry/approval', '/cashcollection/reverse', '/expense');

    $this->browse(function (Browser $browser) use ($cashier) {
        $this->signIn($browser, $cashier)->visit('/posshift')->waitForText('POS Shifts', 30)->assertSee('POS Shifts');
    });
});

test('an accountant preset role opens its accounting pages and gets a 403 from pages it was not given', function () {
    $world = $this->world();
    $role = Role::query()->create(['name' => 'Accountant', 'company_id' => $world['company_id'], 'branch_id' => $world['branch_id'], 'is_active' => true]);
    RolePresets::grant((int) $role->id, (int) $world['company_id'], (int) $world['branch_id'], 'Accountant');
    $accountant = createStaffUserForRole($role, ['company_id' => $world['company_id'], 'branch_id' => $world['branch_id']]);

    $this->browse(function (Browser $browser) use ($accountant) {
        $this->signIn($browser, $accountant)->visit('/tax')->pause(1500);

        expect(duskRequest($browser, 'GET', '/taxexemption')['status'])->toBe(200)
            ->and(duskRequest($browser, 'GET', '/costcenter')['status'])->toBe(200)
            ->and(duskRequest($browser, 'GET', '/journalentry/approval')['status'])->toBe(403)
            ->and(duskRequest($browser, 'GET', '/leads')['status'])->toBe(403)
            ->and(duskRequest($browser, 'GET', '/subscriptionplans')['status'])->toBe(403)
            ->and(duskRequest($browser, 'GET', '/api/taxes')['status'])->toBe(200)
            ->and(duskRequest($browser, 'GET', '/api/asset-approvals')['status'])->toBe(403);
    });
});

test('a branch created from the API shows its withholding accounts as mapped in Company Settings Link Accounts', function () {
    $world = $this->world();

    $this->browse(function (Browser $browser) use ($world) {
        $this->signIn($browser, $this->superadmin())->visit('/dashboard')->waitForText('Dashboard', 30);

        $created = duskRequest($browser, 'POST', '/api/branches', ['company_id' => $world['company_id'], 'name' => 'Dusk Second Branch', 'email' => 'second@dusk.test', 'phone' => '03001234567', 'address' => 'Road 2']);
        expect($created['status'])->toBe(200);

        $branchId = DB::table('branches')->where('name', 'Dusk Second Branch')->value('id');
        $mapped = DB::table('chart_of_account_mappings')->where('branch_id', $branchId)->whereIn('key', ['withholdingreceivable', 'withholdingpayable'])->whereNotNull('value')->count();
        expect($mapped)->toBe(2);

        $companyAdmin = createStaffUserForRole(Role::query()->firstOrCreate(['name' => 'companyadmin', 'company_id' => null], ['is_active' => true]), ['company_id' => $world['company_id'], 'branch_id' => $world['branch_id']]);
        $this->signIn($browser, $companyAdmin)->visit('/company/setting')->waitForText('Business Setting', 30);
        duskClickText($browser, 'Link Accounts');
        $browser->waitFor('#LinkAccountsBranch', 20)->select('#LinkAccountsBranch', (string) $branchId);

        $script = 'return [...document.querySelectorAll("select")].filter((s) => [...s.options].some((o) => /Withholding/.test(o.text))).map((s) => s.options[s.selectedIndex] ? s.options[s.selectedIndex].text : "");';
        $browser->waitUsing(20, 500, fn () => count($browser->driver->executeScript($script)) === 2);
        $selected = $browser->driver->executeScript($script);

        expect($selected)->toHaveCount(2)->and($selected[0])->toContain('Withholding Tax Receivable')->and($selected[1])->toContain('Withholding Tax Payable');
    });
});
