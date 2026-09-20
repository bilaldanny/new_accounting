<?php

use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Customer and supplier outstanding: the ledger closing balance of every contact on a given day. A
 * positive balance is money still to collect (customer) or to pay (supplier), a negative one an
 * advance or credit.
 */
const POR_AS_OF = '2026-09-20';

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function porQuery(array $extra = []): array
{
    return array_merge(['end_date' => POR_AS_OF, 'show_record' => 100], $extra);
}

test('a customer owes their sales less returns and payments', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $customer = prpContact($scope, 'customer', 'Debtor');
    $invoice = trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-01', 'final_amount' => 1000]);
    trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-05', 'final_amount' => 500]);
    trpDoc($scope, 'sellreturn', ['contact_id' => $customer, 'parent_id' => $invoice, 'transaction_date' => '2026-09-06', 'final_amount' => 100]);
    trpPayment($scope, $invoice, 300, '2026-09-10 10:00');

    $row = prpRow(prpGet('customer-outstanding', porQuery()), $customer);

    expect($row['balance'])->toEqual(1100)
        ->and($row['position'])->toBe('due')
        ->and($row['contact_name'])->toBe('Debtor');
});

test('a supplier is owed their purchases less returns and payments', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $supplier = prpContact($scope, 'supplier', 'Creditor');
    $purchase = trpDoc($scope, 'purchaseorder', ['contact_id' => $supplier, 'transaction_date' => '2026-09-01', 'status' => 'received', 'final_amount' => 2000]);
    trpDoc($scope, 'purchasereturn', ['contact_id' => $supplier, 'parent_id' => $purchase, 'transaction_date' => '2026-09-02', 'final_amount' => 200]);
    trpPayment($scope, $purchase, 800, '2026-09-03 10:00');

    $row = prpRow(prpGet('supplier-outstanding', porQuery()), $supplier);

    expect($row['balance'])->toEqual(1000)
        ->and($row['position'])->toBe('due');
});

test('drafts and quotations are not owed', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $customer = prpContact($scope, 'customer', 'Statuses');
    trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-01', 'status' => 'draft', 'final_amount' => 1]);
    trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-01', 'status' => 'quotation', 'final_amount' => 2]);
    trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-01', 'status' => 'issue', 'final_amount' => 4]);
    trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-01', 'status' => 'final', 'final_amount' => 8]);

    expect(prpRow(prpGet('customer-outstanding', porQuery()), $customer)['balance'])->toEqual(12);
});

test('the balance is the same figure the party ledger closes on', function () {
    $scope = trpScope();
    prpFinancialYear($scope['company_id']);
    trpActAsSuperadmin();

    $customer = prpLinkedContact($scope, 'customer', '101-00010', 'Ledger Customer');
    $supplier = prpLinkedContact($scope, 'supplier', '311-00010', 'Ledger Supplier');
    prpOpeningBalance($scope, $customer['coa_id'], 2500, 'dr');
    prpOpeningBalance($scope, $supplier['coa_id'], 10000, 'cr');

    $sale = trpDoc($scope, 'sell', ['contact_id' => $customer['id'], 'transaction_date' => '2026-08-15', 'final_amount' => 5000]);
    trpDoc($scope, 'sellreturn', ['contact_id' => $customer['id'], 'transaction_date' => '2026-08-20', 'final_amount' => 400]);
    trpPayment($scope, $sale, 1200, '2026-09-01 10:00');
    $purchase = trpDoc($scope, 'purchaseorder', ['contact_id' => $supplier['id'], 'transaction_date' => '2026-08-23', 'status' => 'received', 'final_amount' => 23000]);
    trpPayment($scope, $purchase, 3000, '2026-09-04 10:00');

    $customerLedger = test()->getJson("/api/fetchledger?contact_id={$customer['id']}&start_date=2026-07-01&end_date=".POR_AS_OF)->json('closingbalance');
    $supplierLedger = test()->getJson("/api/fetchledger?contact_id={$supplier['id']}&start_date=2026-07-01&end_date=".POR_AS_OF)->json('closingbalance');

    expect($customerLedger)->toBe(5900)
        ->and(prpRow(prpGet('customer-outstanding', porQuery()), $customer['id'])['balance'])->toEqual($customerLedger)
        ->and($supplierLedger)->toBe(30000)
        ->and(prpRow(prpGet('supplier-outstanding', porQuery()), $supplier['id'])['balance'])->toEqual($supplierLedger);
});

