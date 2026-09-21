<?php

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Purchase, purchase return, sell and sell return reports (TransactionListReport). What counts follows
 * the ledger and StockMovements: every sell except draft and quotation, every purchase except draft,
 * every return; the date range is inclusive at both ends.
 */
dataset('transaction kinds', [
    'purchase' => ['purchase', 'purchaseorder'],
    'purchase return' => ['purchase-return', 'purchasereturn'],
    'sell' => ['sell', 'sell'],
    'sell return' => ['sell-return', 'sellreturn'],
]);

test('each report lists only its own document type', function (string $report, string $type) {
    $scope = trpScope();
    trpActAsSuperadmin();

    $ids = [];
    foreach (['purchaseorder', 'recieving_note', 'purchasereturn', 'sell', 'issue_note', 'sellreturn', 'transfer', 'adjustment'] as $documentType) {
        $ids[$documentType] = trpDoc($scope, $documentType);
    }

    $response = $this->getJson("/api/reports/{$report}")->assertSuccessful();

    expect(trpIds($response))->toBe([$ids[$type]])
        ->and($response->json('summary.count'))->toBe(1);
})->with('transaction kinds');

test('a sell counts unless it is a draft or a quotation', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $counted = [];
    foreach (['final', 'approved', 'issue', 'pending', 'ordered'] as $status) {
        $counted[] = trpDoc($scope, 'sell', ['status' => $status]);
    }
    trpDoc($scope, 'sell', ['status' => 'draft']);
    trpDoc($scope, 'sell', ['status' => 'quotation']);

    $response = $this->getJson('/api/reports/sell?show_record=50')->assertSuccessful();

    expect(collect(trpIds($response))->sort()->values()->all())->toBe(collect($counted)->sort()->values()->all())
        ->and($response->json('summary.count'))->toBe(5);
});

test('a purchase counts unless it is a draft', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $counted = [];
    foreach (['pending', 'approved', 'received', 'ordered'] as $status) {
        $counted[] = trpDoc($scope, 'purchaseorder', ['status' => $status]);
    }
    trpDoc($scope, 'purchaseorder', ['status' => 'draft']);

    $response = $this->getJson('/api/reports/purchase?show_record=50')->assertSuccessful();

    expect(collect(trpIds($response))->sort()->values()->all())->toBe(collect($counted)->sort()->values()->all());
});

test('returns count whatever their status', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $sellReturns = [trpDoc($scope, 'sellreturn', ['status' => 'pending']), trpDoc($scope, 'sellreturn', ['status' => 'approved'])];
    $purchaseReturns = [trpDoc($scope, 'purchasereturn', ['status' => 'pending']), trpDoc($scope, 'purchasereturn', ['status' => 'approved'])];

    expect($this->getJson('/api/reports/sell-return')->json('summary.count'))->toBe(2)
        ->and($this->getJson('/api/reports/purchase-return')->json('summary.count'))->toBe(2)
        ->and(collect(trpIds($this->getJson('/api/reports/sell-return')))->sort()->values()->all())->toBe(collect($sellReturns)->sort()->values()->all())
        ->and(collect(trpIds($this->getJson('/api/reports/purchase-return')))->sort()->values()->all())->toBe(collect($purchaseReturns)->sort()->values()->all());
});

test('a deleted document is not reported', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $kept = trpDoc($scope, 'sell');
    trpDoc($scope, 'sell', ['deleted_at' => now()]);

    expect(trpIds($this->getJson('/api/reports/sell')))->toBe([$kept]);
});

// ------------------------------------------------------------------ dates

test('the date range includes both end days completely and nothing outside them', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $before = trpDoc($scope, 'sell', ['transaction_date' => '2026-09-03 23:59:59']);
    $startOfFirstDay = trpDoc($scope, 'sell', ['transaction_date' => '2026-09-04 00:00:00']);
    $middle = trpDoc($scope, 'sell', ['transaction_date' => '2026-09-05 12:00:00']);
    $endOfLastDay = trpDoc($scope, 'sell', ['transaction_date' => '2026-09-06 23:59:59']);
    $after = trpDoc($scope, 'sell', ['transaction_date' => '2026-09-07 00:00:00']);

    $response = $this->getJson('/api/reports/sell?start_date=2026-09-04&end_date=2026-09-06&show_record=50')->assertSuccessful();

    expect(collect(trpIds($response))->sort()->values()->all())->toBe([$startOfFirstDay, $middle, $endOfLastDay])
        ->and(trpIds($response))->not->toContain($before, $after)
        ->and($response->json('summary.count'))->toBe(3);
});

