<?php

use App\Services\Reports\PartyAgingReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Customer and supplier aging: the open balance of every invoice, sorted by days past its due date
 * (0-30, 31-60, 61-90, 90+, or not yet due), one row per contact. Every test asks for the same
 * as-of day so the days are known.
 */
const PAR_AS_OF = '2026-09-20';

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function parQuery(array $extra = []): array
{
    return array_merge(['end_date' => PAR_AS_OF, 'show_record' => 100], $extra);
}

dataset('aging edges', [
    'due today' => ['2026-09-20', null, 0, 'days_0_30'],
    'due tomorrow' => ['2026-09-19', 2, 0, 'not_due'],
    'due in ten days' => ['2026-09-15', 15, 0, 'not_due'],
    '30 days past due' => ['2026-08-21', null, 0, 'days_0_30'],
    '31 days past due' => ['2026-08-20', null, 0, 'days_31_60'],
    '60 days past due' => ['2026-07-22', null, 0, 'days_31_60'],
    '61 days past due' => ['2026-07-21', null, 0, 'days_61_90'],
    '90 days past due' => ['2026-06-22', null, 0, 'days_61_90'],
    '91 days past due' => ['2026-06-21', null, 0, 'days_90_plus'],
    'a year old' => ['2025-09-20', null, 0, 'days_90_plus'],
    'the pay term moves the due date' => ['2026-08-01', 30, 'day', 'days_0_30'],
]);

test('an invoice lands in the bucket its days past the due date say', function (string $invoiceDate, ?int $payTerm, int|string $unit, string $bucket) {
    $scope = trpScope();
    trpActAsSuperadmin();

    $contact = prpContact($scope, 'customer', 'Bucket Customer');
    trpDoc($scope, 'sell', [
        'contact_id' => $contact,
        'transaction_date' => $invoiceDate.' 09:00:00',
        'pay_term' => $payTerm,
        'pay_type' => 'day',
        'final_amount' => 1000,
    ]);

    $row = prpRow(prpGet('customer-aging', parQuery()), $contact);

    expect($row)->not->toBeNull()
        ->and($row[$bucket])->toEqual(1000)
        ->and($row['total'])->toEqual(1000);

    foreach (array_keys(PartyAgingReport::BUCKETS) as $other) {
        if ($other !== $bucket) {
            expect($row[$other])->toEqual(0);
        }
    }
})->with('aging edges');

test('the pay term comes from the invoice, then the contact, and with none the invoice date is the due date', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $withTerm = prpContact($scope, 'customer', 'Contact Term', ['pay_term' => 60, 'pay_type' => 'day']);
    $noTerm = prpContact($scope, 'customer', 'No Term', ['pay_term' => null]);
    $zeroTerm = prpContact($scope, 'customer', 'Zero Term', ['pay_term' => 0]);

    // 2026-08-01 + 60 days = 2026-09-30, still ahead.
    trpDoc($scope, 'sell', ['contact_id' => $withTerm, 'transaction_date' => '2026-08-01', 'pay_term' => null, 'final_amount' => 100]);
    // The invoice's own 10 days wins over the contact's 60: due 2026-08-11, 40 days past.
    trpDoc($scope, 'sell', ['contact_id' => $withTerm, 'transaction_date' => '2026-08-01', 'pay_term' => 10, 'final_amount' => 200]);
    // No term anywhere: aged from the invoice date, 50 days.
    trpDoc($scope, 'sell', ['contact_id' => $noTerm, 'transaction_date' => '2026-08-01', 'pay_term' => null, 'final_amount' => 300]);
    trpDoc($scope, 'sell', ['contact_id' => $zeroTerm, 'transaction_date' => '2026-08-01', 'pay_term' => '0', 'final_amount' => 400]);

    $response = prpGet('customer-aging', parQuery());

    expect(prpRow($response, $withTerm)['not_due'])->toEqual(100)
        ->and(prpRow($response, $withTerm)['days_31_60'])->toEqual(200)
        ->and(prpRow($response, $noTerm)['days_31_60'])->toEqual(300)
        ->and(prpRow($response, $zeroTerm)['days_31_60'])->toEqual(400);
});

