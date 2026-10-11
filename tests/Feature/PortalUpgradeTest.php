<?php

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\PortalRequest;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The portal beyond the balance page: a customer's invoices, statement and quotation / order requests, a supplier's
 * purchase orders and bills. The weight of these tests is on isolation: a portal user reaches only their own
 * contact's documents, whatever id they put in the URL, and only a staff user with the permission reviews requests.
 */
beforeEach(function () {
    Storage::fake(PortalRequest::DISK);
    RateLimiter::clear('portal');
});

function ptlContact(array $scope, string $type, string $name, array $attributes = []): Contact
{
    static $sequence = 0;
    $sequence++;

    return Contact::query()->create(array_merge([
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'business_name' => $name, 'first_name' => $name,
        'mobile' => '0300'.str_pad((string) $sequence, 7, '0', STR_PAD_LEFT), 'address' => 'Somewhere', 'code' => 'PT-'.$sequence,
        'user_type' => $type, 'type' => 'local', 'ntn_number' => '1', 'pay_term' => 10, 'pay_type' => 'day', 'active' => true,
    ], $attributes));
}

function ptlPortalUser(Contact $contact): User
{
    static $sequence = 0;
    $sequence++;

    $user = User::query()->create([
        'company_id' => $contact->company_id, 'role_id' => Role::query()->where('name', 'portal')->value('id'),
        'first_name' => 'Portal', 'last_name' => 'Person', 'email' => "portal{$sequence}@example.com", 'username' => "portal{$sequence}@example.com",
        'password' => Hash::make('portal-pass-1'), 'is_active' => true,
    ]);
    $user->forceFill(['contact_id' => $contact->id, 'email_verified_at' => now()])->save();

    return $user->refresh();
}

/**
 * @param  list<string>  $paths
 */
function ptlStaff(array $scope, array $paths): User
{
    $role = Role::query()->create(['name' => 'ptlstaff'.uniqid(), 'company_id' => $scope['company_id'], 'is_active' => true]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path);
    }

    return createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'email_verified_at' => now()]);
}

/**
 * Two customers and two suppliers of one company, plus a second company with its own customer, all with documents.
 *
 * @return array<string, mixed>
 */