test('a single day range returns that day and no other', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    trpDoc($scope, 'purchaseorder', ['transaction_date' => '2026-09-03 23:59:59']);
    $inside = trpDoc($scope, 'purchaseorder', ['transaction_date' => '2026-09-04 23:59:59']);
    trpDoc($scope, 'purchaseorder', ['transaction_date' => '2026-09-05 00:00:00']);

    expect(trpIds($this->getJson('/api/reports/purchase?start_date=2026-09-04&end_date=2026-09-04')))->toBe([$inside]);
});

test('an open ended range works and no range returns everything', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $early = trpDoc($scope, 'sell', ['transaction_date' => '2026-01-01 08:00:00']);
    $late = trpDoc($scope, 'sell', ['transaction_date' => '2026-12-31 08:00:00']);

    expect($this->getJson('/api/reports/sell?start_date=2026-06-01')->json('summary.count'))->toBe(1)
        ->and(trpIds($this->getJson('/api/reports/sell?start_date=2026-06-01')))->toBe([$late])
        ->and(trpIds($this->getJson('/api/reports/sell?end_date=2026-06-01')))->toBe([$early])
        ->and($this->getJson('/api/reports/sell')->json('summary.count'))->toBe(2);
});

test('an end date before the start date is rejected', function () {
    trpScope();
    trpActAsSuperadmin();

    $this->getJson('/api/reports/sell?start_date=2026-09-10&end_date=2026-09-01')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('end_date');

    $this->getJson('/api/reports/sell?start_date=10/09/2026')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('start_date');
});

// ------------------------------------------------------------------ figures

test('the summary covers the whole filtered set, not the page on screen', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $expected = ['total_before_tax' => 0.0, 'tax_amount' => 0.0, 'discount_amount' => 0.0, 'shipping_charges' => 0.0, 'final_amount' => 0.0];

    for ($i = 1; $i <= 12; $i++) {
        trpDoc($scope, 'sell', [
            'total_before_tax' => 90.5 + $i,
            'tax_amount' => 5.25,
            'discount_amount' => 1.5,
            'shipping_charges' => 4,
            'final_amount' => 100 + $i,
        ]);
        $expected['total_before_tax'] += 90.5 + $i;
        $expected['tax_amount'] += 5.25;
        $expected['discount_amount'] += 1.5;
        $expected['shipping_charges'] += 4;
        $expected['final_amount'] += 100 + $i;
    }

    $response = $this->getJson('/api/reports/sell?show_record=5')->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(5)
        ->and($response->json('data.total'))->toBe(12)
        ->and($response->json('data.last_page'))->toBe(3)
        ->and($response->json('summary.count'))->toBe(12);

    foreach ($expected as $key => $value) {
        expect((float) $response->json("summary.{$key}"))->toEqual(round($value, 2));
    }
});

test('paid is the sum of the payments and due is what is left, without repeating a document', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $split = trpDoc($scope, 'sell', ['final_amount' => 500]);
    trpPayment($scope, $split, 100, '2026-09-10 09:00');
    trpPayment($scope, $split, 150, '2026-09-11 09:00');
    trpPayment($scope, $split, 50.5, '2026-09-12 09:00');

    $settled = trpDoc($scope, 'sell', ['final_amount' => 200]);
    trpPayment($scope, $settled, 200, '2026-09-10 09:00');

    $unpaid = trpDoc($scope, 'sell', ['final_amount' => 300]);

    $response = $this->getJson('/api/reports/sell?show_record=50')->assertSuccessful();
    $rows = collect($response->json('data.data'))->keyBy('id');

    expect($rows)->toHaveCount(3)
        ->and($rows[$split]['paid'])->toEqual(300.5)
        ->and($rows[$split]['due'])->toEqual(199.5)
        ->and($rows[$settled]['paid'])->toEqual(200.0)
        ->and($rows[$settled]['due'])->toEqual(0.0)
        ->and($rows[$unpaid]['paid'])->toEqual(0.0)
        ->and($rows[$unpaid]['due'])->toEqual(300.0)
        ->and($response->json('summary.final_amount'))->toEqual(1000.0)
        ->and($response->json('summary.paid'))->toEqual(500.5)
        ->and($response->json('summary.due'))->toEqual(499.5);
});

test('the contact shows as the business name, or the person when there is none', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $person = trpContact($scope, null, 'customer', 'CU-P', 'Bilal', 'Younus');
    $business = trpContact($scope, 'Younus Traders', 'customer', 'CU-B', 'Bilal', 'Younus');
    $withBusiness = trpDoc($scope, 'sell', ['contact_id' => $business]);
    $withPerson = trpDoc($scope, 'sell', ['contact_id' => $person]);

    $rows = collect($this->getJson('/api/reports/sell')->json('data.data'))->keyBy('id');

    expect($rows[$withBusiness]['contact_name'])->toBe('Younus Traders')
        ->and($rows[$withPerson]['contact_name'])->toBe('Bilal Younus');
});

