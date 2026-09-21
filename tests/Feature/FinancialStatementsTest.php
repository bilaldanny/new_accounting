<?php

use App\Http\Controllers\Reports\FinancialReportController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The profit and loss statement (period) and the balance sheet (on a day): classes by the first digit of
 * the account code, approved vouchers only, and a balance sheet that proves itself.
 */

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function fstQuery(array $extra = []): array
{
    return array_merge(['show_record' => 100], $extra);
}

/**
 * Books with revenue (two accounts, both flagged as debit accounts on purpose), a cost of goods sold, an
 * expense, cash, a payable and capital.
 *
 * @return array{scope: array<string, int>, cash: int}
 */
function fstBooks(): array
{
    $scope = trpScope();
    prpFinancialYear($scope['company_id']);

    $cash = ldgAccount($scope, '212-00001', 'Cash in Hand');
    $capital = ldgAccount($scope, '100-00001', 'Owner Capital', 't', 'cr');
    ldgAccount($scope, '311-00001', 'Trade Creditor', 't', 'cr');
    ldgAccount($scope, '511-00001', 'Local Sales', 't', 'dr');
    ldgAccount($scope, '512-00001', 'Export Sales', 't', 'dr');
    ldgAccount($scope, '611-00001', 'Local Purchases');
    ldgAccount($scope, '441-00001', 'General Expense');

    prpOpeningBalance($scope, $cash, 1000, 'dr');
    prpOpeningBalance($scope, $capital, 1000, 'cr');

    ldgVoucher($scope, 'JV-00001', '2026-08-01', [['212-00001', 500, 0], ['511-00001', 0, 500]]);
    ldgVoucher($scope, 'JV-00002', '2026-08-05', [['212-00001', 300, 0], ['512-00001', 0, 300]]);
    ldgVoucher($scope, 'JV-00003', '2026-08-10', [['611-00001', 200, 0], ['311-00001', 0, 200]]);
    ldgVoucher($scope, 'JV-00004', '2026-08-15', [['441-00001', 150, 0], ['212-00001', 0, 150]]);
    ldgVoucher($scope, 'JV-00005', '2026-09-05', [['212-00001', 100, 0], ['511-00001', 0, 100]]);
    ldgVoucher($scope, 'JV-00006', '2026-09-06', [['212-00001', 9999, 0], ['511-00001', 0, 9999]], ['status' => 'pending']);

    return ['scope' => $scope, 'cash' => $cash];
}

/**
 * The amount of a line of a statement by its section and account code (or name).
 */
function fstLine(TestResponse $response, string $line, string $section, string $match = ''): ?float
{
    $row = prpRows($response)->first(fn (array $row): bool => $row['line'] === $line
        && $row['section'] === $section
        && ($match === '' || $row['code'] === $match || $row['name'] === $match));

    return $row === null || $row['amount'] === null ? null : (float) $row['amount'];
}

// ------------------------------------------------------------------ profit and loss

test('the profit and loss sorts accounts into revenue, purchases and expenses by their digit, and with no stock the cost is the purchases', function () {
    $books = fstBooks();
    trpActAsSuperadmin();

    $response = prpGet('profit-loss', fstQuery(['company_id' => $books['scope']['company_id'], 'end_date' => '2026-09-30']));

    expect(fstLine($response, 'account', 'revenue', '511-00001'))->toBe(600.0)
        ->and(fstLine($response, 'account', 'revenue', '512-00001'))->toBe(300.0)
        ->and(fstLine($response, 'total', 'revenue'))->toBe(900.0)
        ->and(fstLine($response, 'account', 'cogs', '611-00001'))->toBe(200.0)
        // These books hold no stock, so both stock lines are 0 and cost of goods sold = 0 + purchases - 0.
        ->and(fstLine($response, 'stock', 'cogs', 'Opening stock'))->toBe(0.0)
        ->and(fstLine($response, 'subtotal', 'cogs', 'Add: purchases'))->toBe(200.0)
        ->and(fstLine($response, 'stock', 'cogs', 'Less: closing stock'))->toBe(0.0)
        ->and(fstLine($response, 'total', 'cogs'))->toBe(200.0)
        ->and(fstLine($response, 'result', 'gross_profit'))->toBe(700.0)
        ->and(fstLine($response, 'account', 'expenses', '441-00001'))->toBe(150.0)
        ->and(fstLine($response, 'total', 'expenses'))->toBe(150.0)
        ->and(fstLine($response, 'result', 'net_profit'))->toBe(550.0)
        ->and($response->json('summary'))->toMatchArray(['count' => 4, 'revenue' => 900, 'opening_stock' => 0, 'purchases' => 200, 'closing_stock' => 0, 'cogs' => 200, 'uncosted_stock' => 0, 'gross_profit' => 700, 'expenses' => 150, 'net_profit' => 550, 'net_margin' => 61.11]);
});

