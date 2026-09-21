<?php

use App\Models\CashCollection;
use App\Models\CashCollectionAllocation;
use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\Payment;
use App\Models\Role;
use App\Models\TAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashCollections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Cash collection beyond the plain flow: the company switch, keeping the extra cash as a customer advance, using
 * that advance on invoices later, and reversing a completed collection. Invoices here are plain rows (no sale
 * journal), so the tests look at the payments and the vouchers the collection itself posts.
 *
 * @return array<string, mixed>
 */
function ccaScope(bool $enabled = true): array
{
    $scope = seedSellScope();
    $cashId = insertPurchaseChartAccount($scope, '111-00001', 'Cash in Hand', 'dr', false);
    insertPurchaseAccountMapping($scope, 'Cash', 'cash', $cashId);

    (CompanySetting::query()->where('company_id', $scope['company_id'])->first() ?? CompanySetting::createCompanySettings((int) $scope['company_id']))
        ->forceFill(['cash_collection' => $enabled])->save();

    Sanctum::actingAs(User::query()->findOrFail(1));

    return array_merge($scope, ['cash_account_id' => $cashId]);
}

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $attributes
 */
function ccaInvoice(array $scope, string $number, float $amount, array $attributes = []): Transaction
{
    return createSellRecord($scope, array_merge(['invoice_no' => $number, 'final_amount' => $amount, 'transaction_date' => '2026-09-01'], $attributes));
}

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $attributes
 */
function ccaCollection(array $scope, float $amount, array $attributes = []): CashCollection
{
    return CashCollection::query()->create(array_merge([
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'contact_id' => $scope['customer_id'],
        'reference' => CashCollection::nextReference($scope['company_id']), 'collected_on' => '2026-09-10', 'amount' => $amount, 'status' => 'pending',
    ], $attributes));
}

/**
 * Completes the collection through the API: `$parts` is [invoice id => amount].
 *
 * @param  array<string, mixed>  $scope
 * @param  array<int, float>  $parts
 * @param  array<string, mixed>  $extra
 */
function ccaComplete(array $scope, CashCollection $collection, array $parts, array $extra = []): TestResponse
{
    $allocations = [];

    foreach ($parts as $invoiceId => $amount) {
        $allocations[] = ['transaction_id' => $invoiceId, 'amount' => $amount];
    }

    return test()->postJson('/api/cash-collections/'.$collection->id.'/complete', array_merge(['payment_account' => $scope['cash_account_id'], 'allocations' => $allocations], $extra));
}

/**
 * A user of the company with a role that holds exactly these menu permissions.
 *
 * @param  array<string, mixed>  $scope
 * @param  list<string>  $paths
 */
function ccaUser(array $scope, array $paths): User
{
    $role = Role::query()->create(['name' => 'collector'.uniqid(), 'company_id' => $scope['company_id'], 'is_active' => true]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path, ltrim(str_replace('/', '', $path), '/').uniqid());
    }

    return createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']]);
}

// --- the company setting -------------------------------------------------------------------------

