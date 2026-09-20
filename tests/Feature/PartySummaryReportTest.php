<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The customer & supplier summary: per contact, what was bought, sold and returned in the range, and
 * what is still to be received or paid. Both dues use one sign: positive is money still to move.
 */

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function pslQuery(array $extra = []): array
{
    return array_merge(['show_record' => 100], $extra);
}

test('a customer row carries sales, returns, receipts and the receivable due', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $customer = prpContact($scope, 'customer', 'Customer One');
    $first = trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-01', 'final_amount' => 1000]);
    $second = trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-05', 'final_amount' => 500]);
    trpDoc($scope, 'sellreturn', ['contact_id' => $customer, 'parent_id' => $first, 'transaction_date' => '2026-09-06', 'final_amount' => 100]);
    trpPayment($scope, $first, 300, '2026-09-10 10:00');
    trpPayment($scope, $first, 200, '2026-09-11 10:00');
    trpPayment($scope, $second, 500, '2026-09-11 10:00');

    $row = prpRow(prpGet('customer-supplier', pslQuery()), $customer);

    expect($row)->toMatchArray(['contact_name' => 'Customer One', 'user_type' => 'customer'])
        ->and($row['sales'])->toEqual(1500)
        ->and($row['sell_returns'])->toEqual(100)
        ->and($row['received'])->toEqual(1000)
        ->and($row['receivable_due'])->toEqual(400)
        ->and($row['purchases'])->toEqual(0)
        ->and($row['payable_due'])->toEqual(0);
});

test('a supplier owed the same way reads the same sign as a customer who owes', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $customer = prpContact($scope, 'customer', 'Owing Customer');
    $supplier = prpContact($scope, 'supplier', 'Owed Supplier');

    $sale = trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-01', 'final_amount' => 1000]);
    trpPayment($scope, $sale, 300, '2026-09-02 10:00');
    $purchase = trpDoc($scope, 'purchaseorder', ['contact_id' => $supplier, 'transaction_date' => '2026-09-01', 'status' => 'received', 'final_amount' => 1000]);
    trpPayment($scope, $purchase, 300, '2026-09-02 10:00');

    $response = prpGet('customer-supplier', pslQuery());

    // 700 still to collect and 700 still to pay: both positive, the old report gave -700 for the supplier.
    expect(prpRow($response, $customer)['receivable_due'])->toEqual(700)
        ->and(prpRow($response, $supplier)['payable_due'])->toEqual(700)
        ->and(prpRow($response, $supplier)['paid'])->toEqual(300)
        ->and(prpRow($response, $supplier)['user_type'])->toBe('supplier');
});

test('an overpayment is a negative due on either side', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $customer = prpContact($scope, 'customer', 'Overpaid Customer');
    $supplier = prpContact($scope, 'supplier', 'Overpaid Supplier');
    $sale = trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-01', 'final_amount' => 100]);
    trpPayment($scope, $sale, 160, '2026-09-02 10:00');
    $purchase = trpDoc($scope, 'purchaseorder', ['contact_id' => $supplier, 'transaction_date' => '2026-09-01', 'status' => 'received', 'final_amount' => 100]);
    trpPayment($scope, $purchase, 160, '2026-09-02 10:00');

    $response = prpGet('customer-supplier', pslQuery());

    expect(prpRow($response, $customer)['receivable_due'])->toEqual(-60)
        ->and(prpRow($response, $supplier)['payable_due'])->toEqual(-60);
});

test('purchase returns come off the payable due', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $supplier = prpContact($scope, 'supplier', 'Returns Supplier');
    $purchase = trpDoc($scope, 'purchaseorder', ['contact_id' => $supplier, 'transaction_date' => '2026-09-01', 'status' => 'received', 'final_amount' => 2000]);
    trpDoc($scope, 'purchasereturn', ['contact_id' => $supplier, 'parent_id' => $purchase, 'transaction_date' => '2026-09-02', 'final_amount' => 300]);
    trpPayment($scope, $purchase, 500, '2026-09-03 10:00');

    $row = prpRow(prpGet('customer-supplier', pslQuery()), $supplier);

    expect($row['purchases'])->toEqual(2000)
        ->and($row['purchase_returns'])->toEqual(300)
        ->and($row['payable_due'])->toEqual(1200);
});