test('assets, liabilities and equity are not in the profit and loss', function () {
    $books = fstBooks();
    trpActAsSuperadmin();

    $codes = prpRows(prpGet('profit-loss', fstQuery(['company_id' => $books['scope']['company_id']])))->where('line', 'account')->pluck('code')->sort()->values()->all();

    expect($codes)->toBe(['441-00001', '511-00001', '512-00001', '611-00001']);
});

test('only the postings of the period count and the period is inclusive at both ends', function () {
    $books = fstBooks();
    trpActAsSuperadmin();

    $statement = fn (array $range) => prpGet('profit-loss', fstQuery(['company_id' => $books['scope']['company_id']] + $range));

    $august = $statement(['start_date' => '2026-08-01', 'end_date' => '2026-08-31']);
    $middle = $statement(['start_date' => '2026-08-10', 'end_date' => '2026-08-15']);
    $oneDay = $statement(['start_date' => '2026-09-05', 'end_date' => '2026-09-05']);
    $empty = $statement(['start_date' => '2027-01-01', 'end_date' => '2027-01-31']);

    expect($august->json('summary.revenue'))->toEqual(800)
        ->and($august->json('summary.net_profit'))->toEqual(450)
        ->and($middle->json('summary.revenue'))->toEqual(0)
        ->and($middle->json('summary.cogs'))->toEqual(200)
        ->and($middle->json('summary.expenses'))->toEqual(150)
        ->and($middle->json('summary.net_profit'))->toEqual(-350)
        ->and(fstLine($middle, 'result', 'net_profit', 'Net loss'))->toBe(-350.0)
        ->and($oneDay->json('summary.revenue'))->toEqual(100)
        ->and($oneDay->json('summary.net_profit'))->toEqual(100)
        ->and($empty->json('summary'))->toMatchArray(['revenue' => 0, 'net_profit' => 0, 'net_margin' => 0]);
});

test('the profit and loss defaults to the financial year up to today and says so', function () {
    $books = fstBooks();
    trpActAsSuperadmin();

    $response = prpGet('profit-loss', fstQuery(['company_id' => $books['scope']['company_id']]));

    expect($response->json('summary.from'))->toBe('2026-07-01')
        ->and($response->json('summary.to'))->toBe(now()->toDateString());
});

test('a contra revenue account shows negative and a debit on a revenue account is not revenue', function () {
    $books = fstBooks();
    $scope = $books['scope'];
    trpActAsSuperadmin();

    ldgAccount($scope, '513-00001', 'Sales Returns', 't', 'dr');
    ldgVoucher($scope, 'JV-00007', '2026-09-10', [['513-00001', 80, 0], ['212-00001', 0, 80]]);

    $response = prpGet('profit-loss', fstQuery(['company_id' => $scope['company_id'], 'end_date' => '2026-09-30']));

    expect(fstLine($response, 'account', 'revenue', '513-00001'))->toBe(-80.0)
        ->and(fstLine($response, 'total', 'revenue'))->toBe(820.0)
        ->and($response->json('summary.net_profit'))->toEqual(470);
});

test('the branch filter limits the statements to that branch', function () {
    $books = fstBooks();
    $scope = $books['scope'];
    $otherBranch = trpBranch($scope['company_id'], 'Second Branch');
    trpActAsSuperadmin();

    ldgVoucher(array_merge($scope, ['branch_id' => $otherBranch]), 'JV-00008', '2026-09-08', [['212-00001', 60, 0], ['511-00001', 0, 60]], ['branch_id' => $otherBranch]);

    $profit = fn (array $extra): float => (float) prpGet('profit-loss', fstQuery(['company_id' => $scope['company_id'], 'end_date' => '2026-09-30'] + $extra))->json('summary.net_profit');

    expect($profit([]))->toBe(610.0)
        ->and($profit(['branch_id' => $otherBranch]))->toBe(60.0)
        ->and($profit(['branch_id' => $scope['branch_id']]))->toBe(550.0);
});

// ------------------------------------------------------------------ balance sheet

