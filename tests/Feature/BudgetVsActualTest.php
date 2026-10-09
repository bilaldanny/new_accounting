<?php

use App\Models\Budget;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
});

/**
 * Two cost centers with approved September 2026 vouchers: Plant (A) spent 300 in the first branch and 40 in a second,
 * Studio (B) spent 200 and earned 1,000, 25 was spent with no center; a pending 999 and an August 888 on Plant must
 * stay out of September.
 *
 * @return array{scope: array<string, mixed>, a: int, b: int, branch2: int}
 */
function bvaBooks(): array
{
    $scope = faScope();
    $branch2 = trpBranch($scope['company_id'], 'Second Branch');
    ldgAccount($scope, '501-00001', 'Service Revenue', 't', 'cr');
    $a = ccCreate($scope, 'CC-A', ['name' => 'Plant']);
    $b = ccCreate($scope, 'CC-B', ['name' => 'Studio', 'type' => 'profit']);

    $post = function (string $no, string $date, string $code, float $debit, float $credit, ?int $center, array $attributes = [], ?array $use = null) use ($scope): void {
        $voucher = ldgVoucher($use ?? $scope, $no, $date, [[$code, $debit, $credit], ['202-00010', $credit, $debit]], $attributes);

        if ($center !== null) {
            DB::table('t_account_details')->where('t_account_id', $voucher)->where('account_code', $code)->update(['cost_center_id' => $center]);
        }
    };

    $post('JV-1', '2026-09-05', '401-00010', 300, 0, $a);
    $post('JV-2', '2026-09-06', '401-00010', 200, 0, $b);
    $post('JV-3', '2026-09-07', '501-00001', 0, 1000, $b);
    $post('JV-4', '2026-09-09', '401-00010', 25, 0, null);
    $post('JV-5', '2026-09-10', '401-00010', 40, 0, $a, ['branch_id' => $branch2], array_merge($scope, ['branch_id' => $branch2]));
    $post('JV-6', '2026-09-11', '401-00010', 999, 0, $a, ['status' => 'pending']);
    $post('JV-7', '2026-08-01', '401-00010', 888, 0, $a);

    return ['scope' => $scope, 'a' => $a, 'b' => $b, 'branch2' => $branch2];
}

/**
 * @param  array<string, mixed>  $books
 * @param  array<string, mixed>  $extra
 */
function bvaBudgets(array $books): void
{
    $scope = $books['scope'];

    foreach ([
        ['cost_center_id' => $books['a'], 'coa_id' => $scope['accounts']['expense'], 'month' => 9, 'amount' => 500],
        ['cost_center_id' => $books['b'], 'month' => null, 'amount' => 2400],
        ['cost_center_id' => $books['b'], 'coa_id' => DB::table('chart_of_accounts')->where('code', '501-00001')->value('id'), 'month' => 9, 'amount' => 800],
        ['month' => 9, 'amount' => 500],
        ['cost_center_id' => $books['a'], 'branch_id' => $books['branch2'], 'month' => 9, 'amount' => 30],
    ] as $budget) {
        test()->postJson('/api/budgets', ['company_id' => $scope['company_id'], 'year' => 2026] + $budget)->assertSuccessful();
    }
}

/**
 * @param  array<string, mixed>  $books
 * @param  array<string, mixed>  $query
 */
function bvaRows(array $books, array $query = []): Collection
{
    return prpRows(prpGet('budget-vs-actual', array_merge(['show_record' => 100, 'company_id' => $books['scope']['company_id'], 'start_date' => '2026-09-01', 'end_date' => '2026-09-30'], $query)));
}

test('each budget line is compared with what the approved ledger booked in the same scope', function () {
    $books = bvaBooks();
    bvaBudgets($books);

    $rows = bvaRows($books)->keyBy(fn (array $row): string => $row['cost_center_name'].'|'.$row['account_name'].'|'.$row['branch_name']);

    // Plant on the expense account: 300 + 40 across branches, the pending and August vouchers left out.
    $plant = $rows['CC-A - Plant|401-00010 - Depreciation Expense|Whole company'];
    expect($plant['budget'])->toEqual(500)->and($plant['actual'])->toEqual(340)->and($plant['variance'])->toEqual(160)
        ->and($plant['variance_percent'])->toEqual(32)->and($plant['status'])->toBe('Within budget');

    // Studio, all expenses, yearly 2,400 = 200 a month.
    $studio = $rows['CC-B - Studio|All expenses|Whole company'];
    expect($studio['budget'])->toEqual(200)->and($studio['actual'])->toEqual(200)->and($studio['variance'])->toEqual(0)->and($studio['status'])->toBe('Within budget');

    // Studio revenue target: earned more than planned is favourable.
    $revenue = $rows['CC-B - Studio|501-00001 - Service Revenue|Whole company'];
    expect($revenue['kind'])->toBe('revenue')->and($revenue['actual'])->toEqual(1000)->and($revenue['variance'])->toEqual(200)->and($revenue['status'])->toBe('On or above target');

    // No cost center, all expenses of the company: 300 + 200 + 25 + 40 = 565 against 500.
    $company = $rows['All cost centers|All expenses|Whole company'];
    expect($company['actual'])->toEqual(565)->and($company['variance'])->toEqual(-65)->and($company['status'])->toBe('Over budget');

    // Plant in the second branch only.
    $branch = $rows['CC-A - Plant|All expenses|Second Branch'];
    expect($branch['actual'])->toEqual(40)->and($branch['variance'])->toEqual(-10)->and($branch['status'])->toBe('Over budget');
});

