<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Stock adjustment report (completed adjustments by default, split by type) and the expense report
 * (approved EXP vouchers only, one row per debit line).
 */

// ------------------------------------------------------------------ stock adjustment

/**
 * @param  array{company_id: int, branch_id: int, customer_id: int, supplier_id: int}  $scope
 * @param  array<string, mixed>  $attributes
 */
function sarAdjustment(array $scope, string $type, float $amount, array $attributes = []): int
{
    return trpDoc($scope, 'adjustment', array_merge([
        'adjustment_type' => $type,
        'status' => 'completed',
        'final_amount' => $amount,
        'contact_id' => null,
    ], $attributes));
}

test('only completed adjustments are listed unless another status is asked for', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $completed = sarAdjustment($scope, 'normal', 100);
    $pending = sarAdjustment($scope, 'normal', 50, ['status' => 'pending']);

    expect(trpIds($this->getJson('/api/reports/stock-adjustment')))->toBe([$completed])
        ->and(trpIds($this->getJson('/api/reports/stock-adjustment?status=completed')))->toBe([$completed])
        ->and(trpIds($this->getJson('/api/reports/stock-adjustment?status=pending')))->toBe([$pending])
        ->and(collect(trpIds($this->getJson('/api/reports/stock-adjustment?status=all')))->sort()->values()->all())->toBe(collect([$completed, $pending])->sort()->values()->all());
});

test('only adjustment documents are listed', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $adjustment = sarAdjustment($scope, 'normal', 100);
    foreach (['transfer', 'purchaseorder', 'sell', 'recieving_note'] as $type) {
        trpDoc($scope, $type, ['status' => 'completed']);
    }

    expect(trpIds($this->getJson('/api/reports/stock-adjustment?status=all')))->toBe([$adjustment]);
});

test('the summary splits the value by adjustment type', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    sarAdjustment($scope, 'normal', 100);
    sarAdjustment($scope, 'normal', 25.5);
    sarAdjustment($scope, 'abnormal', 300);
    sarAdjustment($scope, 'unboxing', 40);
    sarAdjustment($scope, 'opening', 1000);
    sarAdjustment($scope, 'normal', 999, ['status' => 'pending']);

    $response = $this->getJson('/api/reports/stock-adjustment?show_record=2')->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(2)
        ->and($response->json('summary.count'))->toBe(5)
        ->and($response->json('summary.total'))->toEqual(1465.5)
        ->and($response->json('summary.by_type.normal'))->toEqual(125.5)
        ->and($response->json('summary.by_type.abnormal'))->toEqual(300)
        ->and($response->json('summary.by_type.unboxing'))->toEqual(40)
        ->and($response->json('summary.by_type.opening'))->toEqual(1000);
});

test('the adjustment report filters by type, date, branch and search', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $otherBranch = trpBranch($scope['company_id'], 'Second Branch');

    $normal = sarAdjustment($scope, 'normal', 10, ['transaction_date' => '2026-09-04 23:59:59', 'invoice_no' => 'SA-ONE']);
    $abnormal = sarAdjustment($scope, 'abnormal', 20, ['transaction_date' => '2026-09-05 00:00:00', 'additional_note' => 'Spoiled in transit']);
    $branch = sarAdjustment($scope, 'normal', 30, ['branch_id' => $otherBranch, 'transaction_date' => '2026-09-20 10:00:00']);

    $ids = fn (string $query): array => collect(trpIds($this->getJson("/api/reports/stock-adjustment?show_record=50&{$query}")))->sort()->values()->all();

    expect($ids('adjustment_type=abnormal'))->toBe([$abnormal])
        ->and($ids('adjustment_type=all'))->toBe(collect([$normal, $abnormal, $branch])->sort()->values()->all())
        ->and($ids('start_date=2026-09-04&end_date=2026-09-04'))->toBe([$normal])
        ->and($ids('start_date=2026-09-05&end_date=2026-09-05'))->toBe([$abnormal])
        ->and($ids("branch_id={$otherBranch}"))->toBe([$branch])
        ->and($ids('search=spoiled'))->toBe([$abnormal])
        ->and($ids('search=SA-ONE'))->toBe([$normal]);
});