test('journal postings take the place of the documents, as they do in the ledger', function () {
    $scope = trpScope();
    prpFinancialYear($scope['company_id']);
    trpActAsSuperadmin();

    $customer = prpLinkedContact($scope, 'customer', '101-00010', 'Journal Customer');
    prpOpeningBalance($scope, $customer['coa_id'], 1000, 'dr');

    trpDoc($scope, 'sell', ['contact_id' => $customer['id'], 'transaction_date' => '2026-08-15', 'final_amount' => 9999]);
    prpJournalLine($scope, $customer['code'], $customer['id'], 4000, 0, '2026-08-15', 'dr');
    prpJournalLine($scope, $customer['code'], $customer['id'], 0, 1500, '2026-09-01', 'dr');
    prpJournalLine($scope, $customer['code'], $customer['id'], 700, 0, '2026-09-21', 'dr');

    // 1000 opening + 4000 - 1500; the voucher dated after the as-of day and the document are not in.
    expect(prpRow(prpGet('customer-outstanding', porQuery()), $customer['id'])['balance'])->toEqual(3500)
        ->and(prpRow(prpGet('customer-outstanding', porQuery(['end_date' => '2026-09-21'])), $customer['id'])['balance'])->toEqual(4200);
});

test('a contact without a chart-of-accounts account is measured from its documents', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $noAccount = prpContact($scope, 'customer', 'No Account');
    $danglingCode = prpContact($scope, 'customer', 'Dangling Account', ['customer_gl_id' => '999-99999', 'link_account' => true]);
    trpDoc($scope, 'sell', ['contact_id' => $noAccount, 'transaction_date' => '2026-09-01', 'final_amount' => 300]);
    trpDoc($scope, 'sell', ['contact_id' => $danglingCode, 'transaction_date' => '2026-09-01', 'final_amount' => 700]);

    $response = prpGet('customer-outstanding', porQuery())->assertSuccessful();

    expect(prpRow($response, $noAccount)['balance'])->toEqual(300)
        ->and(prpRow($response, $noAccount)['account_code'])->toBe('')
        ->and(prpRow($response, $danglingCode)['balance'])->toEqual(700)
        ->and(prpRow($response, $danglingCode)['account_code'])->toBe('999-99999');
});

test('the as-of day is inclusive and nothing after it counts', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $customer = prpContact($scope, 'customer', 'Dates');
    $invoice = trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-20 23:59:59', 'final_amount' => 100]);
    trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-21 00:00:00', 'final_amount' => 1000]);
    trpPayment($scope, $invoice, 40, '2026-09-20 18:00');
    trpPayment($scope, $invoice, 60, '2026-09-21 09:00');

    $balance = fn (string $day): float => (float) prpRow(prpGet('customer-outstanding', porQuery(['end_date' => $day, 'include_zero' => 1])), $customer)['balance'];

    expect($balance('2026-09-19'))->toBe(0.0)
        ->and($balance('2026-09-20'))->toBe(60.0)
        ->and($balance('2026-09-21'))->toBe(1000.0);
});

test('a settled customer is left out unless asked for, and an advance shows as a credit', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    // The scope's own customer (Acme, no documents) is a fifth contact and settled.
    $owing = prpContact($scope, 'customer', 'Owing');
    $settled = prpContact($scope, 'customer', 'Settled');
    $advance = prpContact($scope, 'customer', 'Advance');
    $quiet = prpContact($scope, 'customer', 'Never Traded');

    trpDoc($scope, 'sell', ['contact_id' => $owing, 'transaction_date' => '2026-09-01', 'final_amount' => 500]);
    $paid = trpDoc($scope, 'sell', ['contact_id' => $settled, 'transaction_date' => '2026-09-01', 'final_amount' => 300]);
    trpPayment($scope, $paid, 300, '2026-09-02 10:00');
    $over = trpDoc($scope, 'sell', ['contact_id' => $advance, 'transaction_date' => '2026-09-01', 'final_amount' => 200]);
    trpPayment($scope, $over, 260, '2026-09-02 10:00');

    $default = prpGet('customer-outstanding', porQuery());
    $withZero = prpGet('customer-outstanding', porQuery(['include_zero' => 'true']));

    expect($default->json('data.total'))->toBe(2)
        ->and(prpRow($default, $settled))->toBeNull()
        ->and(prpRow($default, $quiet))->toBeNull()
        ->and(prpRow($default, $advance)['balance'])->toEqual(-60)
        ->and(prpRow($default, $advance)['position'])->toBe('advance')
        ->and($withZero->json('data.total'))->toBe(5)
        ->and(prpRow($withZero, $settled)['position'])->toBe('settled')
        ->and(prpRow($withZero, $quiet)['balance'])->toEqual(0);

    expect($default->json('summary'))->toMatchArray(['count' => 2, 'as_of' => POR_AS_OF])
        ->and($default->json('summary.total_due'))->toEqual(500)
        ->and($default->json('summary.total_advance'))->toEqual(60)
        ->and($default->json('summary.net'))->toEqual(440);
});