test('a contact who is both has both dues on one row and nothing is netted', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $both = prpContact($scope, 'both', 'Both Ways');
    trpDoc($scope, 'sell', ['contact_id' => $both, 'transaction_date' => '2026-09-01', 'final_amount' => 900]);
    trpDoc($scope, 'purchaseorder', ['contact_id' => $both, 'transaction_date' => '2026-09-02', 'status' => 'received', 'final_amount' => 400]);

    $rows = prpRows(prpGet('customer-supplier', pslQuery()))->where('id', $both);

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['receivable_due'])->toEqual(900)
        ->and($rows->first()['payable_due'])->toEqual(400);
});

test('the dues equal what the party outstanding report shows for the same documents', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $customer = prpContact($scope, 'customer', 'Reconciled Customer');
    $supplier = prpContact($scope, 'supplier', 'Reconciled Supplier');
    $sale = trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-01', 'final_amount' => 1000]);
    trpDoc($scope, 'sellreturn', ['contact_id' => $customer, 'transaction_date' => '2026-09-02', 'final_amount' => 150]);
    trpPayment($scope, $sale, 250, '2026-09-03 10:00');
    $purchase = trpDoc($scope, 'purchaseorder', ['contact_id' => $supplier, 'transaction_date' => '2026-09-01', 'status' => 'received', 'final_amount' => 800]);
    trpPayment($scope, $purchase, 100, '2026-09-03 10:00');

    $summary = prpGet('customer-supplier', pslQuery());
    $day = ['end_date' => '2026-09-30'];

    expect(prpRow($summary, $customer)['receivable_due'])
        ->toEqual(prpRow(prpGet('customer-outstanding', pslQuery($day)), $customer)['balance'])
        ->and(prpRow($summary, $supplier)['payable_due'])
        ->toEqual(prpRow(prpGet('supplier-outstanding', pslQuery($day)), $supplier)['balance']);
});

test('payments on a return document are not counted, as in the ledger', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $customer = prpContact($scope, 'customer', 'Refunded');
    $sale = trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-01', 'final_amount' => 1000]);
    $return = trpDoc($scope, 'sellreturn', ['contact_id' => $customer, 'parent_id' => $sale, 'transaction_date' => '2026-09-02', 'final_amount' => 200]);
    trpPayment($scope, $return, 200, '2026-09-03 10:00', 'cash', ['is_return' => 1]);

    expect(prpRow(prpGet('customer-supplier', pslQuery()), $customer)['receivable_due'])->toEqual(800);
});

test('drafts and quotations are not counted and a deleted document is not counted', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $customer = prpContact($scope, 'customer', 'Statuses');
    $supplier = prpContact($scope, 'supplier', 'Draft Supplier');

    trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-01', 'status' => 'draft', 'final_amount' => 1]);
    trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-01', 'status' => 'quotation', 'final_amount' => 2]);
    trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-01', 'status' => 'issue', 'final_amount' => 4]);
    trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-01', 'status' => 'final', 'final_amount' => 8]);
    trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-01', 'final_amount' => 16, 'deleted_at' => now()]);
    trpDoc($scope, 'purchaseorder', ['contact_id' => $supplier, 'transaction_date' => '2026-09-01', 'status' => 'draft', 'final_amount' => 32]);

    $response = prpGet('customer-supplier', pslQuery());

    expect(prpRow($response, $customer)['sales'])->toEqual(12)
        ->and(prpRow($response, $supplier))->toBeNull();
});

