<?php

use App\Models\Product;
use App\Models\ProductDetail;
use App\Models\PurchaseLine;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Creates a product, optionally stocked by a received purchase in the given branch.
 *
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $attributes
 */
function lowStockProduct(array $scope, array $attributes = [], float $received = 0, ?int $branchId = null, ?int $unitId = null): Product
{
    static $counter = 0;
    $counter++;

    $product = Product::query()->create(array_merge([
        'company_id' => $scope['company_id'],
        'unit_id' => $scope['unit_id'],
        'itemtype_id' => $scope['itemtype_id'],
        'category_id' => $scope['category_id'],
        'name' => "Low Stock Item {$counter}",
        'sku' => "LS-{$counter}",
        'type' => 'single',
        'active' => true,
        'alert_qty' => 10,
    ], $attributes));

    $detail = ProductDetail::query()->create([
        'product_id' => $product->id,
        'name' => $product->name.' dummy',
        'sku' => $product->sku.'-1',
        'variation_name' => 'dummy',
        'default_purchase_price' => 10,
        'dpp_unit_price' => 10,
        'largequantity' => 1,
        'smallquantity' => 1,
        'profit_percent' => 10,
        'default_sell_price' => 11,
    ]);

    if ($received > 0) {
        lowStockReceive($scope, $product, $detail, $received, $branchId ?? $scope['branch_id'], $unitId);
    }

    return $product;
}

/**
 * @param  array<string, mixed>  $scope
 */
function lowStockReceive(array $scope, Product $product, ProductDetail $detail, float $quantity, int $branchId, ?int $unitId = null, int $packing = 1): Transaction
{
    static $counter = 0;
    $counter++;

    $purchase = Transaction::query()->create([
        'company_id' => $scope['company_id'],
        'branch_id' => $branchId,
        'contact_id' => $scope['contact_id'],
        'invoice_no' => "PO-LS-{$counter}",
        'type' => Transaction::TYPE_PURCHASE,
        'status' => 'received',
        'payment_status' => 'due',
        'transaction_date' => now(),
        'final_amount' => 100,
        'total_item' => 1,
    ]);

    PurchaseLine::query()->create([
        'transaction_id' => $purchase->id,
        'product_id' => $product->id,
        'variation_id' => $detail->id,
        'itemtype_id' => $scope['itemtype_id'],
        'unit_id' => $unitId ?? $scope['unit_id'],
        'quantity' => $quantity,
        'quantity_received' => $quantity,
        'purchase_rate' => 10,
        'pp_without_discount' => 10,
        'default_sell_price' => 11,
        'packing_qty' => $packing,
    ]);

    return $purchase;
}

/**
 * @return list<array<string, mixed>>
 */
function lowStockRows(string $query = ''): array
{
    $response = test()->getJson('/api/lowstock'.($query !== '' ? '?'.$query : ''))->assertSuccessful();

    return $response->json('data.data');
}

beforeEach(function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
});

test('a product at or below its alert quantity is listed with stock and shortage', function () {
    $scope = seedStockTransferScope();
    $product = Product::query()->findOrFail($scope['product_id']);

    $product->update(['alert_qty' => 15]);
    $rows = lowStockRows();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['product_id'])->toBe($product->id)
        ->and($rows[0]['name'])->toBe($product->name)
        ->and($rows[0]['branch_id'])->toBe($scope['branch_id'])
        ->and($rows[0]['branch_name'])->toBe('Purchase Branch')
        ->and($rows[0]['unit_name'])->toBe('KG')
        ->and((float) $rows[0]['stock'])->toBe(10.0)
        ->and((float) $rows[0]['alert_qty'])->toBe(15.0)
        ->and((float) $rows[0]['shortage'])->toBe(5.0);

    $product->update(['alert_qty' => 10]);
    expect(lowStockRows())->toHaveCount(1);

    $product->update(['alert_qty' => 9]);
    expect(lowStockRows())->toBe([]);
});

test('products without an alert quantity or that are inactive are never listed', function () {
    $scope = seedStockTransferScope();
    $product = Product::query()->findOrFail($scope['product_id']);

    $product->update(['alert_qty' => null]);
    expect(lowStockRows())->toBe([]);

    $product->update(['alert_qty' => 50, 'active' => false]);
    expect(lowStockRows())->toBe([]);

    $product->update(['active' => true]);
    $product->delete();
    expect(lowStockRows())->toBe([]);
});