test('a company that has not switched cash collection on cannot record, complete or use one', function () {
    $scope = ccaScope(false);
    $invoice = ccaInvoice($scope, 'INV-A', 100);
    $collection = ccaCollection($scope, 100);

    $this->postJson('/api/cash-collections', [
        'branch_id' => $scope['branch_id'], 'company_id' => $scope['company_id'], 'contact_id' => $scope['customer_id'], 'collected_on' => '2026-09-10', 'amount' => 50,
    ])->assertUnprocessable()->assertJsonPath('reason', 'disabled');

    ccaComplete($scope, $collection, [$invoice->id => 100])->assertUnprocessable()->assertJsonPath('reason', 'disabled');

    expect(CashCollection::query()->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(0)
        ->and($collection->refresh()->status)->toBe('pending');

    // reading, cancelling and deleting still work, and switching it on makes it work
    $this->getJson('/api/cash-collections/'.$collection->id)->assertSuccessful()->assertJsonPath('enabled', false);
    CompanySetting::query()->where('company_id', $scope['company_id'])->update(['cash_collection' => true]);
    ccaComplete($scope, $collection, [$invoice->id => 100])->assertSuccessful();
});

test('the switch is the company\'s own: one company\'s setting does not open another', function () {
    $scope = ccaScope(true);
    $other = DB::table('companies')->insertGetId(['code' => 'CCA02', 'name' => 'Other Co', 'address' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    expect(app(CashCollections::class)->enabledFor((int) $scope['company_id']))->toBeTrue()
        ->and(app(CashCollections::class)->enabledFor($other))->toBeFalse();
});

// --- advance -------------------------------------------------------------------------------------

test('cash beyond the invoices is kept as a customer advance when the caller says so, and posted', function () {
    $scope = ccaScope();
    $invoice = ccaInvoice($scope, 'INV-A', 100);
    $collection = ccaCollection($scope, 150);

    ccaComplete($scope, $collection, [$invoice->id => 100], ['keep_advance' => true])->assertSuccessful()->assertJsonPath('advance_amount', 50);

    $collection->refresh();
    $advance = Payment::query()->findOrFail($collection->advance_payment_id);
    $journal = TAccount::query()->with('details')->findOrFail($advance->t_account_id);

    expect($collection->status)->toBe('completed')
        ->and($collection->advance_amount)->toBe(50.0)
        ->and($collection->advanceRemaining())->toBe(50.0)
        ->and($advance->transaction_id)->toBeNull()
        ->and($advance->contact_id)->toBe($scope['customer_id'])
        ->and((float) $advance->amount)->toBe(50.0)
        ->and($journal->voucher_no)->toStartWith('SP-')
        ->and((float) $journal->details->firstWhere('coa_id', $scope['cash_account_id'])->debit)->toBe(50.0)
        ->and((float) $journal->details->firstWhere('coa_id', $scope['customer_coa_id'])->credit)->toBe(50.0)
        ->and($invoice->refresh()->payment_status)->toBe('paid')
        ->and(Payment::query()->count())->toBe(2)
        ->and(CashCollectionAllocation::query()->count())->toBe(1);

    $show = $this->getJson('/api/cash-collections/'.$collection->id)->assertSuccessful();

    expect($show->json('advance_amount'))->toBe(50)
        ->and($show->json('advance_remaining'))->toBe(50);
});

test('cash short of the collected amount is refused unless the advance is asked for, and more than collected always is', function () {
    $scope = ccaScope();
    $invoice = ccaInvoice($scope, 'INV-A', 100);
    $collection = ccaCollection($scope, 150);

    ccaComplete($scope, $collection, [$invoice->id => 100])->assertUnprocessable()->assertJsonPath('reason', 'allocation_mismatch');
    ccaComplete($scope, ccaCollection($scope, 80), [$invoice->id => 90], ['keep_advance' => true])->assertUnprocessable()->assertJsonPath('reason', 'allocation_mismatch');

    expect(Payment::query()->count())->toBe(0)
        ->and($collection->refresh()->status)->toBe('pending')
        ->and($collection->advance_amount)->toBe(0.0);
});

test('a whole collection can be an advance when the customer owes nothing yet', function () {
    $scope = ccaScope();
    $collection = ccaCollection($scope, 200);

    test()->postJson('/api/cash-collections/'.$collection->id.'/complete', ['payment_account' => $scope['cash_account_id'], 'keep_advance' => true])
        ->assertSuccessful()->assertJsonPath('advance_amount', 200);

    expect($collection->refresh()->advanceRemaining())->toBe(200.0)
        ->and(CashCollectionAllocation::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(1);

    // without the advance flag an empty list is still refused, as before
    $this->postJson('/api/cash-collections/'.ccaCollection($scope, 10)->id.'/complete', ['payment_account' => $scope['cash_account_id'], 'allocations' => []])->assertUnprocessable();
});

test('an advance needs the customer to be linked to a chart of account and saves nothing otherwise', function () {
    $scope = ccaScope();
    $unlinked = Contact::query()->create([
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'business_name' => 'Unlinked Buyer', 'first_name' => 'U', 'mobile' => '0300', 'address' => 'x',
        'code' => 'CU-UNL', 'user_type' => 'customer', 'type' => 'local', 'ntn_number' => '1234567', 'pay_type' => 'day', 'credit_limit' => 0, 'active' => true,
    ]);
    $collection = ccaCollection($scope, 60, ['contact_id' => $unlinked->id]);

    test()->postJson('/api/cash-collections/'.$collection->id.'/complete', ['payment_account' => $scope['cash_account_id'], 'keep_advance' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['contact_id']);

    expect($collection->refresh()->status)->toBe('pending')
        ->and(Payment::query()->count())->toBe(0)
        ->and(TAccount::query()->count())->toBe(0);
});

// --- using the advance ---------------------------------------------------------------------------

test('the advance settles later invoices without moving cash again, and never more than is left', function () {
    $scope = ccaScope();
    $collection = ccaCollection($scope, 50);
    test()->postJson('/api/cash-collections/'.$collection->id.'/complete', ['payment_account' => $scope['cash_account_id'], 'keep_advance' => true])->assertSuccessful();
    $vouchers = TAccount::query()->count();
    $later = ccaInvoice($scope, 'INV-LATER', 80, ['transaction_date' => '2026-09-15']);

    $this->postJson('/api/cash-collections/'.$collection->id.'/apply-advance', ['allocations' => [['transaction_id' => $later->id, 'amount' => 30]]])
        ->assertSuccessful()->assertJsonPath('advance_remaining', 20);

    $payment = Payment::query()->where('transaction_id', $later->id)->firstOrFail();

    expect($payment->method)->toBe('other')
        ->and($payment->t_account_id)->toBeNull()
        ->and($payment->payment_account)->toBeNull()
        ->and((float) $payment->amount)->toBe(30.0)
        ->and($payment->note)->toContain($collection->reference)
        ->and(TAccount::query()->count())->toBe($vouchers)
        ->and($later->refresh()->payment_status)->toBe('partial')
        ->and((float) $later->paid_amount)->toBe(30.0)
        ->and(CashCollectionAllocation::query()->where('kind', 'advance')->sum('amount'))->toBe(30.0);

    $this->postJson('/api/cash-collections/'.$collection->id.'/apply-advance', ['allocations' => [['transaction_id' => $later->id, 'amount' => 25]]])
        ->assertUnprocessable()->assertJsonPath('reason', 'advance_exceeded');

    $this->postJson('/api/cash-collections/'.$collection->id.'/apply-advance', ['allocations' => [['transaction_id' => $later->id, 'amount' => 20]]])->assertSuccessful();

    expect($collection->refresh()->advanceRemaining())->toBe(0.0)
        ->and($later->refresh()->payment_status)->toBe('partial');
});

test('the advance cannot pay more than the invoice owes, another customer\'s invoice or a draft, and changes nothing when refused', function () {
    $scope = ccaScope();
    $collection = ccaCollection($scope, 100);
    test()->postJson('/api/cash-collections/'.$collection->id.'/complete', ['payment_account' => $scope['cash_account_id'], 'keep_advance' => true])->assertSuccessful();

    $small = ccaInvoice($scope, 'INV-SMALL', 40);
    $draft = ccaInvoice($scope, 'INV-DRAFT', 40, ['status' => 'draft']);
    $other = createExportCustomer($scope);
    $foreign = ccaInvoice($scope, 'INV-OTHER', 40, ['contact_id' => $other->id]);

    foreach ([[$small->id, 60], [$draft->id, 10], [$foreign->id, 10]] as [$invoiceId, $amount]) {
        $this->postJson('/api/cash-collections/'.$collection->id.'/apply-advance', ['allocations' => [['transaction_id' => $invoiceId, 'amount' => $amount]]])->assertUnprocessable();
    }

    expect(CashCollectionAllocation::query()->where('kind', 'advance')->count())->toBe(0)
        ->and($collection->refresh()->advanceRemaining())->toBe(100.0)
        ->and($small->refresh()->payment_status)->toBe('due');
});

test('only a completed collection has an advance to use', function () {
    $scope = ccaScope();
    $invoice = ccaInvoice($scope, 'INV-A', 40);

    $this->postJson('/api/cash-collections/'.ccaCollection($scope, 40)->id.'/apply-advance', ['allocations' => [['transaction_id' => $invoice->id, 'amount' => 10]]])
        ->assertUnprocessable()->assertJsonPath('reason', 'not_completed');
});

// --- reversing -----------------------------------------------------------------------------------

/**
 * A completed collection of 150: 100 on invoice A, 50 kept as an advance, of which 30 settled invoice B.
 *
 * @return array<string, mixed>
 */
function ccaCompleted(array $scope): array
{
    $a = ccaInvoice($scope, 'INV-A', 100);
    $b = ccaInvoice($scope, 'INV-B', 80, ['transaction_date' => '2026-09-15']);
    $collection = ccaCollection($scope, 150, ['note' => 'Collected at the shop']);

    ccaComplete($scope, $collection, [$a->id => 100], ['keep_advance' => true])->assertSuccessful();
    test()->postJson('/api/cash-collections/'.$collection->id.'/apply-advance', ['allocations' => [['transaction_id' => $b->id, 'amount' => 30]]])->assertSuccessful();

    return compact('a', 'b', 'collection');
}

test('reversing a completed collection removes every payment and voucher it made and puts it back to pending', function () {
    $scope = ccaScope();
    ['a' => $a, 'b' => $b, 'collection' => $collection] = ccaCompleted($scope);
    expect(Payment::query()->count())->toBe(3)
        ->and(TAccount::query()->count())->toBe(2);

    $this->postJson('/api/cash-collections/'.$collection->id.'/reverse', ['note' => 'Wrong customer'])->assertSuccessful()->assertJsonPath('message', 'Successfully Reversed');

    $collection->refresh();

    expect($collection->status)->toBe('pending')
        ->and($collection->advance_amount)->toBe(0.0)
        ->and($collection->advance_payment_id)->toBeNull()
        ->and($collection->payment_account)->toBeNull()
        ->and($collection->completed_at)->toBeNull()
        ->and($collection->reversed_at)->not->toBeNull()
        ->and($collection->reversed_by)->toBe(1)
        ->and($collection->note)->toContain('Collected at the shop')->toContain('Wrong customer')
        ->and(Payment::query()->count())->toBe(0)
        ->and(TAccount::query()->count())->toBe(0)
        ->and(CashCollectionAllocation::query()->count())->toBe(0)
        ->and($a->refresh()->payment_status)->toBe('due')
        ->and((float) $a->paid_amount)->toBe(0.0)
        ->and($b->refresh()->payment_status)->toBe('due')
        ->and((float) $b->paid_amount)->toBe(0.0);
});

test('a reversed collection can be corrected and completed again', function () {
    $scope = ccaScope();
    ['a' => $a, 'b' => $b, 'collection' => $collection] = ccaCompleted($scope);
    $this->postJson('/api/cash-collections/'.$collection->id.'/reverse')->assertSuccessful();

    ccaComplete($scope, $collection, [$b->id => 80, $a->id => 70])->assertSuccessful();

    expect($collection->refresh()->status)->toBe('completed')
        ->and($collection->advance_amount)->toBe(0.0)
        ->and($a->refresh()->payment_status)->toBe('partial')
        ->and($b->refresh()->payment_status)->toBe('paid')
        ->and(Payment::query()->count())->toBe(2);
});

test('only a completed collection can be reversed, and only once', function () {
    $scope = ccaScope();

    $this->postJson('/api/cash-collections/'.ccaCollection($scope, 40)->id.'/reverse')->assertUnprocessable()->assertJsonPath('reason', 'not_completed');
    $this->postJson('/api/cash-collections/'.ccaCollection($scope, 40, ['status' => 'cancelled'])->id.'/reverse')->assertUnprocessable()->assertJsonPath('reason', 'not_completed');

    ['collection' => $collection] = ccaCompleted($scope);
    $this->postJson('/api/cash-collections/'.$collection->id.'/reverse')->assertSuccessful();
    $this->postJson('/api/cash-collections/'.$collection->id.'/reverse')->assertUnprocessable()->assertJsonPath('reason', 'not_completed');
});

test('a payment that was already deleted by hand does not stop the reversal', function () {
    $scope = ccaScope();
    ['a' => $a, 'collection' => $collection] = ccaCompleted($scope);
    $payment = Payment::query()->where('transaction_id', $a->id)->firstOrFail();
    Payment::deleteSellPayments([$payment->id]);

    $this->postJson('/api/cash-collections/'.$collection->id.'/reverse')->assertSuccessful();

    expect(Payment::query()->count())->toBe(0)
        ->and($collection->refresh()->status)->toBe('pending');
});

test('reversing and using an advance need their own permissions and stay inside the company', function () {
    $scope = ccaScope();
    ['b' => $b, 'collection' => $collection] = ccaCompleted($scope);

    Sanctum::actingAs(ccaUser($scope, ['/cashcollection/:id/view']));
    $this->postJson('/api/cash-collections/'.$collection->id.'/reverse')->assertForbidden();
    $this->postJson('/api/cash-collections/'.$collection->id.'/apply-advance', ['allocations' => [['transaction_id' => $b->id, 'amount' => 10]]])->assertForbidden();
    expect($collection->refresh()->status)->toBe('completed');

    Sanctum::actingAs(ccaUser($scope, ['/cashcollection/advance']));
    $this->postJson('/api/cash-collections/'.$collection->id.'/apply-advance', ['allocations' => [['transaction_id' => $b->id, 'amount' => 10]]])->assertSuccessful();
    $this->postJson('/api/cash-collections/'.$collection->id.'/reverse')->assertForbidden();

    Sanctum::actingAs(ccaUser($scope, ['/cashcollection/reverse']));
    $this->postJson('/api/cash-collections/'.$collection->id.'/reverse')->assertSuccessful();

    $otherCompany = DB::table('companies')->insertGetId(['code' => 'CCA03', 'name' => 'Third Co', 'address' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $role = Role::query()->create(['name' => 'outsider', 'company_id' => $otherCompany, 'is_active' => true]);
    grantMenuPermission($role->id, '/cashcollection/reverse', 'cashcollection.reverse.other');
    grantMenuPermission($role->id, '/cashcollection/advance', 'cashcollection.advance.other');
    Sanctum::actingAs(createStaffUserForRole($role, ['company_id' => $otherCompany]));

    $this->postJson('/api/cash-collections/'.$collection->id.'/reverse')->assertNotFound();
    $this->postJson('/api/cash-collections/'.$collection->id.'/apply-advance', ['allocations' => [['transaction_id' => $b->id, 'amount' => 10]]])->assertNotFound();
});

test('reversing is atomic: nothing changes when it cannot finish', function () {
    $scope = ccaScope();
    ['collection' => $collection] = ccaCompleted($scope);
    $payments = Payment::query()->count();

    // a hostile body is a validation error, never a half-reversed collection
    $this->postJson('/api/cash-collections/'.$collection->id.'/reverse', ['note' => str_repeat('x', 400)])->assertUnprocessable();

    expect($collection->refresh()->status)->toBe('completed')
        ->and(Payment::query()->count())->toBe($payments);
});