test('the summary totals the expense lines and counts those over budget', function () {
    $books = bvaBooks();
    bvaBudgets($books);

    $summary = prpGet('budget-vs-actual', ['company_id' => $books['scope']['company_id'], 'start_date' => '2026-09-01', 'end_date' => '2026-09-30'])->json('summary');

    expect($summary['lines'])->toBe(5)
        ->and($summary['budget'])->toEqual(1230)
        ->and($summary['actual'])->toEqual(1145)
        ->and($summary['variance'])->toEqual(85)
        ->and($summary['over_budget'])->toBe(2);
});

test('a longer range sums the months it touches, a yearly budget counting a twelfth in each', function () {
    $books = bvaBooks();
    bvaBudgets($books);

    $rows = bvaRows($books, ['start_date' => '2026-01-01', 'end_date' => '2026-09-30'])->keyBy(fn (array $row): string => $row['cost_center_name'].'|'.$row['account_name'].'|'.$row['branch_name']);

    // Nine months of a yearly 2,400 is 1,800; the monthly September budgets stay at September's amount.
    expect($rows['CC-B - Studio|All expenses|Whole company']['budget'])->toEqual(1800)
        ->and($rows['CC-A - Plant|401-00010 - Depreciation Expense|Whole company']['budget'])->toEqual(500)
        ->and($rows['CC-A - Plant|401-00010 - Depreciation Expense|Whole company']['actual'])->toEqual(1228);

    // October alone has no September budget and no yearly one covering these scopes except Studio's.
    $october = bvaRows($books, ['start_date' => '2026-10-01', 'end_date' => '2026-10-31']);
    expect($october)->toHaveCount(1)->and($october[0]['cost_center_name'])->toBe('CC-B - Studio')->and($october[0]['budget'])->toEqual(200)->and($october[0]['actual'])->toEqual(0);
});

test('a deleted budget drops out and a pending voucher never counts as actual', function () {
    $books = bvaBooks();
    bvaBudgets($books);
    Budget::query()->where('amount', 500)->whereNull('cost_center_id')->firstOrFail()->delete();

    $names = bvaRows($books)->map(fn (array $row): string => $row['cost_center_name'].'|'.$row['account_name'])->all();

    expect($names)->toHaveCount(4)->and($names)->not->toContain('All cost centers|All expenses');
});

test('the report needs a company and its own permission, and a branch user sees only the branch', function () {
    $books = bvaBooks();
    bvaBudgets($books);

    expect(prpGet('budget-vs-actual', ['show_record' => 100])->json('needs_company'))->toBeTrue();

    $role = Role::query()->create(['name' => 'analyst', 'company_id' => $books['scope']['company_id'], 'is_active' => true]);
    Sanctum::actingAs(createStaffUserForRole($role, ['company_id' => $books['scope']['company_id'], 'branch_id' => $books['branch2']]));

    prpGet('budget-vs-actual', ['company_id' => $books['scope']['company_id']])->assertForbidden();

    grantMenuPermission($role->id, '/report/budget-vs-actual');
    $rows = prpRows(prpGet('budget-vs-actual', ['show_record' => 100, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30']));

    expect($rows)->toHaveCount(1)->and($rows[0]['branch_name'])->toBe('Second Branch');
});

test('the budget report menu migration adds the report next to Stock and grants it to companyadmin', function () {
    DB::table('permissions')->delete();
    DB::table('menus')->delete();
    $reports = DB::table('menus')->insertGetId([
        'parent_id' => null, 'name' => 'Reports', 'icon' => '', 'route_name' => 'reportsgroup', 'route_path' => '', 'menu_color' => '#000', 'sort_order' => 1,
        'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 0, 'type' => 2, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('menus')->insert([
        'parent_id' => $reports, 'name' => 'Stock', 'icon' => '', 'route_name' => 'report.stock', 'route_path' => '/report/stock', 'menu_color' => '#000', 'sort_order' => 1,
        'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $roleId = DB::table('roles')->insertGetId(['name' => 'companyadmin', 'company_id' => null, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    (require database_path('migrations/2026_10_17_200000_add_budget_vs_actual_menu.php'))->up();

    expect((int) DB::table('menus')->where('route_path', '/report/budget-vs-actual')->value('parent_id'))->toBe($reports)
        ->and(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(1);
});