test('a return names the invoice it belongs to', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $sell = trpDoc($scope, 'sell', ['invoice_no' => 'INV-ORIGINAL']);
    trpDoc($scope, 'sellreturn', ['parent_id' => $sell, 'invoice_no' => 'SR-1', 'status' => 'pending']);

    $response = $this->getJson('/api/reports/sell-return')->assertSuccessful();

    expect($response->json('data.data.0.invoice_no'))->toBe('SR-1')
        ->and($response->json('data.data.0.parent_invoice_no'))->toBe('INV-ORIGINAL');
});

test('a purchase shows its supplier reference', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    trpDoc($scope, 'purchaseorder', ['sup_ref_no' => 'SUP-778']);

    expect($this->getJson('/api/reports/purchase')->json('data.data.0.sup_ref_no'))->toBe('SUP-778');
});

// ------------------------------------------------------------------ filters

test('the report filters by contact, status, payment status and branch', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $otherCustomer = trpContact($scope, 'Other Buyer', 'customer', 'CU-OTHER');
    $secondBranch = trpBranch($scope['company_id'], 'Second Branch');

    $mine = trpDoc($scope, 'sell', ['status' => 'final', 'payment_status' => 'due']);
    $approved = trpDoc($scope, 'sell', ['status' => 'approved', 'payment_status' => 'paid']);
    $otherContact = trpDoc($scope, 'sell', ['contact_id' => $otherCustomer]);
    $otherBranch = trpDoc($scope, 'sell', ['branch_id' => $secondBranch, 'payment_status' => 'partial']);

    $ids = fn (string $query): array => collect(trpIds($this->getJson("/api/reports/sell?show_record=50&{$query}")))->sort()->values()->all();

    expect($ids("contact_id={$otherCustomer}"))->toBe([$otherContact])
        ->and($ids('status=approved'))->toBe([$approved])
        ->and($ids('payment_status=paid'))->toBe([$approved])
        ->and($ids('payment_status=partial'))->toBe([$otherBranch])
        ->and($ids("branch_id={$secondBranch}"))->toBe([$otherBranch])
        ->and($ids('status=all&payment_status=all'))->toBe(collect([$mine, $approved, $otherContact, $otherBranch])->sort()->values()->all())
        ->and($ids('status=final'))->toBe(collect([$mine, $otherContact, $otherBranch])->sort()->values()->all());
});

test('searching matches the invoice number, the supplier reference and the contact name', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $byInvoice = trpDoc($scope, 'purchaseorder', ['invoice_no' => 'PO-ZEBRA-1']);
    $byReference = trpDoc($scope, 'purchaseorder', ['sup_ref_no' => 'REF-ZEBRA-9']);
    trpDoc($scope, 'purchaseorder', ['invoice_no' => 'PO-OTHER']);
    DB::table('contacts')->where('id', $scope['supplier_id'])->update(['business_name' => 'Farm Supplies']);
    $byContact = trpDoc($scope, 'purchaseorder', ['contact_id' => trpContact($scope, 'Zebra Traders', 'supplier', 'SU-Z')]);

    $found = collect(trpIds($this->getJson('/api/reports/purchase?search=zebra&show_record=50')))->sort()->values()->all();

    expect($found)->toBe(collect([$byInvoice, $byReference, $byContact])->sort()->values()->all());
});

test('hostile input is treated as data', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    trpDoc($scope, 'sell');

    $this->getJson('/api/reports/sell?'.http_build_query(['search' => "' OR 1=1 --"]))
        ->assertSuccessful()
        ->assertJsonPath('summary.count', 0);

    $this->getJson('/api/reports/sell?'.http_build_query(['status' => "final' OR '1'='1", 'payment_status' => '1; DROP TABLE transactions']))
        ->assertSuccessful()
        ->assertJsonPath('summary.count', 0);

    $this->getJson('/api/reports/sell?'.http_build_query(['sort_by' => 'final_amount; DROP TABLE transactions', 'sort_type' => 'asc']))
        ->assertSuccessful()
        ->assertJsonPath('summary.count', 1);

    expect(DB::table('transactions')->count())->toBe(1);
});

// ------------------------------------------------------------------ sorting and paging

