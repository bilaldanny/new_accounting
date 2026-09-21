<?php

use App\Http\Controllers\Reports\LedgerReportController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The receipts and payments voucher report (approved BD/CD/OD/SP receipts and BP/CP/OP/PP payments) and
 * the access and menu rows of the three accounts reports.
 */

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function vchQuery(array $extra = []): array
{
    return array_merge(['show_record' => 100], $extra);
}

/**
 * A set of vouchers: every receipt and payment prefix, plus ones that must stay out.
 *
 * @return array{scope: array<string, int>, ids: array<string, int>, customer: int, supplier: int, bank: int}
 */
function vchVouchers(): array
{
    $scope = trpScope();
    $bank = ldgAccount($scope, '211-00001', 'Meezan Bank');
    ldgAccount($scope, '213-00001', 'Customer Account');
    ldgAccount($scope, '311-00001', 'Supplier Account', 't', 'cr');
    $customer = prpContact($scope, 'customer', 'Voucher Customer');
    $supplier = prpContact($scope, 'supplier', 'Voucher Supplier');

    $receipt = fn (string $no, string $date, float $amount, array $attributes = []): int => ldgVoucher(
        $scope, $no, $date, [['211-00001', $amount, 0, null, $bank], ['213-00001', 0, $amount, $customer]], ['coa_id' => $bank] + $attributes,
    );
    $payment = fn (string $no, string $date, float $amount, array $attributes = []): int => ldgVoucher(
        $scope, $no, $date, [['311-00001', $amount, 0, $supplier], ['211-00001', 0, $amount, null, $bank]], ['coa_id' => $bank] + $attributes,
    );

    $ids = [
        'BD' => $receipt('BD-00001', '2026-09-01', 1000, ['ref_no' => 'REC-1', 'cheque_no' => 'CH-77', 'cheque_post_date' => '2026-09-05', 'comments' => 'Cheque from customer']),
        'CD' => $receipt('CD-00001', '2026-09-02', 200),
        'OD' => $receipt('OD-00001', '2026-09-03', 300),
        'SP' => $receipt('SP-00001', '2026-09-04', 400),
        'BP' => $payment('BP-00001', '2026-09-01', 150),
        'CP' => $payment('CP-00001', '2026-09-02', 50),
        'OP' => $payment('OP-00001', '2026-09-03', 25),
        'PP' => $payment('PP-00001', '2026-09-04', 75),
    ];

    // Approved vouchers of other kinds, unapproved ones and system postings tied to a document stay out.
    ldgVoucher($scope, 'JV-00001', '2026-09-01', [['211-00001', 9999, 0], ['213-00001', 0, 9999]]);
    ldgVoucher($scope, 'EXP-00001', '2026-09-01', [['211-00001', 9999, 0], ['213-00001', 0, 9999]]);
    ldgVoucher($scope, 'FT-00001', '2026-09-01', [['211-00001', 9999, 0], ['213-00001', 0, 9999]]);
    $receipt('BD-00002', '2026-09-01', 9999, ['status' => 'pending']);
    $receipt('BD-00003', '2026-09-01', 9999, ['status' => 'rejected']);
    $receipt('SP-00002', '2026-09-01', 9999, ['transaction_id' => trpDoc($scope, 'sell')]);
    $receipt('XBD-00001', '2026-09-01', 9999);

    return ['scope' => $scope, 'ids' => $ids, 'customer' => $customer, 'supplier' => $supplier, 'bank' => $bank];
}

test('receipts and payments are listed with their type, party, accounts and cheque', function () {
    $set = vchVouchers();
    trpActAsSuperadmin();

    $response = prpGet('vouchers', vchQuery(['company_id' => $set['scope']['company_id']]));
    $row = fn (string $prefix): array => prpRow($response, $set['ids'][$prefix]);

    expect($response->json('data.total'))->toBe(8)
        ->and($row('BD'))->toMatchArray([
            'voucher_no' => 'BD-00001',
            'kind' => 'receipt',
            'label' => 'Bank deposit',
            'party_name' => 'Voucher Customer',
            'header_account' => 'Meezan Bank',
            'accounts' => '213-00001',
            'ref_no' => 'REC-1',
            'cheque_no' => 'CH-77',
            'cheque_date' => '2026-09-05',
            'description' => 'Cheque from customer',
            'voucher_date' => '2026-09-01',
        ])
        ->and($row('BD')['amount'])->toEqual(1000)
        ->and($row('SP')['label'])->toBe('Sale payment')
        ->and($row('SP')['kind'])->toBe('receipt')
        ->and($row('PP')['label'])->toBe('Purchase payment')
        ->and($row('PP')['kind'])->toBe('payment')
        ->and($row('PP')['party_name'])->toBe('Voucher Supplier')
        ->and($row('PP')['accounts'])->toBe('311-00001')
        ->and($row('BP')['amount'])->toEqual(150)
        ->and(collect(['CD', 'OD', 'CP', 'OP'])->map(fn (string $p): string => $row($p)['label'])->all())->toBe(['Cash deposit', 'Online deposit', 'Cash payment', 'Online payment']);
});

