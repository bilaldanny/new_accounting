<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Purchase payment and sell payment reports (PaymentListReport): one row per payment, filtered by the
 * day the payment was made, not by the date of the invoice it settles.
 */
dataset('payment kinds', [
    'purchase payment' => ['purchase-payment', 'purchaseorder'],
    'sell payment' => ['sell-payment', 'sell'],
]);

test('the date range is the payment date, not the invoice date', function (string $report, string $type) {
    $scope = trpScope();
    trpActAsSuperadmin();

    // An August invoice settled in September, and a September invoice settled in October.
    $august = trpDoc($scope, $type, ['transaction_date' => '2026-08-15 10:00:00']);
    $september = trpDoc($scope, $type, ['transaction_date' => '2026-09-15 10:00:00']);
    $inSeptember = trpPayment($scope, $august, 100, '2026-09-20 10:00');
    $inOctober = trpPayment($scope, $september, 100, '2026-10-05 10:00');

    $september20 = trpIds($this->getJson("/api/reports/{$report}?start_date=2026-09-01&end_date=2026-09-30"));
    $august15 = trpIds($this->getJson("/api/reports/{$report}?start_date=2026-08-01&end_date=2026-08-31"));

    expect($september20)->toBe([$inSeptember])
        ->and($august15)->toBe([])
        ->and(trpIds($this->getJson("/api/reports/{$report}?start_date=2026-10-01&end_date=2026-10-31")))->toBe([$inOctober]);
})->with('payment kinds');

test('the payment day is inclusive at both ends', function (string $report, string $type) {
    $scope = trpScope();
    trpActAsSuperadmin();

    $doc = trpDoc($scope, $type);
    trpPayment($scope, $doc, 10, '2026-09-03 23:59');
    $first = trpPayment($scope, $doc, 20, '2026-09-04 00:00');
    $last = trpPayment($scope, $doc, 30, '2026-09-06 23:59');
    trpPayment($scope, $doc, 40, '2026-09-07 00:00');

    $response = $this->getJson("/api/reports/{$report}?start_date=2026-09-04&end_date=2026-09-06&show_record=50")->assertSuccessful();

    expect(collect(trpIds($response))->sort()->values()->all())->toBe([$first, $last])
        ->and($response->json('summary.total_amount'))->toEqual(50)
        ->and($response->json('summary.count'))->toBe(2);
})->with('payment kinds');

test('each report lists only payments of its own document type', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $purchase = trpPayment($scope, trpDoc($scope, 'purchaseorder'), 100, '2026-09-10 09:00');
    $sell = trpPayment($scope, trpDoc($scope, 'sell'), 200, '2026-09-10 09:00');
    trpPayment($scope, trpDoc($scope, 'purchasereturn'), 30, '2026-09-10 09:00');
    trpPayment($scope, trpDoc($scope, 'sellreturn'), 40, '2026-09-10 09:00');

    expect(trpIds($this->getJson('/api/reports/purchase-payment')))->toBe([$purchase])
        ->and(trpIds($this->getJson('/api/reports/sell-payment')))->toBe([$sell]);
});

test('payments on a draft or quotation sell and on a draft purchase are left out', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $counted = trpPayment($scope, trpDoc($scope, 'sell', ['status' => 'final']), 100, '2026-09-10 09:00');
    trpPayment($scope, trpDoc($scope, 'sell', ['status' => 'draft']), 100, '2026-09-10 09:00');
    trpPayment($scope, trpDoc($scope, 'sell', ['status' => 'quotation']), 100, '2026-09-10 09:00');
    $purchase = trpPayment($scope, trpDoc($scope, 'purchaseorder', ['status' => 'received']), 100, '2026-09-10 09:00');
    trpPayment($scope, trpDoc($scope, 'purchaseorder', ['status' => 'draft']), 100, '2026-09-10 09:00');

    expect(trpIds($this->getJson('/api/reports/sell-payment')))->toBe([$counted])
        ->and(trpIds($this->getJson('/api/reports/purchase-payment')))->toBe([$purchase]);
});

test('a payment on a deleted document is left out', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $kept = trpPayment($scope, trpDoc($scope, 'sell'), 100, '2026-09-10 09:00');
    trpPayment($scope, trpDoc($scope, 'sell', ['deleted_at' => now()]), 100, '2026-09-10 09:00');

    expect(trpIds($this->getJson('/api/reports/sell-payment')))->toBe([$kept]);
});

test('a row carries the payment, the invoice it settles and the party', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $invoice = trpDoc($scope, 'sell', ['invoice_no' => 'INV-777', 'final_amount' => 900, 'payment_status' => 'partial']);
    trpPayment($scope, $invoice, 250.75, '2026-09-10 14:30', 'cheque', ['payment_ref_no' => 'PAY-XYZ', 'cheque_number' => 'CHQ-42', 'note' => 'First instalment']);

    $row = $this->getJson('/api/reports/sell-payment')->assertSuccessful()->json('data.data.0');

    expect($row)->toMatchArray([
        'paid_on' => '2026-09-10 14:30',
        'payment_ref_no' => 'PAY-XYZ',
        'invoice_no' => 'INV-777',
        'contact_name' => 'Acme Retail 1',
        'method' => 'cheque',
        'cheque_number' => 'CHQ-42',
        'note' => 'First instalment',
        'payment_status' => 'partial',
        'branch_name' => 'Report Branch 1',
    ])
        ->and($row['amount'])->toEqual(250.75)
        ->and($row['invoice_amount'])->toEqual(900);
});

