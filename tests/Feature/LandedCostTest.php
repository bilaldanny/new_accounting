<?php

use App\Models\PurchaseLine;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Reports\AverageCost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
});

/**
 * A purchase with one line per [quantity, rate].
 *
 * @param  array<string, mixed>  $scope
 * @param  list<array{0: float|int, 1: float|int}>  $lines
 * @return array{0: Transaction, 1: list<PurchaseLine>}
 */
function lcPurchase(array $scope, array $lines, array $attributes = []): array
{
    $purchase = createPurchaseRecord($scope, array_merge(['invoice_no' => 'LC-'.fake()->unique()->numerify('####'), 'status' => 'approved'], $attributes));
    $purchase->purchaselines()->delete();

    $created = array_map(fn (array $line): PurchaseLine => PurchaseLine::query()->create([
        'transaction_id' => $purchase->id,
        'product_id' => $scope['product_id'],
        'variation_id' => $scope['variation_id'],
        'itemtype_id' => $scope['itemtype_id'],
        'unit_id' => $scope['unit_id'],
        'quantity' => $line[0],
        'purchase_rate' => $line[1],
        'packing_qty' => 1,
    ]), $lines);

    return [$purchase, $created];
}

/**
 * @param  list<array<string, mixed>>  $costs
 */
function lcSave(int $purchaseId, array $costs)
{
    return test()->putJson("/api/landed-costs/{$purchaseId}", ['costs' => $costs]);
}

test('costs are spread over the lines by value', function () {
    $scope = seedPurchaseScope();
    [$purchase, $lines] = lcPurchase($scope, [[1, 100], [3, 100]]);

    $response = lcSave($purchase->id, [['type' => 'freight', 'amount' => 40, 'allocation_basis' => 'value']])->assertSuccessful();

    expect(collect($response->json('data.lines'))->pluck('landed_cost')->all())->toEqual([10, 30])
        ->and($response->json('data.total_costs'))->toEqual(40)
        ->and($response->json('data.lines.0.landed_unit_cost'))->toEqual(110)
        ->and((float) $lines[1]->fresh()->landed_cost)->toBe(30.0);
});

test('costs can be spread by quantity, and both bases add up', function () {
    $scope = seedPurchaseScope();
    [$purchase] = lcPurchase($scope, [[1, 300], [3, 100]]);

    $response = lcSave($purchase->id, [
        ['type' => 'freight', 'amount' => 40, 'allocation_basis' => 'quantity'],
        ['type' => 'customs_duty', 'amount' => 60, 'allocation_basis' => 'value'],
    ])->assertSuccessful();

    // quantity: 10 and 30; value (300 and 300): 30 and 30
    expect(collect($response->json('data.lines'))->pluck('landed_cost')->all())->toEqual([40, 60]);
});

test('rounding never loses a cent: the last line takes the remainder', function () {
    $scope = seedPurchaseScope();
    [$purchase] = lcPurchase($scope, [[1, 100], [1, 100], [1, 100]]);

    $lines = collect(lcSave($purchase->id, [['type' => 'insurance', 'amount' => 10]])->assertSuccessful()->json('data.lines'));

    expect($lines->pluck('landed_cost')->all())->toEqual([3.33, 3.33, 3.34])
        ->and(round($lines->sum('landed_cost'), 2))->toEqual(10.0);
});

test('saving an empty list removes the costs and their shares', function () {
    $scope = seedPurchaseScope();
    [$purchase, $lines] = lcPurchase($scope, [[2, 50]]);
    lcSave($purchase->id, [['type' => 'freight', 'amount' => 25]])->assertSuccessful();

    expect((float) $lines[0]->fresh()->landed_cost)->toBe(25.0);

    lcSave($purchase->id, [])->assertSuccessful();

    expect((float) $lines[0]->fresh()->landed_cost)->toBe(0.0)
        ->and(DB::table('purchase_landed_costs')->where('transaction_id', $purchase->id)->count())->toBe(0);
});

test('the average cost includes the landed cost and takes back what was returned', function () {
    $scope = seedPurchaseScope();
    [$purchase, $lines] = lcPurchase($scope, [[10, 100]]);

    expect(AverageCost::byVariation($scope['company_id'])[$scope['variation_id']])->toEqual(100.0);

    lcSave($purchase->id, [['type' => 'freight', 'amount' => 200]])->assertSuccessful();

    expect(AverageCost::byVariation($scope['company_id'])[$scope['variation_id']])->toEqual(120.0);

    $lines[0]->update(['quantity_returned' => 5]);

    expect(AverageCost::byVariation($scope['company_id'])[$scope['variation_id']])->toEqual(120.0);
});

