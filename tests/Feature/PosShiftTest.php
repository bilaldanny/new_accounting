<?php

use App\Models\PosShift;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
});

/**
 * @param  array<string, mixed>  $scope
 */
function posSale(array $scope, float $amount, array $payments = [], array $attributes = []): Transaction
{
    $sale = Transaction::query()->create(array_merge([
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'contact_id' => $scope['contact_id'],
        'invoice_no' => 'POS-'.fake()->unique()->numerify('#####'), 'type' => 'sell', 'status' => 'final', 'payment_status' => 'due',
        'transaction_date' => now(), 'final_amount' => $amount, 'total_item' => 1, 'created_by' => 1,
    ], $attributes));

    foreach ($payments as [$method, $paid, $isReturn]) {
        DB::table('payments')->insert([
            'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'transaction_id' => $sale->id,
            'contact_id' => $scope['contact_id'], 'amount' => $paid, 'method' => $method, 'is_return' => $isReturn,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $sale;
}

/**
 * @param  array<string, mixed>  $scope
 */
function openPosShift(array $scope, float $float = 500): int
{
    return test()->postJson('/api/pos-shifts/open', ['branch_id' => $scope['branch_id'], 'company_id' => $scope['company_id'], 'opening_float' => $float])
        ->assertSuccessful()
        ->json('data.id');
}

test('a shift opens with a float and the cashier sees it as current', function () {
    $scope = seedSellScope();

    $id = openPosShift($scope);

    $this->getJson('/api/pos-shifts/current')->assertSuccessful()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.report_type', 'X')
        ->assertJsonPath('data.opening_float', 500)
        ->assertJsonPath('data.report.expected_cash', 500);
});

test('there is one open shift per cashier and per branch drawer', function () {
    $scope = seedSellScope();
    openPosShift($scope);

    $this->postJson('/api/pos-shifts/open', ['branch_id' => $scope['branch_id'], 'company_id' => $scope['company_id'], 'opening_float' => 0])
        ->assertUnprocessable()->assertJsonValidationErrors(['shift']);

    $role = Role::query()->create(['name' => 'cashier', 'company_id' => $scope['company_id'], 'is_active' => true]);
    Sanctum::actingAs(createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']]));

    $this->postJson('/api/pos-shifts/open', ['opening_float' => 0])->assertForbidden();
});

test('the X report counts the cashiers cash sales, cash in and out, and leaves other money out', function () {
    $scope = seedSellScope();
    $id = openPosShift($scope, 500);
    $otherCashier = createStaffUserForRole(Role::query()->create(['name' => 'cashier2', 'company_id' => $scope['company_id'], 'is_active' => true]), ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']]);

    posSale($scope, 300, [['cash', 300, 0]]);
    posSale($scope, 200, [['cash', 50, 0], ['card', 150, 0]]);
    posSale($scope, 100, [['cash', 20, 1]]);
    posSale($scope, 900, [['cash', 900, 0]], ['created_by' => $otherCashier->id]);
    posSale($scope, 700, [['cash', 700, 0]], ['status' => 'draft']);

    $this->postJson("/api/pos-shifts/{$id}/movement", ['type' => 'in', 'amount' => 40, 'reason' => 'Change top-up'])->assertSuccessful();
    $this->postJson("/api/pos-shifts/{$id}/movement", ['type' => 'out', 'amount' => 15, 'reason' => 'Petty cash'])->assertSuccessful();

    $report = $this->getJson("/api/pos-shifts/{$id}")->assertSuccessful()->json('data.report');

    expect($report['sales_count'])->toBe(3)
        ->and($report['sales_total'])->toEqual(600)
        ->and($report['payments']['cash'])->toEqual(330)
        ->and($report['payments']['card'])->toEqual(150)
        ->and($report['pay_in'])->toEqual(40)
        ->and($report['pay_out'])->toEqual(15)
        ->and($report['expected_cash'])->toEqual(855);
});

test('closing records the counted cash, the variance and a frozen Z report', function () {
    $scope = seedSellScope();
    $id = openPosShift($scope, 500);
    posSale($scope, 300, [['cash', 300, 0]]);

    $response = $this->postJson("/api/pos-shifts/{$id}/close", ['counted_cash' => 790, 'note' => 'Short by 10'])->assertSuccessful();

    expect($response->json('data.status'))->toBe('closed')
        ->and($response->json('data.report_type'))->toBe('Z')
        ->and($response->json('data.expected_cash'))->toEqual(800)
        ->and($response->json('data.counted_cash'))->toEqual(790)
        ->and($response->json('data.variance'))->toEqual(-10);

    posSale($scope, 999, [['cash', 999, 0]]);

    expect($this->getJson("/api/pos-shifts/{$id}")->json('data.report.expected_cash'))->toEqual(800);

    $this->postJson("/api/pos-shifts/{$id}/close", ['counted_cash' => 1])->assertUnprocessable();
    $this->postJson("/api/pos-shifts/{$id}/movement", ['type' => 'in', 'amount' => 5, 'reason' => 'late'])->assertUnprocessable();
    expect($this->getJson('/api/pos-shifts/current')->json('data'))->toBeNull();
});

test('a closed shift frees the drawer and the history lists it newest first', function () {
    $scope = seedSellScope();
    $first = openPosShift($scope);
    $this->postJson("/api/pos-shifts/{$first}/close", ['counted_cash' => 500])->assertSuccessful();
    $second = openPosShift($scope, 100);

    $rows = $this->getJson('/api/pos-shifts')->assertSuccessful()->json('data.data');

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['id'])->toBe($second)
        ->and($rows[1]['status'])->toBe('closed')
        ->and(PosShift::query()->where('status', 'open')->count())->toBe(1);
});

test('input is validated and a user without the permission is turned away', function () {
    $scope = seedSellScope();

    $this->postJson('/api/pos-shifts/open', ['branch_id' => $scope['branch_id'], 'opening_float' => -5])->assertUnprocessable();

    $id = openPosShift($scope);
    $this->postJson("/api/pos-shifts/{$id}/movement", ['type' => 'sideways', 'amount' => 0, 'reason' => ''])->assertUnprocessable();

    $role = Role::query()->create(['name' => 'accountant', 'company_id' => $scope['company_id'], 'is_active' => true]);
    Sanctum::actingAs(createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']]));

    $this->getJson('/api/pos-shifts')->assertForbidden();
    $this->postJson("/api/pos-shifts/{$id}/close", ['counted_cash' => 1])->assertForbidden();
});

test('the menu migration adds the page and its permission rows next to Sell and grants them to companyadmin', function () {
    DB::table('permissions')->delete();
    DB::table('menus')->delete();
    $group = DB::table('menus')->insertGetId([
        'parent_id' => null, 'name' => 'Sell', 'icon' => '', 'route_name' => 'sellgroup', 'route_path' => '', 'menu_color' => '#000', 'sort_order' => 1,
        'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 0, 'type' => 2, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('menus')->insert([
        'parent_id' => $group, 'name' => 'Sell', 'icon' => '', 'route_name' => 'sell', 'route_path' => '/sell', 'menu_color' => '#000', 'sort_order' => 1,
        'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 0, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $roleId = DB::table('roles')->insertGetId(['name' => 'companyadmin', 'company_id' => null, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    (require database_path('migrations/2026_10_07_100100_add_pos_shift_menu.php'))->up();

    $paths = DB::table('menus')->where('route_path', 'like', '/posshift%')->orderBy('route_path')->pluck('route_path')->all();
    $page = DB::table('menus')->where('route_path', '/posshift')->first();

    expect($paths)->toBe(['/posshift', '/posshift/close', '/posshift/movement', '/posshift/open'])
        ->and((int) $page->parent_id)->toBe($group)
        ->and(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(4);
});
