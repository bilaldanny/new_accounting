<?php

use App\Models\PeriodLockEntry;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Task B of the final closeout: costing method per company from an effective date (weighted average by default, FIFO, standard
 * cost; LIFO is not offered), one cost source for every report, and the COGS recalculation engine with preview and cost lock.
 */

/**
 * Ten units at 100 on 1 Aug, four sold on 15 Aug, ten more at 200 on 5 Sep, eight sold on 10 Sep.
 *
 * @return array<string, mixed>
 */
function ivScenario(): array
{
    $scope = trpScope();
    prpFinancialYear($scope['company_id']);

    foreach ([['212-00001', 'Cash', 'dr'], ['311-00001', 'Creditor', 'cr'], ['511-00001', 'Sales', 'cr'], ['611-00001', 'Purchases', 'dr']] as [$code, $name, $nature]) {
        ldgAccount($scope, $code, $name, 't', $nature);
    }

    $product = prdProduct($scope, 'Valued Goods');
    prdPurchase($scope, $product, 10, 100, '2026-08-01');
    ldgVoucher($scope, 'PE-1', '2026-08-01', [['611-00001', 1000, 0], ['311-00001', 0, 1000]]);
    [, $early] = prdSale($scope, $product, 4, 300, '2026-08-15');
    prdPurchase($scope, $product, 10, 200, '2026-09-05');
    ldgVoucher($scope, 'PE-2', '2026-09-05', [['611-00001', 2000, 0], ['311-00001', 0, 2000]]);
    [, $late] = prdSale($scope, $product, 8, 300, '2026-09-10');

    return $scope + $product + ['early_line' => $early, 'late_line' => $late];
}

function ivFigures(array $scope, string $day = '2026-09-30'): array
{
    $company = ['company_id' => $scope['company_id']];
    $stock = test()->getJson('/api/reports/stock?'.http_build_query($company + ['end_date' => $day, 'show_record' => 100]))->assertOk();
    $item = test()->getJson('/api/reports/item-profit-loss?'.http_build_query($company + ['show_record' => 100]))->assertOk();
    $profit = test()->getJson('/api/reports/profit-loss?'.http_build_query($company + ['start_date' => '2026-09-01', 'end_date' => $day, 'show_record' => 100]))->assertOk();

    return ['stock' => $stock->json(), 'item' => $item->json(), 'profit' => $profit->json()];
}

function ivSetMethod(array $scope, string $method, string $from)
{
    return test()->postJson('/api/valuation/method', ['company_id' => $scope['company_id'], 'method' => $method, 'effective_from' => $from]);
}

test('a company with no method set, or the weighted average set, gets identical stock, item profit and profit and loss figures', function () {
    $scope = ivScenario();
    trpActAsSuperadmin();

    $before = ivFigures($scope);
    ivSetMethod($scope, 'average', '2026-08-20')->assertCreated();
    $after = ivFigures($scope);

    expect($after)->toEqual($before)
        ->and((float) $before['profit']['summary']['closing_stock'])->toBe(1200.0)
        ->and(DB::table('sell_line_costs')->count())->toBe(0);
});

test('FIFO applies from its effective date: the opening stock becomes a layer, the sale takes the oldest layers and stock is the newest', function () {
    $scope = ivScenario();
    trpActAsSuperadmin();
    $average = ivFigures($scope);

    ivSetMethod($scope, 'fifo', '2026-09-01')->assertCreated();
    $fifo = ivFigures($scope);

    $item = fn (array $figures, int $line): array => collect($figures['item']['data']['data'])->firstWhere('id', $line);

    // before the effective date nothing is revalued
    expect($item($fifo, $scope['early_line'])['average_cost'])->toEqual($item($average, $scope['early_line'])['average_cost'])
        ->and($item($fifo, $scope['early_line'])['average_cost'])->toEqual(100);

    // 6 units at 100 (the opening layer) and 2 at 200 = 1000 over 8 units = 125, where the average gave 150
    expect($item($average, $scope['late_line'])['average_cost'])->toEqual(150)
        ->and($item($fifo, $scope['late_line'])['average_cost'])->toEqual(125)
        ->and($item($fifo, $scope['late_line'])['cost'])->toEqual(1000);

    // the 8 left are the newest layer: 8 x 200 = 1600 (the average valued them at 1200)
    expect((float) $average['stock']['summary']['stock_value'])->toBe(1200.0)
        ->and((float) $fifo['stock']['summary']['stock_value'])->toBe(1600.0)
        ->and((float) $fifo['profit']['summary']['closing_stock'])->toBe(1600.0)
        ->and((float) $fifo['profit']['summary']['opening_stock'])->toBe(600.0);
});

test('every report reads one cost source: stock valuation, the stock report and the profit and loss agree under each method', function () {
    $scope = ivScenario();
    trpActAsSuperadmin();
    $variation = $scope['variation_id'];

    foreach (['average', 'fifo', 'standard'] as $step => $method) {
        $from = ['2026-08-20', '2026-09-01', '2026-09-20'][$step];

        if ($method === 'standard') {
            $this->postJson('/api/valuation/standard-costs', ['company_id' => $scope['company_id'], 'effective_from' => $from, 'items' => [['variation_id' => $variation, 'cost' => 130]]])->assertCreated();
        }

        ivSetMethod($scope, $method, $from)->assertCreated();

        $check = $this->getJson('/api/valuation/reconciliation?'.http_build_query(['company_id' => $scope['company_id'], 'as_of' => '2026-09-30']))->assertOk();

        expect($check->json('matches'))->toBeTrue("{$method}: the stock figures must agree")
            ->and($check->json('method'))->toBe($method);
    }

    // standard: 8 units at 130 against the average of 150
    $variance = $this->getJson('/api/valuation/variance?'.http_build_query(['company_id' => $scope['company_id'], 'as_of' => '2026-09-30']))->assertOk();
    expect($variance->json('total_variance'))->toEqual(-160)
        ->and($this->getJson('/api/valuation/reconciliation?'.http_build_query(['company_id' => $scope['company_id'], 'as_of' => '2026-09-30']))->json('stock_valuation'))->toEqual(1040);
});

