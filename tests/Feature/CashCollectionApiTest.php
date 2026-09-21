<?php

use App\Models\CashCollection;
use App\Models\CashCollectionAllocation;
use App\Models\Contact;
use App\Models\Payment;
use App\Models\Role;
use App\Models\TAccount;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * A company with a branch, a customer linked to the chart of accounts and a cash account to bank into.
 *
 * @return array<string, mixed>
 */
function cclScope(): array
{
    $scope = seedSellScope();
    $cashId = insertPurchaseChartAccount($scope, '111-00001', 'Cash in Hand', 'dr', false);
    insertPurchaseAccountMapping($scope, 'Cash', 'cash', $cashId);

    return array_merge($scope, ['cash_account_id' => $cashId]);
}

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $attributes
 */
function cclInvoice(array $scope, string $number, float $amount, array $attributes = []): Transaction
{
    return createSellRecord($scope, array_merge([
        'invoice_no' => $number,
        'final_amount' => $amount,
        'transaction_date' => '2026-09-01',
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $attributes
 */
function cclMake(array $scope, array $attributes = []): CashCollection
{
    return CashCollection::query()->create(array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'contact_id' => $scope['customer_id'],
        'reference' => CashCollection::nextReference($scope['company_id']),
        'collected_on' => '2026-09-10',
        'amount' => 100,
        'status' => 'pending',
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $scope
 * @param  list<string>  $paths  the menu permissions the user's role is given
 */
function cclStaff(array $scope, array $paths = [], string $roleName = 'companyadmin', ?int $branchId = null): User
{
    $role = Role::query()->create(['name' => $roleName, 'company_id' => $scope['company_id'], 'is_active' => true]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path);
    }

    return createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $branchId]);
}

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cclPayload(array $scope, array $overrides = []): array
{
    return array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'contact_id' => $scope['customer_id'],
        'collected_on' => '2026-09-10',
        'amount' => 100,
        'note' => 'Collected at the counter',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $scope
 * @param  list<array{transaction_id: int, amount: float|int}>  $allocations
 * @return array<string, mixed>
 */
function cclComplete(array $scope, array $allocations, array $overrides = []): array
{
    return array_merge(['payment_account' => $scope['cash_account_id'], 'allocations' => $allocations], $overrides);
}

// --- recording ---------------------------------------------------------------------------------

test('a collection is recorded as pending with a reference and posts nothing', function () {
    $scope = cclScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/cash-collections', cclPayload($scope))->assertSuccessful()->assertJson(['reference' => 'CC-00001']);

    $collection = CashCollection::query()->firstOrFail();

    expect($collection->status)->toBe('pending')
        ->and($collection->company_id)->toBe($scope['company_id'])
        ->and($collection->branch_id)->toBe($scope['branch_id'])
        ->and($collection->contact_id)->toBe($scope['customer_id'])
        ->and($collection->collected_on->toDateString())->toBe('2026-09-10')
        ->and($collection->amount)->toBe(100.0)
        ->and($collection->note)->toBe('Collected at the counter')
        ->and($collection->collected_by)->toBe($superadmin->id)
        ->and(Payment::query()->count())->toBe(0);
});

test('references count up per company and are not reused after a delete', function () {
    $scope = cclScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $first = $this->postJson('/api/cash-collections', cclPayload($scope))->json('reference');
    $second = $this->postJson('/api/cash-collections', cclPayload($scope))->json('reference');
    CashCollection::query()->where('reference', $second)->firstOrFail()->delete();
    $third = $this->postJson('/api/cash-collections', cclPayload($scope))->json('reference');

    expect([$first, $second, $third])->toBe(['CC-00001', 'CC-00002', 'CC-00003']);
});

test('recording validates its input', function (array $overrides, string $field) {
    $scope = cclScope();
    $supplier = Contact::query()->where('user_type', 'supplier')->firstOrFail();
    $overrides = array_map(fn ($value) => $value === '@supplier' ? $supplier->id : $value, $overrides);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/cash-collections', cclPayload($scope, $overrides))->assertUnprocessable()->assertJsonValidationErrors([$field]);

    expect(CashCollection::query()->count())->toBe(0);
})->with([
    'no customer' => [['contact_id' => null], 'contact_id'],
    'unknown customer' => [['contact_id' => 999999], 'contact_id'],
    'a supplier is not a customer' => [['contact_id' => '@supplier'], 'contact_id'],
    'no branch' => [['branch_id' => null], 'branch_id'],
    'unknown branch' => [['branch_id' => 999999], 'branch_id'],
    'no date' => [['collected_on' => null], 'collected_on'],
    'date in a wrong format' => [['collected_on' => '10/09/2026'], 'collected_on'],
    'a date in the future' => [['collected_on' => '2999-01-01'], 'collected_on'],
    'no amount' => [['amount' => null], 'amount'],
    'zero amount' => [['amount' => 0], 'amount'],
    'negative amount' => [['amount' => -5], 'amount'],
    'amount not a number' => [['amount' => 'lots'], 'amount'],
    'amount with three decimals' => [['amount' => 10.005], 'amount'],
    'amount too large' => [['amount' => 10000000], 'amount'],
    'note too long' => [['note' => str_repeat('n', 501)], 'note'],
    'unknown company' => [['company_id' => 999999], 'company_id'],
    'no company for the superadmin' => [['company_id' => null], 'company_id'],
]);

test('the boundary values are accepted: today, the smallest and the largest amount', function () {
    $scope = cclScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/cash-collections', cclPayload($scope, ['collected_on' => now()->toDateString()]))->assertSuccessful();
    $this->postJson('/api/cash-collections', cclPayload($scope, ['amount' => 0.01]))->assertSuccessful();
    $this->postJson('/api/cash-collections', cclPayload($scope, ['amount' => 9999999.99]))->assertSuccessful();

    expect(CashCollection::query()->count())->toBe(3);
});

test('a customer or branch of another company is refused', function () {
    $scope = cclScope();
    $otherCompany = DB::table('companies')->insertGetId(['code' => 'CCL002', 'name' => 'Other Co', 'address' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $otherBranch = trpBranch($otherCompany, 'Other Branch');
    $otherCustomer = trpContact(['company_id' => $otherCompany, 'branch_id' => $otherBranch], 'Other Customer', 'customer', 'CU-OTH');
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/cash-collections', cclPayload($scope, ['contact_id' => $otherCustomer]))->assertUnprocessable()->assertJsonValidationErrors(['contact_id']);
    $this->postJson('/api/cash-collections', cclPayload($scope, ['branch_id' => $otherBranch]))->assertUnprocessable()->assertJsonValidationErrors(['branch_id']);
});

// --- editing -----------------------------------------------------------------------------------

test('a pending collection can be edited but keeps its company and reference', function () {
    $scope = cclScope();
    $collection = cclMake($scope);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->putJson('/api/cash-collections/'.$collection->id, cclPayload($scope, ['amount' => 250.5, 'note' => 'Corrected', 'collected_on' => '2026-09-11', 'company_id' => 999999]))->assertSuccessful();

    $collection->refresh();

    expect($collection->amount)->toBe(250.5)
        ->and($collection->note)->toBe('Corrected')
        ->and($collection->collected_on->toDateString())->toBe('2026-09-11')
        ->and($collection->company_id)->toBe($scope['company_id'])
        ->and($collection->reference)->toBe('CC-00001');
});

test('a completed or cancelled collection can no longer be edited', function (string $status) {
    $scope = cclScope();
    $collection = cclMake($scope, ['status' => $status]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->putJson('/api/cash-collections/'.$collection->id, cclPayload($scope, ['amount' => 5]))->assertUnprocessable()->assertJsonPath('reason', 'not_pending');

    expect($collection->refresh()->amount)->toBe(100.0);
})->with(['completed', 'cancelled']);

// --- open invoices -----------------------------------------------------------------------------

test('open invoices are the customer\'s posted sales that still owe money, oldest first', function () {
    $scope = cclScope();
    $newer = cclInvoice($scope, 'INV-B', 80, ['transaction_date' => '2026-09-05']);
    $older = cclInvoice($scope, 'INV-A', 100, ['transaction_date' => '2026-08-20']);
    $partlyPaid = cclInvoice($scope, 'INV-C', 60, ['transaction_date' => '2026-08-25']);
    $paid = cclInvoice($scope, 'INV-D', 50, ['transaction_date' => '2026-08-26']);
    cclInvoice($scope, 'INV-E', 70, ['status' => 'draft']);
    cclInvoice($scope, 'INV-F', 70, ['status' => 'quotation']);
    cclInvoice($scope, 'INV-G', 70, ['type' => Transaction::TYPE_PURCHASE]);
    $otherCustomer = trpContact($scope, 'Beta Traders', 'customer', 'CU-BETA');
    cclInvoice($scope, 'INV-H', 90, ['contact_id' => $otherCustomer]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    foreach ([[$partlyPaid, 20], [$paid, 50]] as [$sale, $amount]) {
        $this->postJson('/api/sell-payments', [
            'company_id' => $scope['company_id'], 'transaction_id' => $sale->id, 'amount' => $amount, 'paid_on' => '2026-09-06',
            'method' => 'cash', 'payment_account' => $scope['cash_account_id'],
        ])->assertSuccessful();
    }

    $rows = collect($this->getJson('/api/cash-collections/open-invoices?contact_id='.$scope['customer_id'].'&company_id='.$scope['company_id'])->assertSuccessful()->json());

    expect($rows->pluck('invoice_no')->all())->toBe(['INV-A', 'INV-C', 'INV-B'])
        ->and($rows->pluck('remaining')->all())->toBe([100, 40, 80])
        ->and($rows->firstWhere('invoice_no', 'INV-C')['paid_amount'])->toBe(20)
        ->and($rows->pluck('id')->all())->toBe([$older->id, $partlyPaid->id, $newer->id]);
});

test('open invoices need a customer and a company user only sees their own company\'s', function () {
    $scope = cclScope();
    cclInvoice($scope, 'INV-A', 100);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->getJson('/api/cash-collections/open-invoices')->assertUnprocessable()->assertJsonValidationErrors(['contact_id']);
    $this->getJson('/api/cash-collections/open-invoices?contact_id='.$scope['customer_id'])->assertSuccessful()->assertJsonCount(0);

    Sanctum::actingAs(cclStaff($scope));

    $this->getJson('/api/cash-collections/open-invoices?contact_id='.$scope['customer_id'].'&company_id=999999')->assertSuccessful()->assertJsonCount(1);
});

// --- completing --------------------------------------------------------------------------------

test('completing spreads the amount over invoices as sell payments and updates their status', function () {
    $scope = cclScope();
    $first = cclInvoice($scope, 'INV-A', 100);
    $second = cclInvoice($scope, 'INV-B', 200);
    $collection = cclMake($scope, ['amount' => 150]);
    $user = cclStaff($scope, ['/cashcollection/complete']);
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/cash-collections/'.$collection->id.'/complete', cclComplete($scope, [
        ['transaction_id' => $first->id, 'amount' => 100],
        ['transaction_id' => $second->id, 'amount' => 50],
    ]))->assertSuccessful()->assertJsonPath('message', 'Successfully Saved');

    $collection->refresh();
    $payments = Payment::query()->orderBy('id')->get();

    expect($collection->status)->toBe('completed')
        ->and($collection->payment_account)->toBe($scope['cash_account_id'])
        ->and($collection->completed_by)->toBe($user->id)
        ->and($collection->completed_at)->not->toBeNull()
        ->and($payments)->toHaveCount(2)
        ->and($payments->pluck('amount')->map(fn ($v) => (float) $v)->all())->toBe([100.0, 50.0])
        ->and($payments->pluck('method')->unique()->all())->toBe(['cash'])
        ->and($payments->pluck('contact_id')->unique()->all())->toBe([$scope['customer_id']])
        ->and((string) $payments[0]->paid_on)->toStartWith('2026-09-10')
        ->and($payments[0]->note)->toBe('Cash collection CC-00001')
        ->and($payments[0]->payment_account)->toBe($scope['cash_account_id'])
        ->and($first->fresh()->payment_status)->toBe('paid')
        ->and($second->fresh()->payment_status)->toBe('partial')
        ->and((float) $second->fresh()->paid_amount)->toBe(50.0)
        ->and($response->json('payments'))->toBe($payments->pluck('id')->all());

    $allocations = CashCollectionAllocation::query()->orderBy('id')->get();

    expect($allocations->pluck('transaction_id')->all())->toBe([$first->id, $second->id])
        ->and($allocations->pluck('payment_id')->all())->toBe($payments->pluck('id')->all())
        ->and($allocations->pluck('amount')->all())->toBe([100.0, 50.0]);
});

test('the payments are posted to the ledger like any sell payment', function () {
    $scope = cclScope();
    $invoice = cclInvoice($scope, 'INV-A', 100);
    $collection = cclMake($scope, ['amount' => 100]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/cash-collections/'.$collection->id.'/complete', cclComplete($scope, [['transaction_id' => $invoice->id, 'amount' => 100]]))->assertSuccessful();

    $payment = Payment::query()->firstOrFail();
    $journal = TAccount::query()->with('details')->findOrFail($payment->t_account_id);
    $debit = $journal->details->firstWhere('coa_id', $scope['cash_account_id']);
    $credit = $journal->details->firstWhere('coa_id', $scope['customer_coa_id']);

    expect($journal->voucher_no)->toStartWith('SP-')
        ->and((float) $journal->total_amount)->toBe(100.0)
        ->and((float) $debit->debit)->toBe(100.0)
        ->and((float) $credit->credit)->toBe(100.0)
        ->and((float) $journal->details->sum('debit'))->toBe((float) $journal->details->sum('credit'));
});

test('the allocations must add up to exactly the collected amount', function (array $amounts) {
    $scope = cclScope();
    $first = cclInvoice($scope, 'INV-A', 100);
    $second = cclInvoice($scope, 'INV-B', 100);
    $collection = cclMake($scope, ['amount' => 100]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $allocations = [['transaction_id' => $first->id, 'amount' => $amounts[0]]];

    if (isset($amounts[1])) {
        $allocations[] = ['transaction_id' => $second->id, 'amount' => $amounts[1]];
    }

    $this->postJson('/api/cash-collections/'.$collection->id.'/complete', cclComplete($scope, $allocations))->assertUnprocessable()->assertJsonPath('reason', 'allocation_mismatch');

    expect($collection->refresh()->status)->toBe('pending')
        ->and(Payment::query()->count())->toBe(0)
        ->and(CashCollectionAllocation::query()->count())->toBe(0);
})->with([
    'less than collected' => [[99.99]],
    'more than collected' => [[100.01]],
    'two parts that fall short' => [[60, 39.99]],
    'two parts that overshoot' => [[60, 40.01]],
]);

test('two parts that add up exactly are accepted, even with cents', function () {
    $scope = cclScope();
    $first = cclInvoice($scope, 'INV-A', 100);
    $second = cclInvoice($scope, 'INV-B', 100);
    $collection = cclMake($scope, ['amount' => 100.1]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/cash-collections/'.$collection->id.'/complete', cclComplete($scope, [
        ['transaction_id' => $first->id, 'amount' => 60.05],
        ['transaction_id' => $second->id, 'amount' => 40.05],
    ]))->assertSuccessful();

    expect(Payment::query()->count())->toBe(2);
});

test('an invoice that is not an open invoice of the customer is refused and nothing at all is posted', function (string $kind) {
    $scope = cclScope();
    $good = cclInvoice($scope, 'INV-A', 100);
    $otherCustomer = trpContact($scope, 'Beta Traders', 'customer', 'CU-BETA');

    $bad = match ($kind) {
        'another customer' => cclInvoice($scope, 'INV-X', 100, ['contact_id' => $otherCustomer]),
        'a draft' => cclInvoice($scope, 'INV-X', 100, ['status' => 'draft']),
        'a quotation' => cclInvoice($scope, 'INV-X', 100, ['status' => 'quotation']),
        'a purchase' => cclInvoice($scope, 'INV-X', 100, ['type' => Transaction::TYPE_PURCHASE]),
        'an invoice that does not exist' => (object) ['id' => 999999],
    };

    $collection = cclMake($scope, ['amount' => 150]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/cash-collections/'.$collection->id.'/complete', cclComplete($scope, [
        ['transaction_id' => $good->id, 'amount' => 100],
        ['transaction_id' => $bad->id, 'amount' => 50],
    ]))->assertUnprocessable()->assertJsonValidationErrors(['allocations.1.transaction_id']);

    expect($collection->refresh()->status)->toBe('pending')
        ->and(Payment::query()->count())->toBe(0)
        ->and($good->fresh()->payment_status)->toBe('due')
        ->and(CashCollectionAllocation::query()->count())->toBe(0);
})->with(['another customer', 'a draft', 'a quotation', 'a purchase', 'an invoice that does not exist']);

test('an invoice cannot be paid more than it still owes and everything is rolled back', function () {
    $scope = cclScope();
    $first = cclInvoice($scope, 'INV-A', 100);
    $second = cclInvoice($scope, 'INV-B', 30);
    $collection = cclMake($scope, ['amount' => 100]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/cash-collections/'.$collection->id.'/complete', cclComplete($scope, [
        ['transaction_id' => $first->id, 'amount' => 60],
        ['transaction_id' => $second->id, 'amount' => 40],
    ]))->assertUnprocessable()->assertJsonValidationErrors(['amount']);

    expect($collection->refresh()->status)->toBe('pending')
        ->and(Payment::query()->count())->toBe(0)
        ->and($first->fresh()->payment_status)->toBe('due');
});

test('completing validates its input', function (array $overrides, string $field) {
    $scope = cclScope();
    $invoice = cclInvoice($scope, 'INV-A', 100);
    $collection = cclMake($scope, ['amount' => 100]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $body = array_merge(cclComplete($scope, [['transaction_id' => $invoice->id, 'amount' => 100]]), $overrides);

    $this->postJson('/api/cash-collections/'.$collection->id.'/complete', $body)->assertUnprocessable()->assertJsonValidationErrors([$field]);

    expect($collection->refresh()->status)->toBe('pending')
        ->and(Payment::query()->count())->toBe(0);
})->with([
    'no account' => [['payment_account' => null], 'payment_account'],
    'an unknown account' => [['payment_account' => 999999], 'payment_account'],
    'no allocations' => [['allocations' => []], 'allocations'],
    'allocations that are not a list' => [['allocations' => 'all'], 'allocations'],
    'a row without an invoice' => [['allocations' => [['amount' => 100]]], 'allocations.0.transaction_id'],
    'a row without an amount' => [['allocations' => [['transaction_id' => 1]]], 'allocations.0.amount'],
    'a zero amount' => [['allocations' => [['transaction_id' => 1, 'amount' => 0]]], 'allocations.0.amount'],
    'an amount with three decimals' => [['allocations' => [['transaction_id' => 1, 'amount' => 1.005]]], 'allocations.0.amount'],
    'the same invoice twice' => [['allocations' => [['transaction_id' => 1, 'amount' => 50], ['transaction_id' => 1, 'amount' => 50]]], 'allocations.0.transaction_id'],
]);

test('a collection is completed only once', function () {
    $scope = cclScope();
    $invoice = cclInvoice($scope, 'INV-A', 500);
    $collection = cclMake($scope, ['amount' => 100]);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $body = cclComplete($scope, [['transaction_id' => $invoice->id, 'amount' => 100]]);

    $this->postJson('/api/cash-collections/'.$collection->id.'/complete', $body)->assertSuccessful();
    $this->postJson('/api/cash-collections/'.$collection->id.'/complete', $body)->assertUnprocessable()->assertJsonPath('reason', 'not_pending');

    expect(Payment::query()->count())->toBe(1)
        ->and((float) $invoice->fresh()->paid_amount)->toBe(100.0);
});

test('a cancelled collection cannot be completed', function () {
    $scope = cclScope();
    $invoice = cclInvoice($scope, 'INV-A', 100);
    $collection = cclMake($scope, ['amount' => 100, 'status' => 'cancelled']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/cash-collections/'.$collection->id.'/complete', cclComplete($scope, [['transaction_id' => $invoice->id, 'amount' => 100]]))->assertUnprocessable()->assertJsonPath('reason', 'not_pending');

    expect(Payment::query()->count())->toBe(0);
});

// --- cancelling and deleting -------------------------------------------------------------------

test('a pending collection can be cancelled; that posts nothing and closes it', function () {
    $scope = cclScope();
    $collection = cclMake($scope);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/cash-collections/'.$collection->id.'/cancel')->assertSuccessful();

    $collection->refresh();

    expect($collection->status)->toBe('cancelled')
        ->and($collection->cancelled_at)->not->toBeNull()
        ->and(Payment::query()->count())->toBe(0);

    $this->postJson('/api/cash-collections/'.$collection->id.'/cancel')->assertUnprocessable()->assertJsonPath('reason', 'not_pending');
});

test('a completed collection cannot be cancelled', function () {
    $scope = cclScope();
    $collection = cclMake($scope, ['status' => 'completed']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/cash-collections/'.$collection->id.'/cancel')->assertUnprocessable()->assertJsonPath('reason', 'not_pending');

    expect($collection->refresh()->status)->toBe('completed');
});

test('pending and cancelled collections can be deleted, restored and deleted for good', function () {
    $scope = cclScope();
    $pending = cclMake($scope);
    $cancelled = cclMake($scope, ['status' => 'cancelled']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->deleteJson('/api/cash-collections/'.$pending->id)->assertSuccessful();
    $this->postJson('/api/cash-collections/bulk_delete', [$cancelled->id])->assertSuccessful();

    expect(CashCollection::query()->count())->toBe(0)
        ->and(CashCollection::onlyTrashed()->count())->toBe(2);

    $this->getJson('/api/cash-collections/trash')->assertSuccessful()->assertJsonCount(2, 'data.data');
    $this->getJson('/api/cash-collections')->assertJsonPath('trash_count', 2);

    $this->postJson('/api/cash-collections/restore_records', [$pending->id, $cancelled->id])->assertSuccessful();
    expect(CashCollection::query()->count())->toBe(2);

    $this->postJson('/api/cash-collections/bulk_delete', [$pending->id])->assertSuccessful();
    $this->postJson('/api/cash-collections/bulk_delete_per', [$pending->id])->assertSuccessful();

    expect(CashCollection::withTrashed()->find($pending->id))->toBeNull();
});

test('a completed collection cannot be deleted: it is the record of its payments', function () {
    $scope = cclScope();
    $collection = cclMake($scope, ['status' => 'completed']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->deleteJson('/api/cash-collections/'.$collection->id)->assertSuccessful();
    $this->postJson('/api/cash-collections/bulk_delete', [$collection->id])->assertSuccessful();

    expect(CashCollection::query()->find($collection->id))->not->toBeNull();
});

// --- list and show -----------------------------------------------------------------------------

test('the list shows collections with names, searches, filters and sorts safely', function () {
    $scope = cclScope();
    $other = trpContact($scope, 'Beta Traders', 'customer', 'CU-BETA');
    cclMake($scope, ['amount' => 100, 'collected_on' => '2026-09-01', 'note' => 'shop counter']);
    cclMake($scope, ['amount' => 300, 'collected_on' => '2026-09-08', 'contact_id' => $other, 'status' => 'completed']);
    cclMake($scope, ['amount' => 200, 'collected_on' => '2026-09-05', 'status' => 'cancelled']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $refs = fn (array $query) => collect($this->getJson('/api/cash-collections?'.http_build_query($query))->assertSuccessful()->json('data.data'))->pluck('reference')->sort()->values()->all();

    expect($refs([]))->toBe(['CC-00001', 'CC-00002', 'CC-00003'])
        ->and($refs(['status' => 'pending']))->toBe(['CC-00001'])
        ->and($refs(['status' => 'completed']))->toBe(['CC-00002'])
        ->and($refs(['status' => 'cancelled']))->toBe(['CC-00003'])
        ->and($refs(['status' => 'bogus']))->toHaveCount(3)
        ->and($refs(['search' => 'Beta']))->toBe(['CC-00002'])
        ->and($refs(['search' => 'counter']))->toBe(['CC-00001'])
        ->and($refs(['search' => 'CC-00003']))->toBe(['CC-00003'])
        ->and($refs(['contact_id' => $other]))->toBe(['CC-00002'])
        ->and($refs(['from_date' => '2026-09-05']))->toBe(['CC-00002', 'CC-00003'])
        ->and($refs(['to_date' => '2026-09-05']))->toBe(['CC-00001', 'CC-00003'])
        ->and($refs(['from_date' => '2026-09-05', 'to_date' => '2026-09-05']))->toBe(['CC-00003'])
        ->and($refs(['branch_id' => $scope['branch_id']]))->toHaveCount(3)
        ->and($refs(['search' => 'nothing matches']))->toBe([]);

    $sorted = collect($this->getJson('/api/cash-collections?sort_by=amount&sort_type=desc')->json('data.data'));

    expect($sorted->pluck('amount')->all())->toBe([300, 200, 100])
        ->and($sorted->first()['contact_name'])->toBe('Beta Traders')
        ->and($sorted->first()['branch_name'])->toBe('Purchase Branch')
        ->and($sorted->first()['company_name'])->toBe('Purchase Test Company');

    $this->getJson('/api/cash-collections?sort_by='.urlencode('id; drop table cash_collections').'&sort_type=sideways')->assertSuccessful();
    $this->getJson('/api/cash-collections?sort_by=password')->assertSuccessful();
});

test('a collection shows its customer, collector and, once completed, the invoices it paid', function () {
    $scope = cclScope();
    $invoice = cclInvoice($scope, 'INV-A', 100);
    $collection = cclMake($scope, ['amount' => 100, 'collected_by' => 1]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $pending = $this->getJson('/api/cash-collections/'.$collection->id)->assertSuccessful();

    expect($pending->json('reference'))->toBe('CC-00001')
        ->and($pending->json('contact_name'))->toBe('Acme Retail')
        ->and($pending->json('branch_name'))->toBe('Purchase Branch')
        ->and($pending->json('collector_name'))->not->toBeNull()
        ->and($pending->json('allocations'))->toBe([]);

    $this->postJson('/api/cash-collections/'.$collection->id.'/complete', cclComplete($scope, [['transaction_id' => $invoice->id, 'amount' => 100]]))->assertSuccessful();

    $done = $this->getJson('/api/cash-collections/'.$collection->id)->assertSuccessful();

    expect($done->json('status'))->toBe('completed')
        ->and($done->json('allocations.0.invoice_no'))->toBe('INV-A')
        ->and($done->json('allocations.0.amount'))->toBe(100)
        ->and($done->json('allocations.0.payment_ref_no'))->not->toBeNull();

    $this->getJson('/api/cash-collections/999999')->assertNotFound();
});

// --- permissions and isolation -----------------------------------------------------------------

test('every write action is forbidden without its menu permission', function () {
    $scope = cclScope();
    $invoice = cclInvoice($scope, 'INV-A', 100);
    $collection = cclMake($scope);
    Sanctum::actingAs(cclStaff($scope));

    $this->postJson('/api/cash-collections', cclPayload($scope))->assertForbidden();
    $this->putJson('/api/cash-collections/'.$collection->id, cclPayload($scope, ['amount' => 5]))->assertForbidden();
    $this->postJson('/api/cash-collections/'.$collection->id.'/complete', cclComplete($scope, [['transaction_id' => $invoice->id, 'amount' => 100]]))->assertForbidden();
    $this->postJson('/api/cash-collections/'.$collection->id.'/cancel')->assertForbidden();

    // delete and restore answer "406" in place of doing anything
    $this->deleteJson('/api/cash-collections/'.$collection->id)->assertSuccessful()->assertContent('"406"');
    $this->postJson('/api/cash-collections/bulk_delete', [$collection->id])->assertSuccessful()->assertContent('"406"');

    expect(CashCollection::query()->count())->toBe(1)
        ->and($collection->refresh()->status)->toBe('pending')
        ->and($collection->amount)->toBe(100.0)
        ->and(Payment::query()->count())->toBe(0);
});

test('a company user with the permissions can record, edit, complete and cancel in their company', function () {
    $scope = cclScope();
    $invoice = cclInvoice($scope, 'INV-A', 100);
    Sanctum::actingAs(cclStaff($scope, ['/cashcollection/add', '/cashcollection/:id/edit', '/cashcollection/complete', '/cashcollection/cancel']));

    $this->postJson('/api/cash-collections', cclPayload($scope, ['company_id' => null, 'amount' => 90]))->assertSuccessful();
    $collection = CashCollection::query()->firstOrFail();

    $this->putJson('/api/cash-collections/'.$collection->id, cclPayload($scope, ['company_id' => null, 'amount' => 100]))->assertSuccessful();
    $this->postJson('/api/cash-collections/'.$collection->id.'/complete', cclComplete($scope, [['transaction_id' => $invoice->id, 'amount' => 100]]))->assertSuccessful();

    $second = cclMake($scope);
    $this->postJson('/api/cash-collections/'.$second->id.'/cancel')->assertSuccessful();

    expect($collection->refresh()->status)->toBe('completed')
        ->and($second->refresh()->status)->toBe('cancelled');
});

test('a user tied to a branch only records, sees and works on their own branch', function () {
    $scope = cclScope();
    $otherBranch = trpBranch($scope['company_id'], 'Second Branch');
    $mine = cclMake($scope);
    $theirs = cclMake($scope, ['branch_id' => $otherBranch]);
    Sanctum::actingAs(cclStaff($scope, ['/cashcollection/add', '/cashcollection/:id/edit', '/cashcollection/cancel'], 'collector', $scope['branch_id']));

    expect(collect($this->getJson('/api/cash-collections')->assertSuccessful()->json('data.data'))->pluck('id')->all())->toBe([$mine->id]);

    $this->postJson('/api/cash-collections', cclPayload($scope, ['branch_id' => $otherBranch]))->assertUnprocessable()->assertJsonValidationErrors(['branch_id']);
    $this->postJson('/api/cash-collections', cclPayload($scope))->assertSuccessful();

    $this->getJson('/api/cash-collections/'.$theirs->id)->assertNotFound();
    $this->putJson('/api/cash-collections/'.$theirs->id, cclPayload($scope, ['branch_id' => $otherBranch]))->assertNotFound();
    $this->postJson('/api/cash-collections/'.$theirs->id.'/cancel')->assertNotFound();

    expect($theirs->refresh()->status)->toBe('pending');
});

test('a company user cannot see or change the collections of another company', function () {
    $scope = cclScope();
    $mine = cclMake($scope);
    $otherCompany = DB::table('companies')->insertGetId(['code' => 'CCL002', 'name' => 'Other Co', 'address' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $otherBranch = trpBranch($otherCompany, 'Other Branch');
    $otherCustomer = trpContact(['company_id' => $otherCompany, 'branch_id' => $otherBranch], 'Other Customer', 'customer', 'CU-OTH');
    $theirs = CashCollection::query()->create([
        'company_id' => $otherCompany, 'branch_id' => $otherBranch, 'contact_id' => $otherCustomer, 'reference' => 'CC-00001',
        'collected_on' => '2026-09-01', 'amount' => 50, 'status' => 'pending',
    ]);
    Sanctum::actingAs(cclStaff($scope, ['/cashcollection/:id/edit', '/cashcollection/complete', '/cashcollection/cancel', '/cashcollection/delete']));

    expect(collect($this->getJson('/api/cash-collections')->assertSuccessful()->json('data.data'))->pluck('id')->all())->toBe([$mine->id]);

    $this->getJson('/api/cash-collections/'.$theirs->id)->assertNotFound();
    $this->postJson('/api/cash-collections/'.$theirs->id.'/cancel')->assertNotFound();
    $this->postJson('/api/cash-collections/'.$theirs->id.'/complete', cclComplete($scope, [['transaction_id' => 1, 'amount' => 50]]))->assertNotFound();
    $this->postJson('/api/cash-collections/bulk_delete', [$theirs->id])->assertSuccessful();

    expect($theirs->refresh()->status)->toBe('pending')
        ->and($theirs->trashed())->toBeFalse();
});

test('the cash collection api requires authentication', function () {
    $this->getJson('/api/cash-collections')->assertUnauthorized();
    $this->postJson('/api/cash-collections', [])->assertUnauthorized();
    $this->getJson('/api/cash-collections/open-invoices')->assertUnauthorized();
    $this->postJson('/api/cash-collections/1/complete', [])->assertUnauthorized();
    $this->postJson('/api/cash-collections/1/cancel')->assertUnauthorized();
});