test('a pay term in months and years does not overflow the month end', function () {
    expect(PartyAgingReport::dueDate('2026-01-31', 1, 'month', null, null)->toDateString())->toBe('2026-02-28')
        ->and(PartyAgingReport::dueDate('2024-02-29', 1, 'year', null, null)->toDateString())->toBe('2025-02-28')
        ->and(PartyAgingReport::dueDate('2026-07-31', null, null, 2, 'MONTH')->toDateString())->toBe('2026-09-30')
        ->and(PartyAgingReport::dueDate('2026-07-10 23:59:59', '5.0', 'day', null, null)->toDateString())->toBe('2026-07-15')
        ->and(PartyAgingReport::dueDate('2026-07-10', null, null, null, null)->toDateString())->toBe('2026-07-10')
        ->and(PartyAgingReport::dueDate('2026-07-10', 'abc', 'day', -3, 'day')->toDateString())->toBe('2026-07-10');
});

test('a month term ages an invoice from the month it falls due', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $contact = prpContact($scope, 'customer', 'Monthly Customer');
    trpDoc($scope, 'sell', ['contact_id' => $contact, 'transaction_date' => '2026-06-20', 'pay_term' => 2, 'pay_type' => 'month', 'final_amount' => 500]);

    // Due 2026-08-20, so exactly 31 days past on 2026-09-20.
    expect(prpRow(prpGet('customer-aging', parQuery()), $contact)['days_31_60'])->toEqual(500);
});

test('only the balance still open is aged, whole or partly paid', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $unpaid = prpContact($scope, 'customer', 'Unpaid');
    $partly = prpContact($scope, 'customer', 'Partly Paid');
    $settled = prpContact($scope, 'customer', 'Settled');

    trpDoc($scope, 'sell', ['contact_id' => $unpaid, 'transaction_date' => '2026-08-01', 'final_amount' => 1000]);

    $invoice = trpDoc($scope, 'sell', ['contact_id' => $partly, 'transaction_date' => '2026-08-01', 'final_amount' => 1000, 'payment_status' => 'partial']);
    trpPayment($scope, $invoice, 250, '2026-08-05 10:00');
    trpPayment($scope, $invoice, 150, '2026-08-20 10:00');

    $paid = trpDoc($scope, 'sell', ['contact_id' => $settled, 'transaction_date' => '2026-08-01', 'final_amount' => 1000, 'payment_status' => 'paid']);
    trpPayment($scope, $paid, 1000, '2026-08-02 10:00');

    $response = prpGet('customer-aging', parQuery());

    expect(prpRow($response, $unpaid)['total'])->toEqual(1000)
        ->and(prpRow($response, $partly)['total'])->toEqual(600)
        ->and(prpRow($response, $partly)['days_31_60'])->toEqual(600)
        ->and(prpRow($response, $settled))->toBeNull();
});

test('each invoice is aged on its own open balance, not a share of the contact total', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $contact = prpContact($scope, 'customer', 'Two Invoices');
    $old = trpDoc($scope, 'sell', ['contact_id' => $contact, 'transaction_date' => '2026-05-01', 'final_amount' => 800]);
    trpDoc($scope, 'sell', ['contact_id' => $contact, 'transaction_date' => '2026-09-01', 'pay_term' => 30, 'final_amount' => 300]);
    trpPayment($scope, $old, 300, '2026-06-01 09:00');

    $row = prpRow(prpGet('customer-aging', parQuery()), $contact);

    expect($row['days_90_plus'])->toEqual(500)
        ->and($row['not_due'])->toEqual(300)
        ->and($row['total'])->toEqual(800)
        ->and($row['invoices'])->toBe(2)
        ->and($row['oldest_days'])->toBe(142);
});