function ptlWorld(): array
{
    $scope = seedSellScope();
    $customerA = Contact::query()->findOrFail($scope['contact_id']);
    $customerB = ptlContact($scope, 'customer', 'Other Customer');
    $supplierA = Contact::query()->where('company_id', $scope['company_id'])->where('user_type', 'supplier')->firstOrFail();
    $supplierB = ptlContact($scope, 'supplier', 'Other Supplier');

    $companyB = DB::table('companies')->insertGetId(['code' => 'PTLB', 'name' => 'Second Co', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $branchB = DB::table('branches')->insertGetId(['code' => 'PTLBB', 'company_id' => $companyB, 'name' => 'B Branch', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $scopeB = ['company_id' => $companyB, 'branch_id' => $branchB] + $scope;
    $customerOfB = ptlContact($scopeB, 'customer', 'Customer Of B');

    $invoiceA = createSellRecord($scope, ['invoice_no' => 'INV-A1', 'final_amount' => 500]);
    createSellRecord($scope, ['invoice_no' => 'INV-A-DRAFT', 'status' => 'draft', 'final_amount' => 90]);
    createSellRecord($scope, ['invoice_no' => 'INV-A-QUOTE', 'status' => 'quotation', 'final_amount' => 80]);
    $invoiceB = createSellRecord($scope, ['invoice_no' => 'INV-B1', 'contact_id' => $customerB->id, 'final_amount' => 700]);
    $invoiceOfB = createSellRecord($scopeB, ['invoice_no' => 'INV-X1', 'contact_id' => $customerOfB->id, 'final_amount' => 900]);

    $orderA = createPurchaseRecord($scope, ['invoice_no' => 'PO-A1', 'contact_id' => $supplierA->id, 'status' => 'ordered']);
    createPurchaseRecord($scope, ['invoice_no' => 'PO-A-DRAFT', 'contact_id' => $supplierA->id, 'status' => 'draft']);
    $orderB = createPurchaseRecord($scope, ['invoice_no' => 'PO-B1', 'contact_id' => $supplierB->id, 'status' => 'ordered']);

    return compact('scope', 'customerA', 'customerB', 'supplierA', 'supplierB', 'customerOfB', 'invoiceA', 'invoiceB', 'invoiceOfB', 'orderA', 'orderB');
}

function ptlPdf(string $name = 'bill.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
}

// --- invoices and statement ------------------------------------------------------------------------

test('a customer lists only their own posted invoices', function () {
    $world = ptlWorld();
    Sanctum::actingAs(ptlPortalUser($world['customerA']));

    $response = $this->getJson('/api/portal/invoices')->assertOk();

    expect(collect($response->json('data'))->pluck('invoice_no')->all())->toBe(['INV-A1'])
        ->and($response->json('data.0.amount'))->toEqual(500)
        ->and($response->json('data.0.due'))->toEqual(500);
});

test('an invoice shows lines, company, totals and what was paid, and only for its own customer', function () {
    $world = ptlWorld();
    DB::table('payments')->insert([
        'company_id' => $world['scope']['company_id'], 'branch_id' => $world['scope']['branch_id'], 'transaction_id' => $world['invoiceA']->id,
        'contact_id' => $world['customerA']->id, 'amount' => 200, 'method' => 'cash', 'is_return' => 0, 'paid_on' => '2026-10-01',
        'payment_ref_no' => 'SP-1', 'created_at' => now(), 'updated_at' => now(),
    ]);
    Sanctum::actingAs(ptlPortalUser($world['customerA']));

    $detail = $this->getJson('/api/portal/invoices/'.$world['invoiceA']->id)->assertOk();

    expect($detail->json('invoice_no'))->toBe('INV-A1')
        ->and($detail->json('paid'))->toEqual(200)
        ->and($detail->json('due'))->toEqual(300)
        ->and($detail->json('company.name'))->toBe('Purchase Test Company')
        ->and($detail->json('lines.0.name'))->toBe('Premium Basmati Rice')
        ->and($detail->json('payments.0.reference'))->toBe('SP-1');

    // another customer's invoice of the same company, another company's invoice, a draft of their own, a purchase order
    foreach ([$world['invoiceB']->id, $world['invoiceOfB']->id, $world['orderA']->id] as $foreignId) {
        $this->getJson('/api/portal/invoices/'.$foreignId)->assertNotFound();
    }

    $draftId = Transaction::query()->where('invoice_no', 'INV-A-DRAFT')->value('id');
    $this->getJson('/api/portal/invoices/'.$draftId)->assertNotFound();
});

test('a statement carries the opening balance, only own posted entries, a running balance and the closing balance', function () {
    $world = ptlWorld();
    Sanctum::actingAs(ptlPortalUser($world['customerA']));

    $response = $this->getJson('/api/portal/statement?from=2026-01-01&to=2026-12-31')->assertOk();

    $rows = collect($response->json('rows'));

    expect($rows->pluck('reference')->all())->toBe(['INV-A1'])
        ->and($rows->last()['balance'])->toEqual($response->json('closing_balance'))
        ->and($response->json('closing_balance'))->toEqual(500)
        ->and($response->json('contact.name'))->toBe('Acme Retail');

    $this->getJson('/api/portal/statement?from=2026-12-31&to=2026-01-01')->assertUnprocessable();
});

// --- who may call what -----------------------------------------------------------------------------

test('a customer cannot use supplier endpoints, a supplier cannot use customer endpoints, and staff are not portal users', function () {
    $world = ptlWorld();

    Sanctum::actingAs(ptlPortalUser($world['customerA']));
    $this->getJson('/api/portal/purchase-orders')->assertForbidden();
    $this->getJson('/api/portal/bills')->assertForbidden();
    $this->postJson('/api/portal/bills', [])->assertForbidden();

    Sanctum::actingAs(ptlPortalUser($world['supplierA']));
    $this->getJson('/api/portal/invoices')->assertForbidden();
    $this->getJson('/api/portal/statement')->assertForbidden();
    $this->postJson('/api/portal/requests', ['kind' => 'order', 'lines' => [['product_id' => 1, 'quantity' => 1]]])->assertForbidden();

    Sanctum::actingAs(ptlStaff($world['scope'], ['/customer']));
    $this->getJson('/api/portal/invoices')->assertForbidden();
});

test('a portal user is still kept out of every staff endpoint', function () {
    $world = ptlWorld();
    Sanctum::actingAs(ptlPortalUser($world['customerA']));

    $this->getJson('/api/customers')->assertForbidden();
    $this->getJson('/api/portal-requests')->assertForbidden();
    $this->getJson('/api/sells')->assertForbidden();
});

// --- customer requests -----------------------------------------------------------------------------

test('a customer asks for a quotation, sees it pending, and can cancel it', function () {
    $world = ptlWorld();
    $salesBefore = Transaction::query()->where('type', 'sell')->count();
    Sanctum::actingAs(ptlPortalUser($world['customerA']));

    $products = $this->getJson('/api/portal/products?search=Basmati')->assertOk();
    expect($products->json('data.0.price'))->toEqual(120);

    $created = $this->postJson('/api/portal/requests', [
        'kind' => 'quotation', 'note' => 'For the October order',
        'lines' => [['product_id' => $world['scope']['product_id'], 'quantity' => 5]],
    ])->assertCreated();

    expect($created->json('data.status'))->toBe('pending')
        ->and($created->json('data.reference'))->toStartWith('PR-')
        ->and($created->json('data.lines.0.unit_price'))->toEqual(120)
        ->and(Transaction::query()->where('type', 'sell')->count())->toBe($salesBefore);

    $id = $created->json('data.id');
    $this->getJson('/api/portal/requests')->assertOk()->assertJsonCount(1, 'data');

    $this->postJson("/api/portal/requests/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson("/api/portal/requests/{$id}/cancel")->assertUnprocessable();
});

test('a request is refused for a product of another company, for an on-hold customer, and with bad quantities', function () {
    $world = ptlWorld();
    $foreignProduct = DB::table('products')->insertGetId([
        'company_id' => $world['customerOfB']->company_id, 'unit_id' => $world['scope']['unit_id'], 'name' => 'Foreign', 'sku' => 'F-1',
        'active' => 1, 'type' => 'single', 'created_at' => now(), 'updated_at' => now(),
    ]);
    Sanctum::actingAs(ptlPortalUser($world['customerA']));

    $this->postJson('/api/portal/requests', ['kind' => 'order', 'lines' => [['product_id' => $foreignProduct, 'quantity' => 1]]])
        ->assertUnprocessable()->assertJsonValidationErrors(['lines.0.product_id']);
    $this->postJson('/api/portal/requests', ['kind' => 'order', 'lines' => [['product_id' => $world['scope']['product_id'], 'quantity' => 0]]])
        ->assertUnprocessable()->assertJsonValidationErrors(['lines.0.quantity']);
    $this->postJson('/api/portal/requests', ['kind' => 'bill', 'lines' => []])->assertUnprocessable();

    $world['customerA']->update(['is_on_hold' => true]);
    $this->postJson('/api/portal/requests', ['kind' => 'order', 'lines' => [['product_id' => $world['scope']['product_id'], 'quantity' => 1]]])
        ->assertUnprocessable();

    expect(PortalRequest::query()->count())->toBe(0);
});

test('one customer cannot see or cancel another customer request', function () {
    $world = ptlWorld();
    $other = PortalRequest::query()->create([
        'company_id' => $world['scope']['company_id'], 'branch_id' => $world['scope']['branch_id'], 'contact_id' => $world['customerB']->id,
        'kind' => 'order', 'status' => 'pending', 'lines' => [],
    ]);
    Sanctum::actingAs(ptlPortalUser($world['customerA']));

    $this->getJson('/api/portal/requests')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/portal/requests/{$other->id}/cancel")->assertNotFound();

    expect($other->fresh()->status)->toBe('pending');
});

test('staff approve a quotation request into a quotation and an order request into a draft, posting nothing', function () {
    $world = ptlWorld();
    $customer = ptlPortalUser($world['customerA']);
    $before = DB::table('t_accounts')->count();

    Sanctum::actingAs($customer);
    $lines = [['product_id' => $world['scope']['product_id'], 'quantity' => 2]];
    $quotation = $this->postJson('/api/portal/requests', ['kind' => 'quotation', 'lines' => $lines])->json('data.id');
    $order = $this->postJson('/api/portal/requests', ['kind' => 'order', 'note' => 'Urgent', 'lines' => $lines])->json('data.id');

    Sanctum::actingAs(ptlStaff($world['scope'], ['/portalrequests', '/portalrequests/approve']));

    $this->getJson('/api/portal-requests')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.contact_name', 'Acme Retail');

    $approved = $this->postJson("/api/portal-requests/{$quotation}/approve")->assertOk();
    $sale = Transaction::query()->findOrFail($approved->json('data.transaction_id'));

    expect($sale->status)->toBe('quotation')
        ->and($sale->contact_id)->toBe($world['customerA']->id)
        ->and((float) $sale->final_amount)->toBe(240.0)
        ->and($sale->selllines()->count())->toBe(1)
        ->and($sale->additional_note)->toContain('PR-');

    $draft = Transaction::query()->findOrFail($this->postJson("/api/portal-requests/{$order}/approve")->assertOk()->json('data.transaction_id'));

    expect($draft->status)->toBe('draft')
        ->and($draft->additional_note)->toContain('Urgent')
        ->and(DB::table('t_accounts')->count())->toBe($before);

    $this->postJson("/api/portal-requests/{$order}/approve")->assertUnprocessable();
});

test('staff need the permission, see only their company, and a rejection needs a reason', function () {
    $world = ptlWorld();
    $request = PortalRequest::query()->create([
        'company_id' => $world['scope']['company_id'], 'branch_id' => $world['scope']['branch_id'], 'contact_id' => $world['customerA']->id,
        'kind' => 'order', 'status' => 'pending', 'lines' => [['product_id' => $world['scope']['product_id'], 'variation_id' => $world['scope']['variation_id'], 'quantity' => 1, 'unit_price' => 120]],
    ]);

    Sanctum::actingAs(ptlStaff($world['scope'], ['/portalrequests']));
    $this->getJson('/api/portal-requests')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/portal-requests/{$request->id}/approve")->assertForbidden();
    $this->postJson("/api/portal-requests/{$request->id}/reject", ['note' => 'no'])->assertForbidden();

    $companyB = ['company_id' => $world['customerOfB']->company_id, 'branch_id' => $world['customerOfB']->branch_id];
    Sanctum::actingAs(ptlStaff($companyB, ['/portalrequests', '/portalrequests/approve']));
    $this->getJson('/api/portal-requests')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/portal-requests/{$request->id}/approve")->assertNotFound();
    $this->postJson("/api/portal-requests/{$request->id}/reject", ['note' => 'not mine'])->assertNotFound();

    Sanctum::actingAs(ptlStaff($world['scope'], ['/portalrequests', '/portalrequests/approve']));
    $this->postJson("/api/portal-requests/{$request->id}/reject", [])->assertUnprocessable();
    $this->postJson("/api/portal-requests/{$request->id}/reject", ['note' => 'Out of stock'])->assertOk()->assertJsonPath('data.status', 'rejected');

    Sanctum::actingAs(ptlPortalUser($world['customerA']));
    $this->getJson('/api/portal/requests')->assertJsonPath('data.0.review_note', 'Out of stock');
});

// --- supplier --------------------------------------------------------------------------------------

test('a supplier sees only their own purchase orders with their status, never a draft', function () {
    $world = ptlWorld();
    Sanctum::actingAs(ptlPortalUser($world['supplierA']));

    $list = $this->getJson('/api/portal/purchase-orders')->assertOk();

    expect(collect($list->json('data'))->pluck('invoice_no')->all())->toBe(['PO-A1'])
        ->and($list->json('data.0.status_label'))->toBe('Ordered')
        ->and($list->json('data.0.ordered_quantity'))->toEqual(1)
        ->and($list->json('data.0.received_quantity'))->toEqual(0);

    $detail = $this->getJson('/api/portal/purchase-orders/'.$world['orderA']->id)->assertOk();
    expect($detail->json('lines.0.name'))->toBe('Premium Basmati Rice');

    $this->getJson('/api/portal/purchase-orders/'.$world['orderB']->id)->assertNotFound();
    $this->getJson('/api/portal/purchase-orders/'.$world['invoiceA']->id)->assertNotFound();
});

test('a supplier sends a bill document as a pending request, tied to their own purchase order', function () {
    $world = ptlWorld();
    $ordersBefore = Transaction::query()->where('type', 'purchaseorder')->count();
    Sanctum::actingAs(ptlPortalUser($world['supplierA']));

    $created = $this->postJson('/api/portal/bills', [
        'bill_no' => 'SUP-77', 'bill_date' => '2026-10-05', 'amount' => 1500.5, 'purchase_id' => $world['orderA']->id,
        'note' => 'Delivered 4 Oct', 'file' => ptlPdf(),
    ])->assertCreated();

    expect($created->json('data.status'))->toBe('pending')
        ->and($created->json('data.bill_no'))->toBe('SUP-77')
        ->and($created->json('data.amount'))->toEqual(1500.5)
        ->and($created->json('data.has_file'))->toBeTrue()
        ->and(Transaction::query()->where('type', 'purchaseorder')->count())->toBe($ordersBefore);

    $id = $created->json('data.id');
    $this->getJson('/api/portal/bills')->assertOk()->assertJsonCount(1, 'data');
    $this->get("/api/portal/bills/{$id}/file")->assertOk()->assertDownload('bill.pdf');

    // the same bill number again, and a purchase order that is another supplier's
    $this->postJson('/api/portal/bills', ['bill_no' => 'SUP-77', 'bill_date' => '2026-10-06', 'amount' => 10, 'file' => ptlPdf()])
        ->assertUnprocessable()->assertJsonValidationErrors(['bill_no']);
    $this->postJson('/api/portal/bills', ['bill_no' => 'SUP-78', 'bill_date' => '2026-10-06', 'amount' => 10, 'purchase_id' => $world['orderB']->id, 'file' => ptlPdf()])
        ->assertUnprocessable()->assertJsonValidationErrors(['purchase_id']);

    expect(PortalRequest::query()->count())->toBe(1);
});

test('a bill must be a real PDF or image, within the size and the company storage limit', function () {
    $world = ptlWorld();
    Sanctum::actingAs(ptlPortalUser($world['supplierA']));

    $path = tempnam(sys_get_temp_dir(), 'ptl');
    file_put_contents($path, '<?php echo 1;');
    $script = new UploadedFile($path, 'bill.pdf', null, null, true);

    $base = ['bill_no' => 'B-1', 'bill_date' => '2026-10-05', 'amount' => 10];

    $this->postJson('/api/portal/bills', $base + ['file' => $script])->assertUnprocessable()->assertJsonValidationErrors(['file']);
    $this->postJson('/api/portal/bills', $base)->assertUnprocessable()->assertJsonValidationErrors(['file']);

    Company::query()->findOrFail($world['scope']['company_id'])->update(['max_storage_mb' => 0]);
    $this->postJson('/api/portal/bills', $base + ['file' => ptlPdf()])->assertUnprocessable()->assertJsonValidationErrors(['document']);

    expect(PortalRequest::query()->count())->toBe(0);
});

test('one supplier cannot download another supplier bill, and staff review it', function () {
    $world = ptlWorld();
    Sanctum::actingAs(ptlPortalUser($world['supplierB']));
    $id = $this->postJson('/api/portal/bills', ['bill_no' => 'B-9', 'bill_date' => '2026-10-05', 'amount' => 20, 'file' => ptlPdf('theirs.pdf')])->assertCreated()->json('data.id');

    Sanctum::actingAs(ptlPortalUser($world['supplierA']));
    $this->get("/api/portal/bills/{$id}/file")->assertNotFound();
    $this->getJson('/api/portal/bills')->assertJsonCount(0, 'data');

    $staff = ptlStaff($world['scope'], ['/portalrequests', '/portalrequests/approve']);
    Sanctum::actingAs($staff);
    $this->get("/api/portal-requests/{$id}/document")->assertOk()->assertDownload('theirs.pdf');

    $approved = $this->postJson("/api/portal-requests/{$id}/approve", ['note' => 'Entered as PI-55'])->assertOk();
    expect($approved->json('data.status'))->toBe('approved')
        ->and($approved->json('data.transaction_id'))->toBeNull()
        ->and(Company::query()->findOrFail($world['scope']['company_id'])->storageUsedBytes())->toBeGreaterThan(0);
});

// --- payment info and limits -----------------------------------------------------------------------

test('the portal shows the payment instructions of the contact own company only', function () {
    $world = ptlWorld();
    CompanySetting::createCompanySettings($world['scope']['company_id'], 'Purchase Test Company')->update(['portal_payment_info' => 'HBL, Account 0123, IBAN PK00']);
    CompanySetting::createCompanySettings($world['customerOfB']->company_id, 'Second Co')->update(['portal_payment_info' => 'MCB secret']);

    Sanctum::actingAs(ptlPortalUser($world['customerA']));
    $this->getJson('/api/portal/payment-info')->assertOk()->assertJsonPath('payment_info', 'HBL, Account 0123, IBAN PK00');

    Sanctum::actingAs(ptlPortalUser($world['customerOfB']));
    $this->getJson('/api/portal/payment-info')->assertOk()->assertJsonPath('payment_info', 'MCB secret');
});

test('the payment instructions are saved through the company settings', function () {
    $company = Company::query()->create(['code' => 'CO-PPI', 'name' => 'Pay Info Co', 'is_active' => true, 'max_users' => 5, 'max_branches' => 1]);
    CompanySetting::createCompanySettings($company->id, $company->name);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->putJson("/api/company-settings/{$company->id}", ['business_name' => 'Pay Info Co', 'portal_payment_info' => 'Meezan, IBAN PK11'])->assertOk();

    expect(DB::table('company_settings')->where('company_id', $company->id)->value('portal_payment_info'))->toBe('Meezan, IBAN PK11');
    $this->getJson("/api/company-settings/{$company->id}")->assertJsonPath('companySetting.portal_payment_info', 'Meezan, IBAN PK11');
});

test('submitting is rate limited per account and reading has its own limit', function () {
    $world = ptlWorld();
    $user = ptlPortalUser($world['customerA']);
    RateLimiter::clear('portal-submit|'.$user->id);
    Sanctum::actingAs($user);

    $payload = ['kind' => 'quotation', 'lines' => [['product_id' => $world['scope']['product_id'], 'quantity' => 1]]];

    foreach (range(1, 10) as $attempt) {
        $this->postJson('/api/portal/requests', $payload)->assertCreated();
    }

    $this->postJson('/api/portal/requests', $payload)->assertStatus(429);

    // the read limiter is separate from the submit limiter
    foreach (range(1, 60) as $attempt) {
        $this->getJson('/api/portal/invoices')->assertOk();
    }

    $this->getJson('/api/portal/invoices')->assertStatus(429);
});

test('the Portal Requests page opens only with its permission', function () {
    $world = ptlWorld();

    $this->actingAs(ptlStaff($world['scope'], ['/customer']))->get('/portalrequests')->assertForbidden();
    $this->actingAs(ptlStaff($world['scope'], ['/portalrequests']))->get('/portalrequests')->assertOk();
});

test('the menu migrations add the import, documents and portal request rows once and grant them to companyadmin', function () {
    $admin = Role::query()->firstOrCreate(['name' => 'companyadmin', 'company_id' => null], ['is_active' => true])->id;
    $paths = ['/customer/import', '/supplier/import', '/customer/documents', '/supplier/documents', '/portalrequests', '/portalrequests/approve'];

    foreach ($paths as $path) {
        $ids = DB::table('menus')->where('route_path', $path)->pluck('id');
        DB::table('permissions')->whereIn('menu_id', $ids)->delete();
        DB::table('menus')->whereIn('id', $ids)->delete();
    }

    $group = DB::table('menus')->where('route_path', '/customer')->value('parent_id')
        ?? DB::table('menus')->insertGetId(['name' => 'Contacts', 'route_name' => 'contacts', 'route_path' => '/contacts-group', 'icon' => 'Grid', 'menu_color' => '#000', 'sort_order' => 1, 'is_hidden' => 0, 'is_active' => 1, 'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now()]);

    foreach (['/customer' => 'Customer', '/supplier' => 'Supplier'] as $path => $name) {
        if (! DB::table('menus')->where('route_path', $path)->exists()) {
            DB::table('menus')->insert(['parent_id' => $group, 'name' => $name, 'route_name' => ltrim($path, '/'), 'route_path' => $path, 'icon' => 'Grid', 'menu_color' => '#000', 'sort_order' => 1, 'is_hidden' => 0, 'is_active' => 1, 'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    foreach ([
        'migrations/2026_11_03_100000_add_contact_import_menus.php',
        'migrations/2026_11_03_130000_add_contact_document_menus.php',
        'migrations/2026_11_03_150000_add_portal_requests_menu.php',
    ] as $file) {
        $migration = require database_path($file);
        $migration->up();
        $migration->up();
    }

    $granted = DB::table('permissions')->join('menus', 'menus.id', '=', 'permissions.menu_id')
        ->where('permissions.role_id', $admin)->whereIn('menus.route_path', $paths)->pluck('menus.route_path')->sort()->values()->all();

    expect($granted)->toBe(collect($paths)->sort()->values()->all())
        ->and(DB::table('menus')->whereIn('route_path', $paths)->count())->toBe(count($paths));
});