test('the summary totals every payment in the filter and splits it by method', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $doc = trpDoc($scope, 'sell');
    trpPayment($scope, $doc, 100, '2026-09-10 09:00', 'cash');
    trpPayment($scope, $doc, 50.5, '2026-09-11 09:00', 'cash');
    trpPayment($scope, $doc, 200, '2026-09-12 09:00', 'bank_transfer');
    trpPayment($scope, $doc, 75.25, '2026-09-13 09:00', 'cheque');

    $response = $this->getJson('/api/reports/sell-payment?show_record=2')->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(2)
        ->and($response->json('summary.count'))->toBe(4)
        ->and($response->json('summary.total_amount'))->toEqual(425.75)
        ->and($response->json('summary.by_method.cash'))->toEqual(150.5)
        ->and($response->json('summary.by_method.bank_transfer'))->toEqual(200)
        ->and($response->json('summary.by_method.cheque'))->toEqual(75.25)
        ->and($response->json('summary.by_method.card'))->toEqual(0)
        ->and($response->json('summary.by_method.other'))->toEqual(0);
});

test('the report filters by method, contact and branch and searches', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $otherBranch = trpBranch($scope['company_id'], 'Second Branch');
    $otherCustomer = trpContact($scope, 'Other Buyer', 'customer', 'CU-OTHER');

    $cash = trpPayment($scope, trpDoc($scope, 'sell', ['invoice_no' => 'INV-CASH']), 10, '2026-09-10 09:00', 'cash', ['payment_ref_no' => 'PAY-A']);
    $card = trpPayment($scope, trpDoc($scope, 'sell'), 20, '2026-09-10 09:00', 'card', ['payment_ref_no' => 'PAY-B']);
    $otherContact = trpPayment($scope, trpDoc($scope, 'sell', ['contact_id' => $otherCustomer]), 30, '2026-09-10 09:00', 'cash', ['payment_ref_no' => 'PAY-C']);
    $branch = trpPayment($scope, trpDoc($scope, 'sell', ['branch_id' => $otherBranch]), 40, '2026-09-10 09:00', 'cash', ['payment_ref_no' => 'PAY-D']);

    $ids = fn (string $query): array => collect(trpIds($this->getJson("/api/reports/sell-payment?show_record=50&{$query}")))->sort()->values()->all();

    expect($ids('method=card'))->toBe([$card])
        ->and($ids('method=cash'))->toBe(collect([$cash, $otherContact, $branch])->sort()->values()->all())
        ->and($ids('method=all'))->toBe(collect([$cash, $card, $otherContact, $branch])->sort()->values()->all())
        ->and($ids("contact_id={$otherCustomer}"))->toBe([$otherContact])
        ->and($ids("branch_id={$otherBranch}"))->toBe([$branch])
        ->and($ids('search=PAY-B'))->toBe([$card])
        ->and($ids('search=INV-CASH'))->toBe([$cash])
        ->and($ids('search=Other Buyer'))->toBe([$otherContact]);
});

test('payments sort by amount and by day', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $doc = trpDoc($scope, 'sell');
    $small = trpPayment($scope, $doc, 10, '2026-09-03 09:00');
    $large = trpPayment($scope, $doc, 900, '2026-09-01 09:00');
    $middle = trpPayment($scope, $doc, 300, '2026-09-02 09:00');

    expect(trpIds($this->getJson('/api/reports/sell-payment?sort_by=amount&sort_type=asc')))->toBe([$small, $middle, $large])
        ->and(trpIds($this->getJson('/api/reports/sell-payment?sort_by=amount&sort_type=desc')))->toBe([$large, $middle, $small])
        ->and(trpIds($this->getJson('/api/reports/sell-payment')))->toBe([$small, $middle, $large]);
});

test('a company user sees only their own payments and hostile input stays data', function () {
    $mine = trpScope('1');
    $theirs = trpScope('2');

    $own = trpPayment($mine, trpDoc($mine, 'sell'), 10, '2026-09-10 09:00');
    trpPayment($theirs, trpDoc($theirs, 'sell'), 20, '2026-09-10 09:00');

    Sanctum::actingAs(jeaUserWith($mine, ['/report/sell-payment']));

    expect(trpIds($this->getJson("/api/reports/sell-payment?company_id={$theirs['company_id']}")))->toBe([$own]);

    $this->getJson('/api/reports/sell-payment?'.http_build_query(['search' => "' OR 1=1 --", 'method' => "cash' OR '1'='1"]))
        ->assertSuccessful()
        ->assertJsonPath('summary.count', 0);
});

test('the payment reports need their own menu rows', function () {
    $scope = trpScope();

    Sanctum::actingAs(jeaUserWith($scope, ['/report/sell-payment']));

    $this->getJson('/api/reports/sell-payment')->assertSuccessful();
    $this->getJson('/api/reports/purchase-payment')->assertForbidden();
});