test('the balance sheet balances: assets against liabilities, equity and the profit to date', function () {
    $books = fstBooks();
    trpActAsSuperadmin();

    $response = prpGet('balance-sheet', fstQuery(['company_id' => $books['scope']['company_id'], 'end_date' => '2026-09-30']));

    // cash 1000 + 500 + 300 - 150 + 100 = 1750; payable 200; capital 1000 + profit 550.
    expect(fstLine($response, 'account', 'assets', '212-00001'))->toBe(1750.0)
        ->and(fstLine($response, 'total', 'assets'))->toBe(1750.0)
        ->and(fstLine($response, 'account', 'liabilities', '311-00001'))->toBe(200.0)
        ->and(fstLine($response, 'total', 'liabilities'))->toBe(200.0)
        ->and(fstLine($response, 'account', 'equity', '100-00001'))->toBe(1000.0)
        ->and(fstLine($response, 'account', 'equity', 'Profit for the year to date'))->toBe(550.0)
        ->and(fstLine($response, 'total', 'equity'))->toBe(1550.0)
        ->and(fstLine($response, 'result', 'liabilities_equity'))->toBe(1750.0)
        ->and(fstLine($response, 'result', 'difference'))->toBe(0.0)
        ->and($response->json('summary'))->toMatchArray(['is_balanced' => true, 'as_of' => '2026-09-30'])
        ->and($response->json('summary.total_assets'))->toEqual(1750)
        ->and($response->json('summary.profit'))->toEqual(550)
        ->and($response->json('summary.difference'))->toEqual(0);
});

test('the balance sheet is as of the day asked for, inclusive, and still balances', function () {
    $books = fstBooks();
    trpActAsSuperadmin();

    $sheet = fn (string $day) => prpGet('balance-sheet', fstQuery(['company_id' => $books['scope']['company_id'], 'end_date' => $day]));

    $early = $sheet('2026-08-05');
    $afterPurchase = $sheet('2026-08-10');

    // On 5 August: cash 1800, no payable yet, profit 800.
    expect($early->json('summary.total_assets'))->toEqual(1800)
        ->and($early->json('summary.total_liabilities'))->toEqual(0)
        ->and($early->json('summary.profit'))->toEqual(800)
        ->and($early->json('summary.is_balanced'))->toBeTrue()
        ->and($afterPurchase->json('summary.total_liabilities'))->toEqual(200)
        ->and($afterPurchase->json('summary.profit'))->toEqual(600)
        ->and($afterPurchase->json('summary.is_balanced'))->toBeTrue()
        ->and($sheet('2026-07-15')->json('summary.total_assets'))->toEqual(1000)
        ->and($sheet('2026-07-15')->json('summary.total_equity'))->toEqual(1000)
        ->and($sheet('2026-07-15')->json('summary.is_balanced'))->toBeTrue();
});

test('an account outside the six classes shows up as a difference instead of being hidden', function () {
    $books = fstBooks();
    $scope = $books['scope'];
    trpActAsSuperadmin();

    ldgAccount($scope, '711-00001', 'Unclassified');
    ldgVoucher($scope, 'JV-00009', '2026-09-10', [['711-00001', 50, 0], ['212-00001', 0, 50]]);

    $response = prpGet('balance-sheet', fstQuery(['company_id' => $scope['company_id'], 'end_date' => '2026-09-30']));

    expect($response->json('summary.total_assets'))->toEqual(1700)
        ->and($response->json('summary.difference'))->toEqual(-50)
        ->and($response->json('summary.is_balanced'))->toBeFalse();
});

test('a loss is shown as a loss and an overdrawn asset shows negative', function () {
    $scope = trpScope();
    prpFinancialYear($scope['company_id']);
    ldgAccount($scope, '212-00001', 'Cash in Hand');
    ldgAccount($scope, '100-00001', 'Owner Capital', 't', 'cr');
    ldgAccount($scope, '441-00001', 'General Expense');
    trpActAsSuperadmin();

    ldgVoucher($scope, 'JV-00001', '2026-08-01', [['441-00001', 300, 0], ['212-00001', 0, 300]]);

    $response = prpGet('balance-sheet', fstQuery(['company_id' => $scope['company_id'], 'end_date' => '2026-09-30']));

    expect(fstLine($response, 'account', 'assets', '212-00001'))->toBe(-300.0)
        ->and(fstLine($response, 'account', 'equity', 'Loss for the year to date'))->toBe(-300.0)
        ->and($response->json('summary.total_equity'))->toEqual(-300)
        ->and($response->json('summary.is_balanced'))->toBeTrue();
});

test('pending vouchers stay out of both statements and the branch filter limits the balance sheet', function () {
    $books = fstBooks();
    $scope = $books['scope'];
    $otherBranch = trpBranch($scope['company_id'], 'Second Branch');
    trpActAsSuperadmin();

    ldgVoucher(array_merge($scope, ['branch_id' => $otherBranch]), 'JV-00010', '2026-09-08', [['212-00001', 60, 0], ['511-00001', 0, 60]], ['branch_id' => $otherBranch]);

    $assets = fn (array $extra): float => (float) prpGet('balance-sheet', fstQuery(['company_id' => $scope['company_id'], 'end_date' => '2026-09-30'] + $extra))->json('summary.total_assets');

    // The pending voucher of 9999 is in neither.
    expect($assets([]))->toBe(1810.0)
        ->and($assets(['branch_id' => $otherBranch]))->toBe(60.0)
        ->and($assets(['branch_id' => $scope['branch_id']]))->toBe(1750.0);
});

