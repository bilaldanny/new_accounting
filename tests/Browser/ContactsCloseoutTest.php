<?php

use App\Models\Contact;
use App\Models\PortalRequest;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Dusk\Browser;

/**
 * The contacts closeout seen from a browser: a customer reads an invoice and sends a quotation request in the portal, a
 * staff user approves it into a quotation, a document is uploaded on the customer's Documents tab, and a customer file is
 * imported from the list.
 */
function duskPortalUser(Contact $contact): User
{
    $user = User::query()->create([
        'company_id' => $contact->company_id, 'role_id' => Role::query()->where('name', 'portal')->value('id'),
        'first_name' => 'Portal', 'last_name' => 'Dusk', 'email' => 'portal-dusk-'.uniqid().'@example.com', 'username' => 'portal-dusk-'.uniqid(),
        'password' => Hash::make('portal-pass-1'), 'is_active' => true,
    ]);
    $user->forceFill(['contact_id' => $contact->id, 'email_verified_at' => now()])->save();

    return $user->refresh();
}

test('a portal customer opens an invoice and sends a quotation request that staff approve into a quotation', function () {
    $world = $this->world();
    $customer = Contact::query()->findOrFail($world['contact_id']);
    $invoice = createSellRecord($world, ['invoice_no' => 'DUSK-PORTAL-1', 'final_amount' => 250]);
    $portalUser = duskPortalUser($customer);

    $this->browse(function (Browser $browser) use ($portalUser) {
        $this->signIn($browser, $portalUser)->visit('/portal')->waitForText('DUSK-PORTAL-1', 30);
        duskClickText($browser, 'View / print');
        $browser->waitForText('Download PDF', 20)->assertSee('Invoice DUSK-PORTAL-1');

        $browser->visit('/portal')->waitForText('Order / quotation request', 30);
        duskClickText($browser, 'Order / quotation request');
        $browser->waitForText('Search', 20);
        duskClickText($browser, 'Premium Basmati Rice - 120.00');
        duskClickText($browser, 'Send request');
        $browser->waitForText('Your request has been sent', 20)->waitForText('PR-', 10);
        $this->assertNoBrowserErrors($browser);
    });

    $request = PortalRequest::query()->where('contact_id', $customer->id)->firstOrFail();
    expect($request->kind)->toBe('quotation')->and($request->status)->toBe('pending');

    $this->browse(function (Browser $browser) use ($request) {
        $this->signIn($browser, $this->adminUser())->visit('/portalrequests')->waitForText($request->reference, 30);
        duskClickText($browser, 'Approve');
        $browser->acceptDialog();
        $browser->waitUntilMissingText($request->reference, 20);
        $this->assertNoBrowserErrors($browser);
    });

    $approved = $request->fresh();
    expect($approved->status)->toBe('approved')
        ->and(Transaction::query()->find($approved->transaction_id)?->status)->toBe('quotation')
        ->and($invoice->fresh()->status)->toBe('final');
});

test('a document is uploaded on the customer Documents tab and listed', function () {
    $world = $this->world();
    $path = tempnam(sys_get_temp_dir(), 'dusk').'.pdf';
    file_put_contents($path, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");

    $this->browse(function (Browser $browser) use ($world, $path) {
        $this->signIn($browser, $this->adminUser())->visit('/customer/'.$world['contact_id'].'/view')->waitForText('Documents', 30);
        duskClickText($browser, 'Documents');
        $browser->waitFor('#contact-document-file', 20)->attach('#contact-document-file', $path);
        duskClickText($browser, 'Upload');
        $browser->waitForText(basename($path), 40)->assertSee(basename($path));
        $this->assertNoBrowserErrors($browser);
    });

    expect(DB::table('contact_attachments')->where('contact_id', $world['contact_id'])->count())->toBe(1);
});

test('a customer file is checked and imported from the customer list', function () {
    $world = $this->world();
    $name = 'Dusk Imported '.uniqid();
    $path = tempnam(sys_get_temp_dir(), 'dusk').'.csv';
    file_put_contents($path, "business_name,first_name,mobile,email\n{$name},Imran,0300".random_int(1000000, 9999999).',dusk-import-'.uniqid()."@example.com\n");

    $this->browse(function (Browser $browser) use ($path) {
        $this->signIn($browser, $this->adminUser())->visit('/customer')->waitForText('Import', 30);
        duskClickText($browser, 'Import');
        $browser->waitForText('Import Customers', 20)->pause(500)->attach('#customer-import-file', $path);
        $browser->waitForText('Selected file', 10);
        duskClickText($browser, 'Check and import');
        $browser->waitForText('Successfully imported 1 new', 30);
        $this->assertNoBrowserErrors($browser);
    });

    expect(Contact::query()->where('business_name', $name)->where('user_type', 'customer')->exists())->toBeTrue();
});