test('the date range is inclusive at both ends and contacts with nothing in it are not listed', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $inside = prpContact($scope, 'customer', 'Inside');
    $outside = prpContact($scope, 'customer', 'Outside');
    trpDoc($scope, 'sell', ['contact_id' => $inside, 'transaction_date' => '2026-09-04 23:59:59', 'final_amount' => 1]);
    trpDoc($scope, 'sell', ['contact_id' => $inside, 'transaction_date' => '2026-09-05 00:00:00', 'final_amount' => 2]);
    trpDoc($scope, 'sell', ['contact_id' => $inside, 'transaction_date' => '2026-09-06 23:59:59', 'final_amount' => 4]);
    trpDoc($scope, 'sell', ['contact_id' => $inside, 'transaction_date' => '2026-09-07 00:00:00', 'final_amount' => 8]);
    trpDoc($scope, 'sell', ['contact_id' => $outside, 'transaction_date' => '2026-08-01', 'final_amount' => 16]);

    $response = prpGet('customer-supplier', pslQuery(['start_date' => '2026-09-05', 'end_date' => '2026-09-06']));

    expect(prpRow($response, $inside)['sales'])->toEqual(6)
        ->and(prpRow($response, $outside))->toBeNull()
        ->and(prpGet('customer-supplier', pslQuery(['start_date' => '2026-09-05']))->json('summary.sales'))->toEqual(14)
        ->and(prpGet('customer-supplier', pslQuery(['end_date' => '2026-09-05']))->json('summary.sales'))->toEqual(19);
});

test('the type filter keeps customers or suppliers and both-way contacts fall in each', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $customer = prpContact($scope, 'customer', 'Only Customer');
    $supplier = prpContact($scope, 'supplier', 'Only Supplier');
    $both = prpContact($scope, 'both', 'Both');
    trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-01', 'final_amount' => 1]);
    trpDoc($scope, 'purchaseorder', ['contact_id' => $supplier, 'transaction_date' => '2026-09-01', 'status' => 'received', 'final_amount' => 2]);
    trpDoc($scope, 'sell', ['contact_id' => $both, 'transaction_date' => '2026-09-01', 'final_amount' => 4]);

    $ids = fn (string $type): array => prpRows(prpGet('customer-supplier', pslQuery(['contact_type' => $type])))->pluck('id')->sort()->values()->all();
    $sorted = fn (array $ids): array => collect($ids)->sort()->values()->all();

    expect($ids('customer'))->toBe($sorted([$customer, $both]))
        ->and($ids('supplier'))->toBe($sorted([$supplier, $both]))
        ->and($ids('all'))->toBe($sorted([$customer, $supplier, $both]));

    prpGet('customer-supplier', pslQuery(['contact_type' => 'vendor']))->assertUnprocessable();
});

test('the report filters by customer group and contact, and searches', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $wholesale = prpGroup($scope, 'Wholesale');
    $alpha = prpContact($scope, 'customer', 'Alpha', ['customer_group_id' => $wholesale, 'code' => 'CU-A']);
    $beta = prpContact($scope, 'customer', 'Beta', ['code' => 'CU-B']);
    foreach ([$alpha, $beta] as $contact) {
        trpDoc($scope, 'sell', ['contact_id' => $contact, 'transaction_date' => '2026-09-01', 'final_amount' => 10]);
    }

    $ids = fn (array $extra): array => prpRows(prpGet('customer-supplier', pslQuery($extra)))->pluck('id')->all();

    expect($ids(['customer_group_id' => $wholesale]))->toBe([$alpha])
        ->and($ids(['contact_id' => $beta]))->toBe([$beta])
        ->and($ids(['search' => 'CU-B']))->toBe([$beta])
        ->and(prpRow(prpGet('customer-supplier', pslQuery()), $alpha)['group_name'])->toBe('Wholesale');
});

test('the summary totals the whole filtered set, not the page on screen', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $customer = prpContact($scope, 'customer', 'Total Customer');
    $supplier = prpContact($scope, 'supplier', 'Total Supplier');
    $sale = trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-01', 'final_amount' => 100]);
    trpDoc($scope, 'sellreturn', ['contact_id' => $customer, 'transaction_date' => '2026-09-02', 'final_amount' => 10]);
    trpPayment($scope, $sale, 30, '2026-09-03 10:00');
    trpDoc($scope, 'purchaseorder', ['contact_id' => $supplier, 'transaction_date' => '2026-09-01', 'status' => 'received', 'final_amount' => 200]);
    trpDoc($scope, 'purchasereturn', ['contact_id' => $supplier, 'transaction_date' => '2026-09-02', 'final_amount' => 20]);

    $response = prpGet('customer-supplier', pslQuery(['show_record' => 1]));

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('summary'))->toMatchArray(['count' => 2])
        ->and($response->json('summary.sales'))->toEqual(100)
        ->and($response->json('summary.sell_returns'))->toEqual(10)
        ->and($response->json('summary.purchases'))->toEqual(200)
        ->and($response->json('summary.purchase_returns'))->toEqual(20)
        ->and($response->json('summary.receivable_due'))->toEqual(60)
        ->and($response->json('summary.payable_due'))->toEqual(180);
});