test('a zero alert quantity flags a product that has run out', function () {
    $scope = seedStockTransferScope();
    $out = lowStockProduct($scope, ['alert_qty' => 0], 3);

    $return = PurchaseLine::query()->where('product_id', $out->id)->firstOrFail();
    $return->update(['quantity_returned' => 3]);

    Product::query()->findOrFail($scope['product_id'])->update(['alert_qty' => null]);

    $rows = lowStockRows();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['product_id'])->toBe($out->id)
        ->and((float) $rows[0]['stock'])->toBe(0.0);
});

test('branch stock matches PurchaseLine currentStock after a completed transfer and adjustment', function () {
    $scope = seedStockTransferScope();
    Product::query()->findOrFail($scope['product_id'])->update(['alert_qty' => 100]);

    createStockTransferRecord($scope, ['status' => 'completed']);
    createStockAdjustmentRecord($scope, ['invoice_no' => 'SA-LS-1']);

    $rows = collect(lowStockRows())->keyBy('branch_id');

    foreach ([$scope['branch_id'], $scope['tobranch_id']] as $branchId) {
        $expected = PurchaseLine::currentStock($scope['product_id'], $scope['variation_id'], $scope['unit_id'], $branchId);

        expect($rows->has($branchId))->toBeTrue()
            ->and((float) $rows[$branchId]['stock'])->toBe($expected);
    }

    // 10 received, 2 out by transfer, 2 adjusted; destination received 2.
    expect((float) $rows[$scope['branch_id']]['stock'])->toBe(10.0 - 2.0 + 2.0)
        ->and((float) $rows[$scope['tobranch_id']]['stock'])->toBe(2.0);
});

test('pending transfers do not move stock and never stocked branches are not listed', function () {
    $scope = seedStockTransferScope();
    Product::query()->findOrFail($scope['product_id'])->update(['alert_qty' => 100]);

    createStockTransferRecord($scope, ['status' => 'pending']);

    $rows = lowStockRows();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['branch_id'])->toBe($scope['branch_id'])
        ->and((float) $rows[0]['stock'])->toBe(10.0);
});

test('stock is summed across the variations of a product', function () {
    $scope = seedStockTransferScope();
    $product = Product::query()->findOrFail($scope['product_id']);
    $product->update(['alert_qty' => 100]);

    $second = ProductDetail::query()->create([
        'product_id' => $product->id,
        'name' => $product->name.' large',
        'sku' => $product->sku.'-2',
        'variation_name' => 'large',
        'default_purchase_price' => 10,
        'dpp_unit_price' => 10,
        'largequantity' => 1,
        'smallquantity' => 1,
        'profit_percent' => 10,
        'default_sell_price' => 11,
    ]);
    lowStockReceive($scope, $product, $second, 5, $scope['branch_id']);

    $rows = lowStockRows();
    $expected = PurchaseLine::currentStock($product->id, $scope['variation_id'], $scope['unit_id'], $scope['branch_id'])
        + PurchaseLine::currentStock($product->id, $second->id, $scope['unit_id'], $scope['branch_id']);

    expect($rows)->toHaveCount(1)
        ->and((float) $rows[0]['stock'])->toBe(15.0)
        ->and((float) $rows[0]['stock'])->toBe($expected);
});

test('purchases in a larger unit count in base units and deleted purchases are not counted', function () {
    $scope = seedStockTransferScope();
    $product = Product::query()->findOrFail($scope['product_id']);
    $product->update(['alert_qty' => 100]);

    $carton = Unit::query()->create([
        'company_id' => $scope['company_id'],
        'parent_id' => $scope['unit_id'],
        'name' => 'Carton',
        'short_name' => 'CTN',
        'type' => 'large',
        'active' => true,
        'auto_adjustment' => false,
    ]);
    $detail = ProductDetail::query()->findOrFail($scope['variation_id']);
    // 4 cartons of 10 base units each.
    lowStockReceive($scope, $product, $detail, 4, $scope['branch_id'], $carton->id, 10);

    expect((float) lowStockRows()[0]['stock'])->toBe(50.0);

    Transaction::query()->where('invoice_no', 'PO-STOCK-0001')->firstOrFail()->delete();

    expect((float) lowStockRows()[0]['stock'])->toBe(40.0);

    Transaction::query()->where('invoice_no', 'like', 'PO-LS-%')->get()->each->delete();

    expect(lowStockRows())->toBe([]);
});