test('a row names who made the adjustment and carries its note', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $userId = DB::table('users')->insertGetId([
        'first_name' => 'Amina', 'last_name' => 'Rauf', 'username' => 'amina.rauf', 'email' => 'amina@example.com',
        'password' => 'x', 'pass' => '', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    sarAdjustment($scope, 'normal', 60, ['created_by' => $userId, 'additional_note' => 'Broken bags', 'total_item' => 3, 'invoice_no' => 'SA-100']);
    sarAdjustment($scope, 'abnormal', 70, ['created_by' => null]);

    $rows = collect($this->getJson('/api/reports/stock-adjustment?sort_by=final_amount&sort_type=asc')->json('data.data'));

    expect($rows[0])->toMatchArray([
        'invoice_no' => 'SA-100',
        'adjustment_type' => 'normal',
        'status' => 'completed',
        'total_item' => 3,
        'additional_note' => 'Broken bags',
        'created_by_name' => 'Amina Rauf',
        'branch_name' => 'Report Branch 1',
    ])
        ->and($rows[0]['final_amount'])->toEqual(60)
        ->and($rows[1]['created_by_name'])->toBe('-');
});

test('a company user sees only their own adjustments and needs the menu row', function () {
    $mine = trpScope('1');
    $theirs = trpScope('2');

    $own = sarAdjustment($mine, 'normal', 10);
    sarAdjustment($theirs, 'normal', 20);

    Sanctum::actingAs(jeaUserWith($mine, []));
    $this->getJson('/api/reports/stock-adjustment')->assertForbidden();

    Sanctum::actingAs(jeaUserWith($mine, ['/report/stock-adjustment']));

    expect(trpIds($this->getJson("/api/reports/stock-adjustment?company_id={$theirs['company_id']}")))->toBe([$own]);

    $this->getJson('/api/reports/stock-adjustment?'.http_build_query(['adjustment_type' => "normal' OR '1'='1", 'search' => "' OR 1=1 --"]))
        ->assertSuccessful()
        ->assertJsonPath('summary.count', 0);
});

// ------------------------------------------------------------------ expense

/**
 * A voucher with hand-picked lines so each amount, account and status is known.
 *
 * @param  array{company_id: int, branch_id: int, debit_account_id: int, credit_account_id: int, debit_code: string, credit_code: string}  $scope
 * @param  list<array{coa_id: int, code: string, debit: float, credit: float, description?: string}>  $lines
 * @param  array<string, mixed>  $attributes
 */
function xprVoucher(array $scope, string $voucherNo, string $status, string $date, array $lines, array $attributes = []): int
{
    $voucherId = DB::table('t_accounts')->insertGetId(array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'account_code' => $lines[0]['code'],
        'voucher_no' => $voucherNo,
        'ref_no' => '',
        'cheque_no' => '',
        'comments' => '',
        'status' => $status,
        'voucher_date' => $date,
        'total_amount' => array_sum(array_column($lines, 'debit')),
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));

    foreach ($lines as $line) {
        DB::table('t_account_details')->insert([
            't_account_id' => $voucherId,
            'branch_id' => $scope['branch_id'],
            'coa_id' => $line['coa_id'],
            'account_code' => $line['code'],
            'description' => $line['description'] ?? '',
            'acc_nature' => $line['debit'] > 0 ? 'dr' : 'cr',
            'debit' => $line['debit'],
            'credit' => $line['credit'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $voucherId;
}

/**
 * @param  array{company_id: int, branch_id: int, debit_account_id: int, credit_account_id: int, debit_code: string, credit_code: string}  $scope
 * @return array{coa_id: int, code: string}
 */
function xprAccount(array $scope, string $code, string $name): array
{
    $id = DB::table('chart_of_accounts')->insertGetId([
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'code' => $code, 'name' => $name,
        'acc_type' => 't', 'acc_nature' => 'dr', 'bs' => 0, 'active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return ['coa_id' => $id, 'code' => $code];
}

/**
 * @param  array{coa_id: int, code: string}  $account
 * @return array{coa_id: int, code: string, debit: float, credit: float, description?: string}
 */
function xprDebit(array $account, float $amount, string $description = ''): array
{
    return $account + ['debit' => $amount, 'credit' => 0.0, 'description' => $description];
}

/**
 * @param  array{coa_id: int, code: string}  $account
 * @return array{coa_id: int, code: string, debit: float, credit: float}
 */
function xprCredit(array $account, float $amount): array
{
    return $account + ['debit' => 0.0, 'credit' => $amount];
}

test('an approved expense shows one row per expense account it charges', function () {
    $scope = jeaScope();
    trpActAsSuperadmin();

    $rent = xprAccount($scope, '410-00001', 'Office Rent');
    $power = xprAccount($scope, '410-00002', 'Electricity');
    $cash = ['coa_id' => $scope['debit_account_id'], 'code' => $scope['debit_code']];

    xprVoucher($scope, 'EXP-00001', 'approved', '2026-09-10', [
        xprDebit($rent, 300, 'September rent'),
        xprDebit($power, 200.5, 'September bill'),
        xprCredit($cash, 500.5),
    ], ['ref_no' => 'BILL-77', 'comments' => 'Monthly bills']);

    $response = $this->getJson('/api/reports/expense?sort_by=amount&sort_type=desc')->assertSuccessful();
    $rows = collect($response->json('data.data'));

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toMatchArray([
            'voucher_no' => 'EXP-00001',
            'voucher_date' => '2026-09-10',
            'ref_no' => 'BILL-77',
            'account_code' => '410-00001',
            'account_name' => 'Office Rent',
            'description' => 'September rent',
            'comments' => 'Monthly bills',
            'branch_name' => 'Approval Branch 1',
        ])
        ->and($rows[0]['amount'])->toEqual(300)
        ->and($rows[1]['account_name'])->toBe('Electricity')
        ->and($rows[1]['amount'])->toEqual(200.5)
        ->and($response->json('summary.count'))->toBe(2)
        ->and($response->json('summary.vouchers'))->toBe(1)
        ->and($response->json('summary.total_amount'))->toEqual(500.5);
});

test('only approved expense vouchers count and the credit leg is not an expense', function () {
    $scope = jeaScope();
    trpActAsSuperadmin();

    $rent = xprAccount($scope, '410-00001', 'Office Rent');
    $cash = ['coa_id' => $scope['debit_account_id'], 'code' => $scope['debit_code']];

    foreach (['pending', 'rejected', 'cancelled'] as $i => $status) {
        xprVoucher($scope, 'EXP-0010'.$i, $status, '2026-09-10', [xprDebit($rent, 100), xprCredit($cash, 100)]);
    }
    $approved = xprVoucher($scope, 'EXP-00200', 'approved', '2026-09-10', [xprDebit($rent, 400), xprCredit($cash, 400)]);

    $response = $this->getJson('/api/reports/expense')->assertSuccessful();

    expect(collect($response->json('data.data'))->pluck('voucher_id')->all())->toBe([$approved])
        ->and($response->json('summary.count'))->toBe(1)
        ->and($response->json('summary.total_amount'))->toEqual(400)
        ->and(collect($response->json('data.data'))->pluck('account_code')->all())->toBe(['410-00001']);
});

test('only expense vouchers are reported, not journals, payments, deposits or system postings', function () {
    $scope = jeaScope();
    trpActAsSuperadmin();

    $rent = xprAccount($scope, '410-00001', 'Office Rent');
    $cash = ['coa_id' => $scope['debit_account_id'], 'code' => $scope['debit_code']];

    $expense = xprVoucher($scope, 'EXP-00001', 'approved', '2026-09-10', [xprDebit($rent, 100), xprCredit($cash, 100)]);

    foreach (['JV-00001', 'BP-00001', 'CP-00001', 'BD-00001', 'FT-00001'] as $voucherNo) {
        xprVoucher($scope, $voucherNo, 'approved', '2026-09-10', [xprDebit($rent, 999), xprCredit($cash, 999)]);
    }

    $document = trpDoc(trpScope(), 'purchaseorder');
    xprVoucher($scope, 'EXP-00099', 'approved', '2026-09-10', [xprDebit($rent, 777), xprCredit($cash, 777)], ['transaction_id' => $document]);

    $response = $this->getJson('/api/reports/expense')->assertSuccessful();

    expect(collect($response->json('data.data'))->pluck('voucher_id')->all())->toBe([$expense]);
});

test('the expense date range is inclusive at both ends', function () {
    $scope = jeaScope();
    trpActAsSuperadmin();

    $rent = xprAccount($scope, '410-00001', 'Office Rent');
    $cash = ['coa_id' => $scope['debit_account_id'], 'code' => $scope['debit_code']];

    xprVoucher($scope, 'EXP-00001', 'approved', '2026-09-03', [xprDebit($rent, 10), xprCredit($cash, 10)]);
    $first = xprVoucher($scope, 'EXP-00002', 'approved', '2026-09-04', [xprDebit($rent, 20), xprCredit($cash, 20)]);
    $last = xprVoucher($scope, 'EXP-00003', 'approved', '2026-09-06', [xprDebit($rent, 30), xprCredit($cash, 30)]);
    xprVoucher($scope, 'EXP-00004', 'approved', '2026-09-07', [xprDebit($rent, 40), xprCredit($cash, 40)]);

    $response = $this->getJson('/api/reports/expense?start_date=2026-09-04&end_date=2026-09-06&show_record=50')->assertSuccessful();

    expect(collect($response->json('data.data'))->pluck('voucher_id')->sort()->values()->all())->toBe([$first, $last])
        ->and($response->json('summary.total_amount'))->toEqual(50);
});

test('the expense report filters by account and searches', function () {
    $scope = jeaScope();
    trpActAsSuperadmin();

    $rent = xprAccount($scope, '410-00001', 'Office Rent');
    $power = xprAccount($scope, '410-00002', 'Electricity');
    $cash = ['coa_id' => $scope['debit_account_id'], 'code' => $scope['debit_code']];

    $rentVoucher = xprVoucher($scope, 'EXP-00001', 'approved', '2026-09-10', [xprDebit($rent, 100, 'Shop rent'), xprCredit($cash, 100)], ['ref_no' => 'INV-RENT']);
    $powerVoucher = xprVoucher($scope, 'EXP-00002', 'approved', '2026-09-11', [xprDebit($power, 50, 'Meter reading'), xprCredit($cash, 50)]);

    $vouchers = fn (string $query): array => collect($this->getJson("/api/reports/expense?show_record=50&{$query}")->json('data.data'))->pluck('voucher_id')->all();

    expect($vouchers("account_id={$rent['coa_id']}"))->toBe([$rentVoucher])
        ->and($vouchers("account_id={$power['coa_id']}"))->toBe([$powerVoucher])
        ->and($vouchers('search=electricity'))->toBe([$powerVoucher])
        ->and($vouchers('search=meter'))->toBe([$powerVoucher])
        ->and($vouchers('search=INV-RENT'))->toBe([$rentVoucher])
        ->and($vouchers('search=410-00001'))->toBe([$rentVoucher])
        ->and($vouchers('search=EXP-00002'))->toBe([$powerVoucher]);
});

test('the expense summary counts vouchers separately from lines', function () {
    $scope = jeaScope();
    trpActAsSuperadmin();

    $rent = xprAccount($scope, '410-00001', 'Office Rent');
    $power = xprAccount($scope, '410-00002', 'Electricity');
    $cash = ['coa_id' => $scope['debit_account_id'], 'code' => $scope['debit_code']];

    xprVoucher($scope, 'EXP-00001', 'approved', '2026-09-10', [xprDebit($rent, 100), xprDebit($power, 60), xprCredit($cash, 160)]);
    xprVoucher($scope, 'EXP-00002', 'approved', '2026-09-11', [xprDebit($rent, 40), xprCredit($cash, 40)]);

    $response = $this->getJson('/api/reports/expense?show_record=1')->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.total'))->toBe(3)
        ->and($response->json('summary.count'))->toBe(3)
        ->and($response->json('summary.vouchers'))->toBe(2)
        ->and($response->json('summary.total_amount'))->toEqual(200);
});

test('a company user sees only their own expenses, a branch user only their branch', function () {
    $mine = jeaScope('1');
    $theirs = jeaScope('2');

    $rent = xprAccount($mine, '410-00001', 'Office Rent');
    $cash = ['coa_id' => $mine['debit_account_id'], 'code' => $mine['debit_code']];
    $otherBranch = trpBranch($mine['company_id'], 'Second Branch');

    $here = xprVoucher($mine, 'EXP-00001', 'approved', '2026-09-10', [xprDebit($rent, 10), xprCredit($cash, 10)]);
    xprVoucher($mine, 'EXP-00002', 'approved', '2026-09-10', [xprDebit($rent, 20), xprCredit($cash, 20)], ['branch_id' => $otherBranch]);

    $theirRent = xprAccount($theirs, '410-00009', 'Their Rent');
    $theirCash = ['coa_id' => $theirs['debit_account_id'], 'code' => $theirs['debit_code']];
    xprVoucher($theirs, 'EXP-00003', 'approved', '2026-09-10', [xprDebit($theirRent, 30), xprCredit($theirCash, 30)]);

    Sanctum::actingAs(jeaUserWith($mine, []));
    $this->getJson('/api/reports/expense')->assertForbidden();

    Sanctum::actingAs(jeaUserWith($mine, ['/report/expense']));

    $response = $this->getJson("/api/reports/expense?company_id={$theirs['company_id']}&branch_id={$otherBranch}")->assertSuccessful();

    expect(collect($response->json('data.data'))->pluck('voucher_id')->all())->toBe([$here]);
});

test('hostile input to the expense report stays data', function () {
    $scope = jeaScope();
    trpActAsSuperadmin();

    $rent = xprAccount($scope, '410-00001', 'Office Rent');
    $cash = ['coa_id' => $scope['debit_account_id'], 'code' => $scope['debit_code']];
    xprVoucher($scope, 'EXP-00001', 'approved', '2026-09-10', [xprDebit($rent, 10), xprCredit($cash, 10)]);

    $this->getJson('/api/reports/expense?'.http_build_query(['search' => "' OR 1=1 --", 'sort_by' => 'amount; DROP TABLE t_accounts']))
        ->assertSuccessful()
        ->assertJsonPath('summary.count', 0);

    expect(DB::table('t_accounts')->count())->toBe(1);
});