test('a contact who is both customer and supplier shows each side in its own report', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $both = prpContact($scope, 'both', 'Both Ways');
    trpDoc($scope, 'sell', ['contact_id' => $both, 'transaction_date' => '2026-09-01', 'final_amount' => 900]);
    $purchase = trpDoc($scope, 'purchaseorder', ['contact_id' => $both, 'transaction_date' => '2026-09-02', 'status' => 'received', 'final_amount' => 400]);
    trpPayment($scope, $purchase, 100, '2026-09-03 10:00');

    expect(prpRow(prpGet('customer-outstanding', porQuery()), $both)['balance'])->toEqual(900)
        ->and(prpRow(prpGet('supplier-outstanding', porQuery()), $both)['balance'])->toEqual(300);
});

test('only customers are in the customer report and only suppliers in the supplier report', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $customer = prpContact($scope, 'customer', 'A Customer');
    $supplier = prpContact($scope, 'supplier', 'A Supplier');
    trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-01', 'final_amount' => 10]);
    trpDoc($scope, 'purchaseorder', ['contact_id' => $supplier, 'transaction_date' => '2026-09-01', 'status' => 'received', 'final_amount' => 20]);

    expect(prpRows(prpGet('customer-outstanding', porQuery()))->pluck('id')->all())->toBe([$customer])
        ->and(prpRows(prpGet('supplier-outstanding', porQuery()))->pluck('id')->all())->toBe([$supplier]);
});

test('inactive contacts still owing are listed and deleted ones are not', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $inactive = prpContact($scope, 'customer', 'Gone Quiet', ['active' => false]);
    $deleted = prpContact($scope, 'customer', 'Deleted');
    trpDoc($scope, 'sell', ['contact_id' => $inactive, 'transaction_date' => '2026-09-01', 'final_amount' => 10]);
    trpDoc($scope, 'sell', ['contact_id' => $deleted, 'transaction_date' => '2026-09-01', 'final_amount' => 20]);
    Contact::query()->findOrFail($deleted)->delete();

    expect(prpRows(prpGet('customer-outstanding', porQuery()))->pluck('id')->all())->toBe([$inactive]);
});

test('the report filters by contact and customer group and searches by name and code', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $wholesale = prpGroup($scope, 'Wholesale');
    $retail = prpGroup($scope, 'Retail');

    $alpha = prpContact($scope, 'customer', 'Alpha Traders', ['code' => 'CU-ALPHA', 'customer_group_id' => $wholesale]);
    $beta = prpContact($scope, 'customer', 'Beta Stores', ['code' => 'CU-BETA', 'customer_group_id' => $retail]);
    $person = prpContact($scope, 'customer', '', ['first_name' => 'Zara', 'last_name' => 'Iqbal', 'code' => 'CU-ZARA']);

    foreach ([$alpha, $beta, $person] as $contact) {
        trpDoc($scope, 'sell', ['contact_id' => $contact, 'transaction_date' => '2026-09-01', 'final_amount' => 50]);
    }

    $ids = fn (array $extra): array => prpRows(prpGet('customer-outstanding', porQuery($extra)))->pluck('id')->sort()->values()->all();

    expect($ids(['contact_id' => $beta]))->toBe([$beta])
        ->and($ids(['customer_group_id' => $wholesale]))->toBe([$alpha])
        ->and($ids(['search' => 'stores']))->toBe([$beta])
        ->and($ids(['search' => 'CU-ZARA']))->toBe([$person])
        ->and($ids(['search' => 'iqbal']))->toBe([$person])
        ->and(prpRow(prpGet('customer-outstanding', porQuery()), $alpha)['group_name'])->toBe('Wholesale')
        ->and(prpRow(prpGet('customer-outstanding', porQuery()), $person)['group_name'])->toBe('')
        ->and(prpRow(prpGet('customer-outstanding', porQuery()), $person)['contact_name'])->toBe('Zara Iqbal');
});