test('rows sort by a known column and fall back to the highest sales first', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $small = prpContact($scope, 'customer', 'Aaa Small');
    $large = prpContact($scope, 'customer', 'Zzz Large');
    trpDoc($scope, 'sell', ['contact_id' => $small, 'transaction_date' => '2026-09-01', 'final_amount' => 10]);
    trpDoc($scope, 'sell', ['contact_id' => $large, 'transaction_date' => '2026-09-01', 'final_amount' => 999]);

    $order = fn (array $extra): array => prpRows(prpGet('customer-supplier', pslQuery($extra)))->pluck('id')->all();

    expect($order([]))->toBe([$large, $small])
        ->and($order(['sort_by' => 'contact_name', 'sort_type' => 'asc']))->toBe([$small, $large])
        ->and($order(['sort_by' => 'sales', 'sort_type' => 'asc']))->toBe([$small, $large])
        ->and($order(['sort_by' => 'x; drop table users']))->toBe([$large, $small]);
});

test('several payments on an invoice are counted once each and never multiply the document', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $customer = prpContact($scope, 'customer', 'Fan Out');
    $invoice = trpDoc($scope, 'sell', ['contact_id' => $customer, 'transaction_date' => '2026-09-01', 'final_amount' => 1000]);
    foreach ([100, 200, 300] as $amount) {
        trpPayment($scope, $invoice, $amount, '2026-09-02 10:00');
    }

    $row = prpRow(prpGet('customer-supplier', pslQuery()), $customer);

    expect($row['sales'])->toEqual(1000)
        ->and($row['received'])->toEqual(600)
        ->and($row['receivable_due'])->toEqual(400);
});

test('a company user sees only their own contacts and hostile input stays data', function () {
    $mine = trpScope('1');
    $theirs = trpScope('2');

    $own = prpContact($mine, 'customer', 'Mine');
    $other = prpContact($theirs, 'customer', 'Theirs');
    trpDoc($mine, 'sell', ['contact_id' => $own, 'transaction_date' => '2026-09-01', 'final_amount' => 10]);
    trpDoc($theirs, 'sell', ['contact_id' => $other, 'transaction_date' => '2026-09-01', 'final_amount' => 20]);

    Sanctum::actingAs(jeaUserWith($mine, ['/report/customer-supplier']));

    expect(prpRows(prpGet('customer-supplier', pslQuery(['company_id' => $theirs['company_id']])))->pluck('id')->all())->toBe([$own]);

    prpGet('customer-supplier', pslQuery(['search' => "' OR 1=1 --"]))->assertSuccessful()->assertJsonPath('summary.count', 0);
});

test('a branch user sees only the documents of their branch', function () {
    $scope = trpScope();
    $otherBranch = trpBranch($scope['company_id'], 'Second Branch');

    $contact = prpContact($scope, 'customer', 'Two Branches');
    trpDoc($scope, 'sell', ['contact_id' => $contact, 'transaction_date' => '2026-09-01', 'final_amount' => 10]);
    trpDoc($scope, 'sell', ['contact_id' => $contact, 'branch_id' => $otherBranch, 'transaction_date' => '2026-09-01', 'final_amount' => 20]);

    Sanctum::actingAs(jeaUserWith($scope, ['/report/customer-supplier']));
    expect(prpRow(prpGet('customer-supplier', pslQuery()), $contact)['sales'])->toEqual(10);

    trpActAsSuperadmin();
    expect(prpRow(prpGet('customer-supplier', pslQuery(['branch_id' => $otherBranch])), $contact)['sales'])->toEqual(20)
        ->and(prpRow(prpGet('customer-supplier', pslQuery()), $contact)['sales'])->toEqual(30);
});

test('an empty report is empty', function () {
    trpScope();
    trpActAsSuperadmin();

    prpGet('customer-supplier', pslQuery())
        ->assertSuccessful()
        ->assertJsonPath('data.total', 0)
        ->assertJsonPath('summary.count', 0)
        ->assertJsonPath('summary.receivable_due', 0);
});