test('a payment after the as-of day does not reduce that day, and later invoices do not exist yet', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $contact = prpContact($scope, 'customer', 'History');
    $invoice = trpDoc($scope, 'sell', ['contact_id' => $contact, 'transaction_date' => '2026-08-01', 'final_amount' => 1000]);
    trpPayment($scope, $invoice, 400, '2026-09-20 23:59');
    trpPayment($scope, $invoice, 600, '2026-09-21 00:00');
    trpDoc($scope, 'sell', ['contact_id' => $contact, 'transaction_date' => '2026-09-21', 'final_amount' => 9999]);

    $asOfDay = prpRow(prpGet('customer-aging', parQuery()), $contact);
    $nextDay = prpRow(prpGet('customer-aging', parQuery(['end_date' => '2026-09-21'])), $contact);

    expect($asOfDay['total'])->toEqual(600)
        ->and($nextDay['total'])->toEqual(9999);
});

test('a return raised against an invoice takes its share off the open balance', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $contact = prpContact($scope, 'customer', 'Returns');
    $covered = prpContact($scope, 'customer', 'Fully Returned');

    $invoice = trpDoc($scope, 'sell', ['contact_id' => $contact, 'transaction_date' => '2026-08-01', 'final_amount' => 1000]);
    trpDoc($scope, 'sellreturn', ['contact_id' => $contact, 'parent_id' => $invoice, 'transaction_date' => '2026-08-10', 'final_amount' => 250]);
    trpDoc($scope, 'sellreturn', ['contact_id' => $contact, 'parent_id' => $invoice, 'transaction_date' => '2026-09-30', 'final_amount' => 500]);

    $paidAndReturned = trpDoc($scope, 'sell', ['contact_id' => $covered, 'transaction_date' => '2026-08-01', 'final_amount' => 1000]);
    trpPayment($scope, $paidAndReturned, 1000, '2026-08-02 10:00');
    trpDoc($scope, 'sellreturn', ['contact_id' => $covered, 'parent_id' => $paidAndReturned, 'transaction_date' => '2026-08-15', 'final_amount' => 400]);

    $response = prpGet('customer-aging', parQuery());

    // The 500 return is dated after the as-of day and does not count yet.
    expect(prpRow($response, $contact)['total'])->toEqual(750)
        ->and(prpRow($response, $covered))->toBeNull();
});

test('drafts and quotations are not invoices and other documents never age', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $contact = prpContact($scope, 'customer', 'Statuses');

    trpDoc($scope, 'sell', ['contact_id' => $contact, 'transaction_date' => '2026-08-01', 'status' => 'draft', 'final_amount' => 1]);
    trpDoc($scope, 'sell', ['contact_id' => $contact, 'transaction_date' => '2026-08-01', 'status' => 'quotation', 'final_amount' => 2]);
    trpDoc($scope, 'sell', ['contact_id' => $contact, 'transaction_date' => '2026-08-01', 'status' => 'issue', 'final_amount' => 4]);
    trpDoc($scope, 'sell', ['contact_id' => $contact, 'transaction_date' => '2026-08-01', 'status' => 'final', 'final_amount' => 8]);
    trpDoc($scope, 'purchaseorder', ['contact_id' => $contact, 'transaction_date' => '2026-08-01', 'final_amount' => 16]);
    trpDoc($scope, 'sell', ['contact_id' => $contact, 'transaction_date' => '2026-08-01', 'final_amount' => 32, 'deleted_at' => now()]);

    expect(prpRow(prpGet('customer-aging', parQuery()), $contact)['total'])->toEqual(12);
});