test('rows sort by a known column and fall back to the largest balance first', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $small = prpContact($scope, 'customer', 'Aaa Small');
    $large = prpContact($scope, 'customer', 'Zzz Large');
    trpDoc($scope, 'sell', ['contact_id' => $small, 'transaction_date' => '2026-09-01', 'final_amount' => 10]);
    trpDoc($scope, 'sell', ['contact_id' => $large, 'transaction_date' => '2026-09-01', 'final_amount' => 999]);

    $order = fn (array $extra): array => prpRows(prpGet('customer-outstanding', porQuery($extra)))->pluck('id')->all();

    expect($order([]))->toBe([$large, $small])
        ->and($order(['sort_by' => 'contact_name', 'sort_type' => 'asc']))->toBe([$small, $large])
        ->and($order(['sort_by' => 'balance', 'sort_type' => 'asc']))->toBe([$small, $large])
        ->and($order(['sort_by' => 'nope; drop table users']))->toBe([$large, $small]);
});

test('a page past the end shows the last page', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    foreach (range(1, 3) as $n) {
        trpDoc($scope, 'sell', ['contact_id' => prpContact($scope, 'customer', "Customer {$n}"), 'transaction_date' => '2026-09-01', 'final_amount' => $n]);
    }

    $response = prpGet('customer-outstanding', porQuery(['show_record' => 2, 'cur_page' => 9]));

    expect($response->json('data.current_page'))->toBe(2)
        ->and($response->json('data.last_page'))->toBe(2)
        ->and($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('summary.count'))->toBe(3);
});

test('a company user sees only their own contacts and hostile input stays data', function () {
    $mine = trpScope('1');
    $theirs = trpScope('2');

    $own = prpContact($mine, 'customer', 'Mine');
    $other = prpContact($theirs, 'customer', 'Theirs');
    trpDoc($mine, 'sell', ['contact_id' => $own, 'transaction_date' => '2026-09-01', 'final_amount' => 10]);
    trpDoc($theirs, 'sell', ['contact_id' => $other, 'transaction_date' => '2026-09-01', 'final_amount' => 20]);

    Sanctum::actingAs(jeaUserWith($mine, ['/report/customer-outstanding']));

    expect(prpRows(prpGet('customer-outstanding', porQuery(['company_id' => $theirs['company_id']])))->pluck('id')->all())->toBe([$own]);

    prpGet('customer-outstanding', porQuery(['search' => "' OR 1=1 --", 'contact_id' => 'x']))->assertUnprocessable();
    prpGet('customer-outstanding', porQuery(['search' => "' OR 1=1 --"]))->assertSuccessful()->assertJsonPath('summary.count', 0);
});

test('a branch user sees only the contacts and activity of their branch', function () {
    $scope = trpScope();
    $otherBranch = trpBranch($scope['company_id'], 'Second Branch');
    $otherScope = array_merge($scope, ['branch_id' => $otherBranch]);

    $here = prpContact($scope, 'customer', 'Here');
    $there = prpContact($otherScope, 'customer', 'There');
    trpDoc($scope, 'sell', ['contact_id' => $here, 'transaction_date' => '2026-09-01', 'final_amount' => 10]);
    trpDoc($otherScope, 'sell', ['contact_id' => $there, 'transaction_date' => '2026-09-01', 'final_amount' => 20]);

    Sanctum::actingAs(jeaUserWith($scope, ['/report/customer-outstanding']));
    expect(prpRows(prpGet('customer-outstanding', porQuery()))->pluck('id')->all())->toBe([$here]);

    trpActAsSuperadmin();
    expect(prpRows(prpGet('customer-outstanding', porQuery(['branch_id' => $otherBranch])))->pluck('id')->all())->toBe([$there])
        ->and(prpRows(prpGet('customer-outstanding', porQuery()))->pluck('id')->sort()->values()->all())->toBe(collect([$here, $there])->sort()->values()->all());
});

test('the report is empty when nobody owes anything', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    prpContact($scope, 'customer', 'Nothing Owed');

    prpGet('customer-outstanding', porQuery())
        ->assertSuccessful()
        ->assertJsonPath('data.total', 0)
        ->assertJsonPath('summary.total_due', 0)
        ->assertJsonPath('summary.net', 0);

    expect(DB::table('contacts')->count())->toBeGreaterThan(0);
});