test('only approved receipt and payment vouchers count, not journals, expenses, transfers or system postings', function () {
    $set = vchVouchers();
    trpActAsSuperadmin();

    $numbers = prpRows(prpGet('vouchers', vchQuery(['company_id' => $set['scope']['company_id']])))->pluck('voucher_no')->sort()->values()->all();

    expect($numbers)->toBe(['BD-00001', 'BP-00001', 'CD-00001', 'CP-00001', 'OD-00001', 'OP-00001', 'PP-00001', 'SP-00001']);
});

test('the summary splits receipts from payments and gives the net', function () {
    $set = vchVouchers();
    trpActAsSuperadmin();

    $response = prpGet('vouchers', vchQuery(['company_id' => $set['scope']['company_id']]));

    expect($response->json('summary'))->toMatchArray(['count' => 8, 'receipt_count' => 4, 'payment_count' => 4])
        ->and($response->json('summary.receipts'))->toEqual(1900)
        ->and($response->json('summary.payments'))->toEqual(300)
        ->and($response->json('summary.net'))->toEqual(1600);
});

test('the voucher type, party, date range and search filters narrow the report', function () {
    $set = vchVouchers();
    trpActAsSuperadmin();

    $numbers = fn (array $extra): array => prpRows(prpGet('vouchers', vchQuery(['company_id' => $set['scope']['company_id']] + $extra)))->pluck('voucher_no')->sort()->values()->all();

    expect($numbers(['voucher_type' => 'receipt']))->toBe(['BD-00001', 'CD-00001', 'OD-00001', 'SP-00001'])
        ->and($numbers(['voucher_type' => 'payment']))->toBe(['BP-00001', 'CP-00001', 'OP-00001', 'PP-00001'])
        ->and($numbers(['voucher_type' => 'all']))->toHaveCount(8)
        ->and($numbers(['contact_id' => $set['customer']]))->toBe(['BD-00001', 'CD-00001', 'OD-00001', 'SP-00001'])
        ->and($numbers(['contact_id' => $set['supplier']]))->toBe(['BP-00001', 'CP-00001', 'OP-00001', 'PP-00001'])
        ->and($numbers(['start_date' => '2026-09-02', 'end_date' => '2026-09-03']))->toBe(['CD-00001', 'CP-00001', 'OD-00001', 'OP-00001'])
        ->and($numbers(['start_date' => '2026-09-04']))->toBe(['PP-00001', 'SP-00001'])
        ->and($numbers(['end_date' => '2026-09-01']))->toBe(['BD-00001', 'BP-00001'])
        ->and($numbers(['search' => 'CH-77']))->toBe(['BD-00001'])
        ->and($numbers(['search' => 'rec-1']))->toBe(['BD-00001'])
        ->and($numbers(['search' => 'from customer']))->toBe(['BD-00001'])
        ->and($numbers(['search' => 'PP-0']))->toBe(['PP-00001']);

    prpGet('vouchers', vchQuery(['voucher_type' => 'journal']))->assertUnprocessable();
});

test('a voucher with several lines shows its whole amount and every account', function () {
    $set = vchVouchers();
    trpActAsSuperadmin();

    ldgAccount($set['scope'], '213-00002', 'Second Debtor');
    $id = ldgVoucher($set['scope'], 'BD-00009', '2026-09-10', [
        ['211-00001', 700, 0, null, $set['bank']],
        ['213-00001', 0, 400, $set['customer']],
        ['213-00002', 0, 300],
    ], ['coa_id' => $set['bank']]);

    $row = prpRow(prpGet('vouchers', vchQuery(['company_id' => $set['scope']['company_id']])), $id);

    expect($row['amount'])->toEqual(700)
        ->and($row['accounts'])->toBe('213-00001, 213-00002')
        ->and($row['party_name'])->toBe('Voucher Customer');
});

test('rows sort by a known column and fall back to the newest first', function () {
    $set = vchVouchers();
    trpActAsSuperadmin();

    $order = fn (array $extra): array => prpRows(prpGet('vouchers', vchQuery(['company_id' => $set['scope']['company_id'], 'voucher_type' => 'receipt'] + $extra)))->pluck('voucher_no')->all();

    expect($order([]))->toBe(['SP-00001', 'OD-00001', 'CD-00001', 'BD-00001'])
        ->and($order(['sort_by' => 'amount', 'sort_type' => 'asc']))->toBe(['CD-00001', 'OD-00001', 'SP-00001', 'BD-00001'])
        ->and($order(['sort_by' => 'x; drop table users']))->toBe(['SP-00001', 'OD-00001', 'CD-00001', 'BD-00001']);
});