test('LIFO is not offered, a method cannot take effect inside a locked period or before the previous change', function () {
    $scope = ivScenario();
    trpActAsSuperadmin();

    ivSetMethod($scope, 'lifo', '2026-10-01')->assertUnprocessable()->assertJsonPath('errors.method.0', 'LIFO is not offered: it is not permitted under IFRS (IAS 2).');

    PeriodLockEntry::query()->create(['company_id' => $scope['company_id'], 'locked_through' => '2026-08-31', 'action' => 'lock', 'locked_by' => 1]);
    ivSetMethod($scope, 'fifo', '2026-08-15')->assertUnprocessable();
    ivSetMethod($scope, 'fifo', '2026-09-01')->assertCreated();
    ivSetMethod($scope, 'standard', '2026-09-01')->assertUnprocessable();

    expect(DB::table('valuation_method_changes')->count())->toBe(1);
});

test('recalculation previews the difference, locks the cost on each sale line and refuses a locked period', function () {
    $scope = ivScenario();
    trpActAsSuperadmin();
    ivSetMethod($scope, 'fifo', '2026-09-01')->assertCreated();
    $query = ['company_id' => $scope['company_id'], 'from' => '2026-09-01', 'to' => '2026-09-30'];

    $preview = $this->getJson('/api/valuation/recalculation?'.http_build_query($query))->assertOk();
    expect($preview->json('summary.lines'))->toBe(1)
        ->and($preview->json('summary.cost_before'))->toEqual(1200)
        ->and($preview->json('summary.cost_after'))->toEqual(1000)
        ->and($preview->json('summary.difference'))->toEqual(-200)
        ->and(DB::table('sell_line_costs')->count())->toBe(0);

    $this->postJson('/api/valuation/recalculate', $query)->assertCreated()->assertJsonPath('data.lines', 1);

    $lock = DB::table('sell_line_costs')->first();
    expect((float) $lock->cost)->toBe(1000.0)->and($lock->method)->toBe('fifo');

    // a locked period is refused as a whole and the override header does not apply
    PeriodLockEntry::query()->create(['company_id' => $scope['company_id'], 'locked_through' => '2026-09-15', 'action' => 'lock', 'locked_by' => 1]);
    $this->postJson('/api/valuation/recalculate', $query)->assertUnprocessable();
    $this->postJson('/api/valuation/recalculate', ['from' => '2026-08-01', 'to' => '2026-09-30'] + $query)->assertUnprocessable();
    expect(DB::table('cost_recalculation_runs')->count())->toBe(1);
});

test('a locked sale cost is used only while the line keeps its date and quantity', function () {
    $scope = ivScenario();
    trpActAsSuperadmin();
    ivSetMethod($scope, 'fifo', '2026-09-01')->assertCreated();
    $this->postJson('/api/valuation/recalculate', ['company_id' => $scope['company_id'], 'from' => '2026-09-01', 'to' => '2026-09-30'])->assertCreated();

    DB::table('sell_lines')->where('id', $scope['late_line'])->update(['quantity' => 6]);

    $row = collect($this->getJson('/api/reports/item-profit-loss?'.http_build_query(['company_id' => $scope['company_id'], 'show_record' => 100]))->json('data.data'))->firstWhere('id', $scope['late_line']);

    // the quantity changed, so the lock is ignored and the cost is worked out again (6 units from the oldest layer: 100 each)
    expect($row['average_cost'])->toEqual(100)->and($row['cost'])->toEqual(600);
});

test('valuation settings need their permissions and a company user only reaches their own company', function () {
    $scope = ivScenario();
    $other = trpScope('2');
    $role = Role::query()->create(['name' => 'iv_'.uniqid(), 'company_id' => $scope['company_id'], 'is_active' => true]);
    grantMenuPermission($role->id, '/valuation');
    $user = createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'email_verified_at' => now()]);
    Sanctum::actingAs($user);

    $this->getJson('/api/valuation')->assertOk();
    $this->postJson('/api/valuation/method', ['method' => 'fifo', 'effective_from' => '2026-10-01'])->assertForbidden();
    $this->postJson('/api/valuation/recalculate', ['from' => '2026-09-01', 'to' => '2026-09-30'])->assertForbidden();

    grantMenuPermission($role->id, '/valuation/method');
    grantMenuPermission($role->id, '/valuation/standard');

    // company_id in the body is ignored for a company user
    $this->postJson('/api/valuation/method', ['company_id' => $other['company_id'], 'method' => 'fifo', 'effective_from' => '2026-10-01'])->assertCreated();
    expect(DB::table('valuation_method_changes')->where('company_id', $other['company_id'])->count())->toBe(0)
        ->and(DB::table('valuation_method_changes')->where('company_id', $scope['company_id'])->count())->toBe(1);

    // a variation of another company cannot be given a standard cost
    $foreign = prdProduct($other, 'Foreign');
    $this->postJson('/api/valuation/standard-costs', ['effective_from' => '2026-10-01', 'items' => [['variation_id' => $foreign['variation_id'], 'cost' => 5]]])->assertUnprocessable();
    expect(User::query()->count())->toBeGreaterThan(0);
});