test('company branch search and category filters narrow the list', function () {
    $scope = seedStockTransferScope();
    Product::query()->findOrFail($scope['product_id'])->update(['alert_qty' => 100]);

    $otherCompanyId = DB::table('companies')->insertGetId([
        'code' => 'LSO001', 'name' => 'Other Company', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $otherBranchId = DB::table('branches')->insertGetId([
        'code' => 'LSOB01', 'company_id' => $otherCompanyId, 'name' => 'Other Branch', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $otherUnit = Unit::query()->create([
        'company_id' => $otherCompanyId, 'name' => 'Piece', 'short_name' => 'PC', 'type' => 'large', 'active' => true, 'auto_adjustment' => false,
    ]);
    $otherScope = array_merge($scope, ['company_id' => $otherCompanyId, 'unit_id' => $otherUnit->id]);
    $other = lowStockProduct($otherScope, ['name' => 'Zebra Widget', 'sku' => 'ZW-1', 'category_id' => null], 4, $otherBranchId);

    expect(lowStockRows())->toHaveCount(2)
        ->and(lowStockRows('company_id='.$otherCompanyId))->toHaveCount(1)
        ->and(lowStockRows('branch_id='.$otherBranchId)[0]['product_id'])->toBe($other->id)
        ->and(lowStockRows('search=Zebra'))->toHaveCount(1)
        ->and(lowStockRows('search=ZW-1'))->toHaveCount(1)
        ->and(lowStockRows('search=nothing-matches'))->toBe([])
        ->and(lowStockRows('category_id='.$scope['category_id']))->toHaveCount(1);
});

test('the default order lists the biggest shortage first and only known sort columns are used', function () {
    $scope = seedStockTransferScope();
    Product::query()->findOrFail($scope['product_id'])->update(['alert_qty' => 12]);
    $bigger = lowStockProduct($scope, ['alert_qty' => 50], 5);

    $rows = lowStockRows();

    expect(collect($rows)->pluck('product_id')->all())->toBe([$bigger->id, $scope['product_id']]);

    $byStock = lowStockRows('sort_by=stock&sort_type=asc');
    expect(collect($byStock)->pluck('product_id')->all())->toBe([$bigger->id, $scope['product_id']]);

    $hostile = lowStockRows('sort_by='.urlencode('p.name; DROP TABLE products'));
    expect(collect($hostile)->pluck('product_id')->all())->toBe([$bigger->id, $scope['product_id']])
        ->and(Product::query()->count())->toBe(2);
});

test('the response is a paginator that the shared table understands', function () {
    $scope = seedStockTransferScope();
    Product::query()->findOrFail($scope['product_id'])->update(['alert_qty' => 100]);
    lowStockProduct($scope, ['alert_qty' => 100], 1);
    lowStockProduct($scope, ['alert_qty' => 100], 2);

    $response = $this->getJson('/api/lowstock?show_record=2&cur_page=2')->assertSuccessful();

    expect($response->json('data.total'))->toBe(3)
        ->and($response->json('data.per_page'))->toBe(2)
        ->and($response->json('data.current_page'))->toBe(2)
        ->and($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('trash_count'))->toBe(0);

    $beyond = $this->getJson('/api/lowstock?show_record=2&cur_page=9')->assertSuccessful();
    expect($beyond->json('data.current_page'))->toBe(2);
});

test('a staff role needs the lowstock permission and is limited to its own company and branch', function () {
    $scope = seedStockTransferScope();
    Product::query()->findOrFail($scope['product_id'])->update(['alert_qty' => 100]);
    createStockTransferRecord($scope, ['status' => 'completed']);

    $role = Role::query()->create(['name' => 'storekeeper', 'company_id' => $scope['company_id'], 'is_active' => true]);
    $staff = createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']]);
    Sanctum::actingAs($staff);

    $this->getJson('/api/lowstock')->assertForbidden();

    grantMenuPermission($role->id, '/lowstock');

    $rows = lowStockRows('branch_id='.$scope['tobranch_id']);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['branch_id'])->toBe($scope['branch_id']);
});

test('a company admin can pick any branch of its own company but not another company', function () {
    $scope = seedStockTransferScope();
    Product::query()->findOrFail($scope['product_id'])->update(['alert_qty' => 100]);
    createStockTransferRecord($scope, ['status' => 'completed']);

    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => $scope['company_id'], 'is_active' => true]);
    $admin = createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']]);
    Sanctum::actingAs($admin);
    grantMenuPermission($role->id, '/lowstock');

    expect(lowStockRows())->toHaveCount(2)
        ->and(lowStockRows('branch_id='.$scope['tobranch_id']))->toHaveCount(1)
        ->and(lowStockRows('company_id=999999'))->toHaveCount(2);
});