test('supplier aging ages purchases, and a draft purchase is left out', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $supplier = prpContact($scope, 'supplier', 'Aged Supplier', ['pay_term' => 15, 'pay_type' => 'day']);

    $purchase = trpDoc($scope, 'purchaseorder', ['contact_id' => $supplier, 'transaction_date' => '2026-07-01', 'status' => 'received', 'final_amount' => 2000]);
    trpPayment($scope, $purchase, 500, '2026-07-10 10:00');
    trpDoc($scope, 'purchaseorder', ['contact_id' => $supplier, 'transaction_date' => '2026-07-01', 'status' => 'draft', 'final_amount' => 77]);
    trpDoc($scope, 'sell', ['contact_id' => $supplier, 'transaction_date' => '2026-07-01', 'final_amount' => 88]);
    trpDoc($scope, 'purchasereturn', ['contact_id' => $supplier, 'parent_id' => $purchase, 'transaction_date' => '2026-07-20', 'final_amount' => 300]);

    // Due 2026-07-16, 66 days past.
    $row = prpRow(prpGet('supplier-aging', parQuery()), $supplier);

    // The sell on the same contact is a customer invoice: it is not in the supplier report.
    expect($row['days_61_90'])->toEqual(1200)
        ->and($row['total'])->toEqual(1200)
        ->and(prpRow(prpGet('customer-aging', parQuery()), $supplier)['total'])->toEqual(88);
});

test('the summary totals every bucket over the whole filtered set', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $first = prpContact($scope, 'customer', 'First');
    $second = prpContact($scope, 'customer', 'Second');
    $third = prpContact($scope, 'customer', 'Third');

    trpDoc($scope, 'sell', ['contact_id' => $first, 'transaction_date' => '2026-09-19', 'pay_term' => 10, 'final_amount' => 10]);
    trpDoc($scope, 'sell', ['contact_id' => $first, 'transaction_date' => '2026-09-10', 'final_amount' => 20]);
    trpDoc($scope, 'sell', ['contact_id' => $second, 'transaction_date' => '2026-08-01', 'final_amount' => 40]);
    trpDoc($scope, 'sell', ['contact_id' => $second, 'transaction_date' => '2026-07-01', 'final_amount' => 80]);
    trpDoc($scope, 'sell', ['contact_id' => $third, 'transaction_date' => '2026-01-01', 'final_amount' => 160]);

    $response = prpGet('customer-aging', parQuery(['show_record' => 1]));

    expect($response->json('data.total'))->toBe(3)
        ->and($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('summary'))->toMatchArray([
            'count' => 3,
            'invoices' => 5,
            'as_of' => PAR_AS_OF,
        ])
        ->and($response->json('summary.not_due'))->toEqual(10)
        ->and($response->json('summary.days_0_30'))->toEqual(20)
        ->and($response->json('summary.days_31_60'))->toEqual(40)
        ->and($response->json('summary.days_61_90'))->toEqual(80)
        ->and($response->json('summary.days_90_plus'))->toEqual(160)
        ->and($response->json('summary.total'))->toEqual(310);
});

test('the report as of today ages against today', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $contact = prpContact($scope, 'customer', 'Today');
    trpDoc($scope, 'sell', ['contact_id' => $contact, 'transaction_date' => now()->subDays(45)->toDateString(), 'final_amount' => 90]);

    $response = prpGet('customer-aging', ['show_record' => 100]);

    expect(prpRow($response, $contact)['days_31_60'])->toEqual(90)
        ->and($response->json('summary.as_of'))->toBe(now()->toDateString());
});

test('the report filters by contact and searches by name and code', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $alpha = prpContact($scope, 'customer', 'Alpha Traders', ['code' => 'CU-ALPHA']);
    $beta = prpContact($scope, 'customer', 'Beta Stores', ['code' => 'CU-BETA']);
    $person = prpContact($scope, 'customer', '', ['first_name' => 'Zara', 'last_name' => 'Iqbal', 'code' => 'CU-ZARA']);

    foreach ([$alpha, $beta, $person] as $contact) {
        trpDoc($scope, 'sell', ['contact_id' => $contact, 'transaction_date' => '2026-08-01', 'final_amount' => 50]);
    }

    $ids = fn (array $extra): array => prpRows(prpGet('customer-aging', parQuery($extra)))->pluck('id')->sort()->values()->all();

    expect($ids(['contact_id' => $beta]))->toBe([$beta])
        ->and($ids(['search' => 'alpha']))->toBe([$alpha])
        ->and($ids(['search' => 'CU-BETA']))->toBe([$beta])
        ->and($ids(['search' => 'iqbal']))->toBe([$person])
        ->and(prpRow(prpGet('customer-aging', parQuery()), $person)['contact_name'])->toBe('Zara Iqbal');
});