// ------------------------------------------------------------------ both statements

dataset('financial reports', [
    'profit and loss' => ['profit-loss', '/report/profit-loss'],
    'balance sheet' => ['balance-sheet', '/report/balance-sheet'],
]);

test('the statements need a company, are scoped to it and reject a bad range', function (string $report, string $figure, int $own) {
    $books = fstBooks();
    $other = trpScope('2');
    prpFinancialYear($other['company_id']);
    ldgAccount($other, '511-00001', 'Foreign Sales');
    ldgVoucher($other, 'JV-00001', '2026-09-01', [['212-00001', 5000, 0], ['511-00001', 0, 5000]]);

    trpActAsSuperadmin();
    prpGet($report, fstQuery())->assertUnprocessable();

    Sanctum::actingAs(jeaUserWith($books['scope'], ["/report/{$report}"]));

    $response = prpGet($report, fstQuery(['company_id' => $other['company_id'], 'end_date' => '2026-09-30']));

    // Their 5000 is not in the signed in user's figures, whatever company they ask for.
    expect($response->json("summary.{$figure}"))->toEqual($own);

    prpGet($report, fstQuery(['start_date' => '2026-09-30', 'end_date' => '2026-09-01']))->assertUnprocessable();
})->with(fn () => [['profit-loss', 'revenue', 900], ['balance-sheet', 'total_assets', 1750]]);

test('the statement reads in order and rows sort only by a known column', function (string $report) {
    $books = fstBooks();
    trpActAsSuperadmin();

    $ids = fn (array $extra): array => prpRows(prpGet($report, fstQuery(['company_id' => $books['scope']['company_id']] + $extra)))->pluck('id')->all();

    expect($ids([]))->toBe(collect($ids([]))->sort()->values()->all())
        ->and($ids(['sort_by' => 'x; drop table users']))->toBe($ids([]))
        ->and($ids(['sort_by' => 'amount', 'sort_type' => 'desc']))->toHaveCount(count($ids([])));
})->with(fn () => [['profit-loss'], ['balance-sheet']]);

test('the report answers with a page of rows and a summary, and needs its menu row', function (string $report, string $path) {
    $scope = trpScope();

    $this->getJson("/api/reports/{$report}")->assertUnauthorized();

    Sanctum::actingAs(jeaUserWith($scope, []));
    $this->getJson("/api/reports/{$report}")->assertForbidden();

    Sanctum::actingAs(jeaUserWith($scope, [$path]));
    $this->getJson("/api/reports/{$report}")
        ->assertSuccessful()
        ->assertJsonStructure(['data' => ['data', 'current_page', 'last_page', 'per_page', 'total'], 'summary' => ['count'], 'trash_count']);
})->with('financial reports');

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
})->with('financial reports');

function fstMenuMigration(): object
{
    return require database_path('migrations/2026_09_21_160000_add_financial_report_menus.php');
}

test('the statements sit in the Reports group with a hidden export row and roll back cleanly', function () {
    expect(array_keys(FinancialReportController::PERMISSIONS))->toBe(['profit-loss', 'balance-sheet']);

    $groupId = DB::table('menus')->whereNull('parent_id')->where('name', 'Reports')->where('type', 2)->value('id');
    $paths = array_values(FinancialReportController::PERMISSIONS);

    foreach ($paths as $path) {
        $page = DB::table('menus')->where('route_path', $path)->first();
        $export = DB::table('menus')->where('route_path', $path.'/export')->first();

        expect($page)->not->toBeNull()
            ->and((int) $page->parent_id)->toBe((int) $groupId)
            ->and($export)->not->toBeNull()
            ->and((int) $export->is_hidden)->toBe(1);
    }

    $before = DB::table('menus')->count();
    fstMenuMigration()->up();
    expect(DB::table('menus')->count())->toBe($before);

    $pageId = (int) DB::table('menus')->where('route_path', '/report/balance-sheet')->value('id');
    Permission::query()->create(['role_id' => Role::query()->create(['name' => 'cfo', 'is_active' => true])->id, 'menu_id' => $pageId, 'status' => 1]);

    fstMenuMigration()->down();

    expect(DB::table('menus')->whereIn('route_path', $paths)->exists())->toBeFalse()
        ->and(DB::table('permissions')->where('menu_id', $pageId)->exists())->toBeFalse()
        ->and(DB::table('menus')->where('route_path', '/report/trial-balance')->exists())->toBeTrue();

    fstMenuMigration()->up();

    expect(DB::table('menus')->whereIn('route_path', $paths)->count())->toBe(2);
});