test('rows sort by a known column and fall back to the newest first', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $small = trpDoc($scope, 'sell', ['final_amount' => 10, 'transaction_date' => '2026-09-01 10:00:00']);
    $large = trpDoc($scope, 'sell', ['final_amount' => 900, 'transaction_date' => '2026-09-03 10:00:00']);
    $middle = trpDoc($scope, 'sell', ['final_amount' => 300, 'transaction_date' => '2026-09-02 10:00:00']);

    expect(trpIds($this->getJson('/api/reports/sell?sort_by=final_amount&sort_type=asc')))->toBe([$small, $middle, $large])
        ->and(trpIds($this->getJson('/api/reports/sell?sort_by=final_amount&sort_type=desc')))->toBe([$large, $middle, $small])
        ->and(trpIds($this->getJson('/api/reports/sell')))->toBe([$large, $middle, $small])
        ->and(trpIds($this->getJson('/api/reports/sell?sort_by=nonsense&sort_type=asc')))->toBe([$large, $middle, $small]);
});

test('a page past the end shows the last page', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    foreach (range(1, 7) as $i) {
        trpDoc($scope, 'sell');
    }

    $response = $this->getJson('/api/reports/sell?show_record=5&cur_page=9')->assertSuccessful();

    expect($response->json('data.current_page'))->toBe(2)
        ->and($response->json('data.data'))->toHaveCount(2);
});

// ------------------------------------------------------------------ who sees what

test('a company user sees only their own company', function () {
    $mine = trpScope('1');
    $theirs = trpScope('2');

    $own = trpDoc($mine, 'sell');
    trpDoc($theirs, 'sell');

    Sanctum::actingAs(jeaUserWith($mine, ['/report/sell']));

    $response = $this->getJson("/api/reports/sell?company_id={$theirs['company_id']}")->assertSuccessful();

    expect(trpIds($response))->toBe([$own])
        ->and($response->json('summary.count'))->toBe(1);
});

test('a branch user sees only their branch and a company admin can pick any branch', function () {
    $scope = trpScope();
    $otherBranch = trpBranch($scope['company_id'], 'Other Branch');

    $here = trpDoc($scope, 'sell');
    $there = trpDoc($scope, 'sell', ['branch_id' => $otherBranch]);

    Sanctum::actingAs(jeaUserWith($scope, ['/report/sell']));
    expect(trpIds($this->getJson("/api/reports/sell?branch_id={$otherBranch}")))->toBe([$here]);

    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => $scope['company_id'], 'is_active' => true]);
    grantMenuPermission($role->id, '/report/sell', 'reportsellcompanyadmin');
    $admin = createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']]);
    Sanctum::actingAs($admin);

    expect(collect(trpIds($this->getJson('/api/reports/sell')))->sort()->values()->all())->toBe(collect([$here, $there])->sort()->values()->all())
        ->and(trpIds($this->getJson("/api/reports/sell?branch_id={$otherBranch}")))->toBe([$there]);
});

test('the superadmin can narrow to one company or see all', function () {
    $one = trpScope('1');
    $two = trpScope('2');

    $first = trpDoc($one, 'sell');
    $second = trpDoc($two, 'sell');
    trpActAsSuperadmin();

    expect(collect(trpIds($this->getJson('/api/reports/sell')))->sort()->values()->all())->toBe(collect([$first, $second])->sort()->values()->all())
        ->and(trpIds($this->getJson("/api/reports/sell?company_id={$two['company_id']}")))->toBe([$second]);
});

test('a user without a company gets nothing', function () {
    $scope = trpScope();
    trpDoc($scope, 'sell');

    $role = Role::query()->create(['name' => 'drifter', 'is_active' => true]);
    grantMenuPermission($role->id, '/report/sell', 'reportselldrifter');
    Sanctum::actingAs(createStaffUserForRole($role));

    $this->getJson('/api/reports/sell')->assertForbidden();
});

test('a guest is turned away', function () {
    $this->getJson('/api/reports/sell')->assertUnauthorized();
});

test('the report is not available for a user whose role lacks its menu row', function (string $report) {
    $scope = trpScope();
    trpDoc($scope, 'sell');

    Sanctum::actingAs(jeaUserWith($scope, []));

    $this->getJson("/api/reports/{$report}")->assertForbidden();
})->with(['purchase', 'purchase-return', 'sell', 'sell-return']);

test('one report permission does not open another report', function () {
    $scope = trpScope();
    Sanctum::actingAs(jeaUserWith($scope, ['/report/purchase']));

    $this->getJson('/api/reports/purchase')->assertSuccessful();
    $this->getJson('/api/reports/sell')->assertForbidden();
    $this->getJson('/api/reports/purchase-return')->assertForbidden();
});

test('an unknown report is not found', function () {
    trpActAsSuperadmin();

    $this->getJson('/api/reports/no-such-report')->assertNotFound();
});