test('rows sort by a known column and fall back to the largest total first', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $small = prpContact($scope, 'customer', 'Aaa Small');
    $large = prpContact($scope, 'customer', 'Zzz Large');
    trpDoc($scope, 'sell', ['contact_id' => $small, 'transaction_date' => '2026-08-01', 'final_amount' => 10]);
    trpDoc($scope, 'sell', ['contact_id' => $large, 'transaction_date' => '2026-08-01', 'final_amount' => 999]);

    $order = fn (array $extra): array => prpRows(prpGet('customer-aging', parQuery($extra)))->pluck('id')->all();

    expect($order([]))->toBe([$large, $small])
        ->and($order(['sort_by' => 'contact_name', 'sort_type' => 'asc']))->toBe([$small, $large])
        ->and($order(['sort_by' => 'contact_name', 'sort_type' => 'desc']))->toBe([$large, $small])
        ->and($order(['sort_by' => 'not_a_column; drop table users']))->toBe([$large, $small]);
});

test('a company user sees only their own company and hostile input stays data', function () {
    $mine = trpScope('1');
    $theirs = trpScope('2');

    $own = prpContact($mine, 'customer', 'Mine');
    $other = prpContact($theirs, 'customer', 'Theirs');
    trpDoc($mine, 'sell', ['contact_id' => $own, 'transaction_date' => '2026-08-01', 'final_amount' => 10]);
    trpDoc($theirs, 'sell', ['contact_id' => $other, 'transaction_date' => '2026-08-01', 'final_amount' => 20]);

    Sanctum::actingAs(jeaUserWith($mine, ['/report/customer-aging']));

    // The signed in user's id is not their company id; the report is scoped by the company.
    expect(prpRows(prpGet('customer-aging', parQuery(['company_id' => $theirs['company_id']])))->pluck('id')->all())->toBe([$own]);

    prpGet('customer-aging', parQuery(['search' => "' OR 1=1 --"]))
        ->assertSuccessful()
        ->assertJsonPath('summary.count', 0);
});

test('a branch user sees only their branch and the branch filter narrows a company admin', function () {
    $scope = trpScope();
    $otherBranch = trpBranch($scope['company_id'], 'Second Branch');

    $contact = prpContact($scope, 'customer', 'Two Branches');
    trpDoc($scope, 'sell', ['contact_id' => $contact, 'transaction_date' => '2026-08-01', 'final_amount' => 10]);
    trpDoc($scope, 'sell', ['contact_id' => $contact, 'branch_id' => $otherBranch, 'transaction_date' => '2026-08-01', 'final_amount' => 20]);

    Sanctum::actingAs(jeaUserWith($scope, ['/report/customer-aging']));
    expect(prpRow(prpGet('customer-aging', parQuery()), $contact)['total'])->toEqual(10);

    trpActAsSuperadmin();
    expect(prpRow(prpGet('customer-aging', parQuery(['branch_id' => $otherBranch])), $contact)['total'])->toEqual(20)
        ->and(prpRow(prpGet('customer-aging', parQuery()), $contact)['total'])->toEqual(30);
});

test('a customer with no open invoice is not listed and an empty report is empty', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    prpContact($scope, 'customer', 'No Invoices');

    prpGet('customer-aging', parQuery())
        ->assertSuccessful()
        ->assertJsonPath('data.total', 0)
        ->assertJsonPath('summary.total', 0)
        ->assertJsonPath('summary.count', 0);
});

test('the as-of day must be a date', function () {
    trpScope();
    trpActAsSuperadmin();

    prpGet('customer-aging', ['end_date' => 'yesterday'])->assertUnprocessable();
});