test('only non-draft purchase orders take landed costs and input is validated', function () {
    $scope = seedPurchaseScope();
    [$draft] = lcPurchase($scope, [[1, 10]], ['status' => 'draft']);
    [$purchase] = lcPurchase($scope, [[1, 10]]);

    lcSave($draft->id, [['type' => 'freight', 'amount' => 5]])->assertUnprocessable()->assertJsonValidationErrors(['transaction_id']);
    lcSave($purchase->id, [['type' => 'bribe', 'amount' => 5]])->assertUnprocessable()->assertJsonValidationErrors(['costs.0.type']);
    lcSave($purchase->id, [['type' => 'freight', 'amount' => 0]])->assertUnprocessable()->assertJsonValidationErrors(['costs.0.amount']);
    $this->putJson("/api/landed-costs/{$purchase->id}", [])->assertUnprocessable();
    lcSave(999999, [])->assertNotFound();
});

test('a line-less purchase cannot be spread over', function () {
    $scope = seedPurchaseScope();
    [$purchase] = lcPurchase($scope, []);

    lcSave($purchase->id, [['type' => 'freight', 'amount' => 5]])->assertUnprocessable();
    expect(DB::table('purchase_landed_costs')->count())->toBe(0);
});

test('editing the purchase spreads the costs again over the new lines', function () {
    $scope = seedPurchaseScope();
    $id = $this->postJson('/api/purchases', validPurchasePayload($scope))->assertSuccessful()->json('id') ?? Transaction::query()->purchases()->latest('id')->value('id');
    lcSave((int) $id, [['type' => 'freight', 'amount' => 50]])->assertSuccessful();

    $before = (float) PurchaseLine::query()->where('transaction_id', $id)->sum('landed_cost');

    $payload = validPurchasePayload($scope);
    $this->putJson("/api/purchases/{$id}", $payload)->assertSuccessful();

    expect($before)->toBe(50.0)
        ->and((float) PurchaseLine::query()->where('transaction_id', $id)->sum('landed_cost'))->toBe(50.0);
});

test('the list shows each purchases landed total and a user needs the permission', function () {
    $scope = seedPurchaseScope();
    [$purchase] = lcPurchase($scope, [[1, 10]]);
    lcSave($purchase->id, [['type' => 'freight', 'amount' => 7.5]])->assertSuccessful();

    $row = $this->getJson('/api/landed-costs?search='.$purchase->invoice_no)->assertSuccessful()->json('data.data.0');

    expect($row['invoice_no'])->toBe($purchase->invoice_no)->and($row['landed_total'])->toEqual(7.5);

    $role = Role::query()->create(['name' => 'buyer', 'company_id' => $scope['company_id'], 'is_active' => true]);
    Sanctum::actingAs(createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']]));

    $this->getJson('/api/landed-costs')->assertForbidden();
    lcSave($purchase->id, [])->assertForbidden();
});

test('the menu migration adds the page next to purchase and grants companyadmin', function () {
    DB::table('permissions')->delete();
    DB::table('menus')->delete();
    $group = DB::table('menus')->insertGetId([
        'parent_id' => null, 'name' => 'Purchase', 'icon' => '', 'route_name' => 'pgroup', 'route_path' => '', 'menu_color' => '#000', 'sort_order' => 1,
        'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 0, 'type' => 2, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('menus')->insert([
        'parent_id' => $group, 'name' => 'Purchase', 'icon' => '', 'route_name' => 'purchase', 'route_path' => '/purchase', 'menu_color' => '#000', 'sort_order' => 1,
        'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 0, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $roleId = DB::table('roles')->insertGetId(['name' => 'companyadmin', 'company_id' => null, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    (require database_path('migrations/2026_10_08_100100_add_landed_cost_menu.php'))->up();

    expect(DB::table('menus')->where('route_path', 'like', '/landedcost%')->count())->toBe(2)
        ->and((int) DB::table('menus')->where('route_path', '/landedcost')->value('parent_id'))->toBe($group)
        ->and(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(2);
});