test('a company user sees only their own vouchers and a branch user only their branch', function () {
    $set = vchVouchers();
    $scope = $set['scope'];
    $otherBranch = trpBranch($scope['company_id'], 'Second Branch');
    $other = trpScope('2');
    ldgAccount($other, '211-00001', 'Foreign Bank');
    ldgVoucher($other, 'BD-00001', '2026-09-01', [['211-00001', 5000, 0], ['213-00001', 0, 5000]]);
    ldgVoucher(array_merge($scope, ['branch_id' => $otherBranch]), 'BD-00050', '2026-09-01', [['211-00001', 60, 0], ['213-00001', 0, 60]], ['branch_id' => $otherBranch]);

    Sanctum::actingAs(jeaUserWith($scope, ['/report/vouchers']));

    $mine = prpRows(prpGet('vouchers', vchQuery(['company_id' => $other['company_id']])))->pluck('voucher_no')->sort()->values()->all();

    expect($mine)->toHaveCount(8)
        ->and($mine)->not->toContain('BD-00050');

    prpGet('vouchers', vchQuery(['search' => "' OR 1=1 --"]))->assertSuccessful()->assertJsonPath('summary.count', 0);

    trpActAsSuperadmin();

    expect(prpRows(prpGet('vouchers', vchQuery(['company_id' => $scope['company_id'], 'branch_id' => $otherBranch])))->pluck('voucher_no')->all())->toBe(['BD-00050']);
});

test('the report is empty without vouchers', function () {
    trpScope();
    trpActAsSuperadmin();

    prpGet('vouchers', vchQuery())
        ->assertSuccessful()
        ->assertJsonPath('data.total', 0)
        ->assertJsonPath('summary.net', 0);
});

// ------------------------------------------------------------------ access and menu rows

dataset('ledger reports', [
    'account ledger' => ['account-ledger', '/report/account-ledger'],
    'trial balance' => ['trial-balance', '/report/trial-balance'],
    'vouchers' => ['vouchers', '/report/vouchers'],
]);

test('the report answers with a page of rows and a summary, and needs its menu row', function (string $report, string $path) {
    $scope = trpScope();

    $this->getJson("/api/reports/{$report}")->assertUnauthorized();

    Sanctum::actingAs(jeaUserWith($scope, []));
    $this->getJson("/api/reports/{$report}")->assertForbidden();

    Sanctum::actingAs(jeaUserWith($scope, [$path]));
    $this->getJson("/api/reports/{$report}")
        ->assertSuccessful()
        ->assertJsonStructure(['data' => ['data', 'current_page', 'last_page', 'per_page', 'total'], 'summary' => ['count'], 'trash_count']);
})->with('ledger reports');

test('a page needs the same menu row and a signed in user', function (string $report, string $path) {
    $scope = trpScope();

    $this->get(route("report.{$report}"))->assertRedirect();
    $this->actingAs(User::query()->findOrFail(1))
        ->get(route("report.{$report}"))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('report/index')->where('report', $report));
    $this->actingAs(jeaUserWith($scope, []))->get(route("report.{$report}"))->assertForbidden();
    $this->actingAs(jeaUserWith($scope, [$path]))->get(route("report.{$report}"))->assertSuccessful();

    expect(app('router')->getRoutes()->match(Request::create($path, 'GET'))->getName())->toBe("report.{$report}");
})->with('ledger reports');

function vchMenuMigration(): object
{
    return require database_path('migrations/2026_09_21_150000_add_ledger_report_menus.php');
}

test('the accounts reports sit in the Reports group with a hidden export row and roll back cleanly', function () {
    expect(array_keys(LedgerReportController::PERMISSIONS))->toBe(['account-ledger', 'trial-balance', 'vouchers']);

    $groupId = DB::table('menus')->whereNull('parent_id')->where('name', 'Reports')->where('type', 2)->value('id');
    $paths = array_values(LedgerReportController::PERMISSIONS);

    foreach ($paths as $path) {
        $page = DB::table('menus')->where('route_path', $path)->first();
        $export = DB::table('menus')->where('route_path', $path.'/export')->first();

        expect($page)->not->toBeNull()
            ->and((int) $page->parent_id)->toBe((int) $groupId)
            ->and($export)->not->toBeNull()
            ->and((int) $export->is_hidden)->toBe(1);
    }

    $before = DB::table('menus')->count();
    vchMenuMigration()->up();
    expect(DB::table('menus')->count())->toBe($before);

    $pageId = (int) DB::table('menus')->where('route_path', '/report/trial-balance')->value('id');
    Permission::query()->create(['role_id' => Role::query()->create(['name' => 'auditor', 'is_active' => true])->id, 'menu_id' => $pageId, 'status' => 1]);

    vchMenuMigration()->down();

    expect(DB::table('menus')->whereIn('route_path', $paths)->exists())->toBeFalse()
        ->and(DB::table('permissions')->where('menu_id', $pageId)->exists())->toBeFalse()
        ->and(DB::table('menus')->where('route_path', '/report/ledger')->exists())->toBeTrue();

    vchMenuMigration()->up();

    expect(DB::table('menus')->whereIn('route_path', $paths)->count())->toBe(3);
});
