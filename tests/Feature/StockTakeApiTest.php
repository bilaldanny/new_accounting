<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductDetail;
use App\Models\PurchaseLine;
use App\Models\Role;
use App\Models\StockTake;
use App\Models\StockTakeLine;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StockMovements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * A company with a branch and one product holding 10 KG of stock.
 *
 * @return array<string, mixed>
 */
function tktScope(): array
{
    return seedStockAdjustmentScope();
}

/**
 * A second company with a branch and one product, to prove nothing leaks across companies.
 *
 * @return array{company_id: int, branch_id: int, variation_id: int}
 */
function tktForeign(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'TKTF01', 'name' => 'Foreign Company', 'address' => '9 Other Road', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $branchId = trpBranch($companyId, 'Foreign Branch');
    $unitId = (int) DB::table('units')->value('id');

    $product = Product::query()->create([
        'company_id' => $companyId, 'unit_id' => $unitId, 'name' => 'Foreign Product', 'sku' => 'FOR-1', 'type' => 'single', 'active' => true,
    ]);
    $detail = ProductDetail::query()->create([
        'product_id' => $product->id, 'name' => 'Foreign Product', 'sku' => 'FOR-1-1', 'variation_name' => 'dummy',
        'default_purchase_price' => 1, 'dpp_unit_price' => 1, 'largequantity' => 1, 'smallquantity' => 1, 'profit_percent' => 1, 'default_sell_price' => 2,
    ]);

    return ['company_id' => $companyId, 'branch_id' => $branchId, 'variation_id' => $detail->id];
}

/**
 * Another product of the company (a variation of it), optionally with received stock in the scope's branch.
 *
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $product
 * @return array{product_id: int, variation_id: int, unit_id: int}
 */
function tktProduct(array $scope, string $name, float $stock = 0, array $product = []): array
{
    $created = Product::query()->create(array_merge([
        'company_id' => $scope['company_id'],
        'unit_id' => $scope['unit_id'],
        'itemtype_id' => $scope['itemtype_id'],
        'category_id' => $scope['category_id'],
        'name' => $name,
        'sku' => 'SKU-'.strtoupper(substr(md5($name), 0, 6)),
        'type' => 'single',
        'active' => true,
    ], $product));

    $detail = ProductDetail::query()->create([
        'product_id' => $created->id,
        'name' => $name,
        'sku' => $created->sku.'-1',
        'variation_name' => 'dummy',
        'default_purchase_price' => 10,
        'dpp_unit_price' => 10,
        'largequantity' => 1,
        'smallquantity' => 1,
        'profit_percent' => 10,
        'default_sell_price' => 11,
    ]);

    if ($stock > 0) {
        $purchase = Transaction::query()->create([
            'company_id' => $scope['company_id'],
            'branch_id' => $scope['branch_id'],
            'contact_id' => $scope['contact_id'],
            'invoice_no' => 'PO-'.$created->id,
            'type' => Transaction::TYPE_PURCHASE,
            'status' => 'received',
            'payment_status' => 'due',
            'transaction_date' => now(),
            'final_amount' => 10 * $stock,
            'total_item' => 1,
        ]);

        PurchaseLine::query()->create([
            'transaction_id' => $purchase->id,
            'product_id' => $created->id,
            'variation_id' => $detail->id,
            'itemtype_id' => $scope['itemtype_id'],
            'unit_id' => $scope['unit_id'],
            'quantity' => $stock,
            'quantity_received' => $stock,
            'purchase_rate' => 10,
            'pp_without_discount' => 10,
            'default_sell_price' => 11,
            'packing_qty' => 1,
        ]);
    }

    return ['product_id' => $created->id, 'variation_id' => $detail->id, 'unit_id' => $scope['unit_id']];
}

/**
 * @param  array<string, mixed>  $scope
 * @param  list<string>  $paths  the menu permissions the user's role is given
 */
function tktStaff(array $scope, array $paths = [], string $roleName = 'companyadmin', ?int $branchId = null): User
{
    $role = Role::query()->create(['name' => $roleName, 'company_id' => $scope['company_id'], 'is_active' => true]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path);
    }

    return createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $branchId]);
}

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tktPayload(array $scope, array $overrides = []): array
{
    return array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'count_date' => '2026-09-20',
        'note' => 'Monthly count',
    ], $overrides);
}

/**
 * Opens a sheet through the API as the superadmin and returns it.
 *
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $overrides
 */
function tktOpen(array $scope, array $overrides = []): StockTake
{
    Sanctum::actingAs(User::query()->findOrFail(1));

    test()->postJson('/api/stock-takes', tktPayload($scope, $overrides))->assertSuccessful();

    return StockTake::query()->latest('id')->firstOrFail();
}

function tktLine(StockTake $take, int $variationId): StockTakeLine
{
    return StockTakeLine::query()->where('stock_take_id', $take->id)->where('variation_id', $variationId)->firstOrFail();
}

/**
 * @param  array<int|string, float|int|null>  $counts  keyed by variation id
 */
function tktCount(StockTake $take, array $counts): void
{
    $payload = [];

    foreach ($counts as $variationId => $quantity) {
        $payload[tktLine($take, (int) $variationId)->id] = $quantity;
    }

    test()->putJson('/api/stock-takes/'.$take->id.'/counts', ['counts' => $payload])->assertSuccessful();
}

// --- opening a sheet ---------------------------------------------------------------------------

test('opening a stock take freezes the system stock of the branch into a draft sheet', function () {
    $scope = tktScope();
    $second = tktProduct($scope, 'Basmati Broken', 4.5);
    tktProduct($scope, 'Out Of Stock Item');
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/stock-takes', tktPayload($scope))
        ->assertSuccessful()
        ->assertJson(['reference' => 'ST-00001']);

    $take = StockTake::query()->firstOrFail();

    expect($take->status)->toBe('draft')
        ->and($take->company_id)->toBe($scope['company_id'])
        ->and($take->branch_id)->toBe($scope['branch_id'])
        ->and($take->count_date->toDateString())->toBe('2026-09-20')
        ->and($take->note)->toBe('Monthly count')
        ->and($take->adjustment_id)->toBeNull()
        ->and($take->lines)->toHaveCount(3)
        ->and(tktLine($take, $scope['variation_id'])->system_qty)->toBe(10.0)
        ->and(tktLine($take, $second['variation_id'])->system_qty)->toBe(4.5)
        ->and($take->lines->pluck('counted_qty')->filter()->all())->toBe([])
        ->and(tktLine($take, $scope['variation_id'])->unit_id)->toBe($scope['unit_id']);
});

test('the frozen quantity is the stock of that branch only, sales included', function () {
    $scope = tktScope();
    $otherBranch = trpBranch($scope['company_id'], 'Second Branch');
    $sale = trpDoc(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'customer_id' => $scope['contact_id'], 'supplier_id' => $scope['contact_id']], 'sell', ['status' => 'final']);
    prdSellLine($sale, $scope, 3, 5);

    $take = tktOpen($scope);

    expect(tktLine($take, $scope['variation_id'])->system_qty)->toBe(7.0)
        ->and(StockMovements::baseStock($scope['product_id'], $scope['variation_id'], $scope['branch_id']))->toBe(7.0);

    $emptyBranch = tktOpen($scope, ['branch_id' => $otherBranch]);

    expect(tktLine($emptyBranch, $scope['variation_id'])->system_qty)->toBe(0.0);
});

test('only active products of the company are on the sheet', function () {
    $scope = tktScope();
    $inactive = tktProduct($scope, 'Retired Item', 5, ['active' => false]);
    $foreignScope = tktForeign();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $take = tktOpen($scope);

    expect($take->lines->pluck('variation_id')->all())->toBe([$scope['variation_id']])
        ->and($take->lines->pluck('variation_id')->all())->not->toContain($inactive['variation_id'])
        ->and($take->lines->pluck('variation_id')->all())->not->toContain($foreignScope['variation_id']);
});

test('the sheet can be limited by category, item type or to products that have stock', function () {
    $scope = tktScope();
    $otherCategory = Category::query()->create(['company_id' => $scope['company_id'], 'name' => 'Beverages', 'active' => true]);
    $drink = tktProduct($scope, 'Green Tea', 2, ['category_id' => $otherCategory->id]);
    $empty = tktProduct($scope, 'Empty Shelf Item');

    $byCategory = tktOpen($scope, ['category_id' => $otherCategory->id]);
    $inStock = tktOpen($scope, ['only_in_stock' => true]);
    $everything = tktOpen($scope);

    expect($byCategory->lines->pluck('variation_id')->all())->toBe([$drink['variation_id']])
        ->and($inStock->lines->pluck('variation_id')->sort()->values()->all())->toBe(collect([$scope['variation_id'], $drink['variation_id']])->sort()->values()->all())
        ->and($everything->lines->pluck('variation_id')->all())->toContain($empty['variation_id'])
        ->and($everything->lines)->toHaveCount(3);

    $byItemType = tktOpen($scope, ['itemtype_id' => $scope['itemtype_id']]);

    expect($byItemType->lines)->toHaveCount(3);
});

test('a sheet with no matching product is refused', function () {
    $scope = tktScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/stock-takes', tktPayload($scope, ['category_id' => 999999]))->assertUnprocessable()->assertJsonValidationErrors(['filters']);
    $this->postJson('/api/stock-takes', tktPayload($scope, ['brand_id' => 999999]))->assertUnprocessable()->assertJsonValidationErrors(['filters']);

    expect(StockTake::query()->count())->toBe(0);
});

test('opening validates its input', function (array $overrides, string $field) {
    $scope = tktScope();
    $foreign = tktForeign();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $overrides = array_map(fn ($value) => $value === '@foreign-branch' ? $foreign['branch_id'] : $value, $overrides);

    $this->postJson('/api/stock-takes', tktPayload($scope, $overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(StockTake::query()->count())->toBe(0);
})->with([
    'no branch' => [['branch_id' => null], 'branch_id'],
    'unknown branch' => [['branch_id' => 999999], 'branch_id'],
    'a branch of another company' => [['branch_id' => '@foreign-branch'], 'branch_id'],
    'no date' => [['count_date' => null], 'count_date'],
    'date in a wrong format' => [['count_date' => '20/09/2026'], 'count_date'],
    'an impossible date' => [['count_date' => '2026-02-30'], 'count_date'],
    'note too long' => [['note' => str_repeat('n', 501)], 'note'],
    'only in stock not a boolean' => [['only_in_stock' => 'maybe'], 'only_in_stock'],
    'unknown company' => [['company_id' => 999999], 'company_id'],
    'no company for the superadmin' => [['company_id' => null], 'company_id'],
]);

test('references count up and are never reused, even after a delete', function () {
    $scope = tktScope();

    $first = tktOpen($scope);
    $second = tktOpen($scope);
    $second->delete();
    $third = tktOpen($scope);

    expect([$first->reference, $second->reference, $third->reference])->toBe(['ST-00001', 'ST-00002', 'ST-00003']);
});

// --- counting ----------------------------------------------------------------------------------

test('counted quantities are saved, corrected and cleared on a draft sheet', function () {
    $scope = tktScope();
    $take = tktOpen($scope);
    $lineId = tktLine($take, $scope['variation_id'])->id;

    tktCount($take, [$scope['variation_id'] => 8]);
    expect(tktLine($take, $scope['variation_id'])->counted_qty)->toBe(8.0);

    tktCount($take, [$scope['variation_id'] => 8.25]);
    expect(tktLine($take, $scope['variation_id'])->counted_qty)->toBe(8.25);

    tktCount($take, [$scope['variation_id'] => 0]);
    expect(tktLine($take, $scope['variation_id'])->counted_qty)->toBe(0.0);

    $this->putJson('/api/stock-takes/'.$take->id.'/counts', ['counts' => [$lineId => null]])->assertSuccessful();
    expect(tktLine($take, $scope['variation_id'])->counted_qty)->toBeNull();
});

test('a count for a line of another sheet is ignored', function () {
    $scope = tktScope();
    $one = tktOpen($scope);
    $two = tktOpen($scope);
    $foreignLine = tktLine($two, $scope['variation_id'])->id;

    $this->putJson('/api/stock-takes/'.$one->id.'/counts', ['counts' => [$foreignLine => 5, 999999 => 1]])->assertSuccessful();

    expect(tktLine($two, $scope['variation_id'])->counted_qty)->toBeNull();
});

test('counting validates the quantities', function (array $counts) {
    $scope = tktScope();
    $take = tktOpen($scope);

    $this->putJson('/api/stock-takes/'.$take->id.'/counts', ['counts' => $counts])->assertUnprocessable();

    expect(tktLine($take, $scope['variation_id'])->counted_qty)->toBeNull();
})->with([
    'nothing to save' => [[]],
    'a negative quantity' => [[1 => -1]],
    'text' => [[1 => 'many']],
    'too large' => [[1 => 10000000]],
    'five decimals' => [[1 => 1.23456]],
]);

test('a completed sheet can no longer be counted', function () {
    $scope = tktScope();
    $take = tktOpen($scope);
    tktCount($take, [$scope['variation_id'] => 10]);
    $this->postJson('/api/stock-takes/'.$take->id.'/complete')->assertSuccessful();
    $lineId = tktLine($take, $scope['variation_id'])->id;

    $this->putJson('/api/stock-takes/'.$take->id.'/counts', ['counts' => [$lineId => 3]])->assertUnprocessable()->assertJsonPath('reason', 'not_draft');

    expect(tktLine($take, $scope['variation_id'])->counted_qty)->toBe(10.0);
});

// --- completing --------------------------------------------------------------------------------

test('completing writes a stock adjustment for the differences and stock then equals the count', function () {
    $scope = tktScope();
    $second = tktProduct($scope, 'Basmati Broken', 4);
    $third = tktProduct($scope, 'Sugar', 6);
    $take = tktOpen($scope);

    tktCount($take, [$scope['variation_id'] => 7, $second['variation_id'] => 9.5, $third['variation_id'] => 6]);

    $response = $this->postJson('/api/stock-takes/'.$take->id.'/complete')->assertSuccessful();

    $take->refresh();
    $adjustment = Transaction::query()->findOrFail($take->adjustment_id);
    $lines = $adjustment->purchaselines->keyBy('variation_id');

    expect($response->json('adjustment_id'))->toBe($adjustment->id)
        ->and($take->status)->toBe('completed')
        ->and($take->completed_at)->not->toBeNull()
        ->and($adjustment->type)->toBe(Transaction::TYPE_ADJUSTMENT)
        ->and($adjustment->status)->toBe('completed')
        ->and($adjustment->company_id)->toBe($scope['company_id'])
        ->and($adjustment->branch_id)->toBe($scope['branch_id'])
        ->and($adjustment->transaction_date->toDateString())->toBe('2026-09-20')
        ->and($adjustment->additional_note)->toBe('Stock take ST-00001')
        ->and($lines)->toHaveCount(2)
        ->and((float) $lines[$scope['variation_id']]->quantity_adjustment)->toBe(-3.0)
        ->and((float) $lines[$second['variation_id']]->quantity_adjustment)->toBe(5.5)
        ->and($lines->has($third['variation_id']))->toBeFalse()
        ->and(StockMovements::baseStock($scope['product_id'], $scope['variation_id'], $scope['branch_id']))->toBe(7.0)
        ->and(StockMovements::baseStock($second['product_id'], $second['variation_id'], $scope['branch_id']))->toBe(9.5)
        ->and(StockMovements::baseStock($third['product_id'], $third['variation_id'], $scope['branch_id']))->toBe(6.0);
});

test('lines nobody counted are left alone', function () {
    $scope = tktScope();
    $second = tktProduct($scope, 'Basmati Broken', 4);
    $take = tktOpen($scope);

    tktCount($take, [$scope['variation_id'] => 12]);
    $this->postJson('/api/stock-takes/'.$take->id.'/complete')->assertSuccessful();

    $adjustment = Transaction::query()->findOrFail($take->refresh()->adjustment_id);

    expect($adjustment->purchaselines)->toHaveCount(1)
        ->and(StockMovements::baseStock($second['product_id'], $second['variation_id'], $scope['branch_id']))->toBe(4.0)
        ->and(StockMovements::baseStock($scope['product_id'], $scope['variation_id'], $scope['branch_id']))->toBe(12.0);
});

test('a count that matches the system needs no adjustment', function () {
    $scope = tktScope();
    $take = tktOpen($scope);
    tktCount($take, [$scope['variation_id'] => 10]);

    $this->postJson('/api/stock-takes/'.$take->id.'/complete')->assertSuccessful()->assertJson(['adjustment_id' => null]);

    expect($take->refresh()->status)->toBe('completed')
        ->and($take->adjustment_id)->toBeNull()
        ->and(Transaction::query()->adjustments()->count())->toBe(0);
});

test('a difference below a hundredth is not an adjustment', function () {
    $scope = tktScope();
    $take = tktOpen($scope);
    tktCount($take, [$scope['variation_id'] => 10.004]);

    $this->postJson('/api/stock-takes/'.$take->id.'/complete')->assertSuccessful()->assertJson(['adjustment_id' => null]);

    expect(Transaction::query()->adjustments()->count())->toBe(0);
});

test('a sheet with nothing counted cannot be completed', function () {
    $scope = tktScope();
    $take = tktOpen($scope);

    $this->postJson('/api/stock-takes/'.$take->id.'/complete')->assertUnprocessable()->assertJsonPath('reason', 'nothing_counted');

    expect($take->refresh()->status)->toBe('draft');
});

test('a sheet is completed only once', function () {
    $scope = tktScope();
    $take = tktOpen($scope);
    tktCount($take, [$scope['variation_id'] => 4]);

    $this->postJson('/api/stock-takes/'.$take->id.'/complete')->assertSuccessful();
    $this->postJson('/api/stock-takes/'.$take->id.'/complete')->assertUnprocessable()->assertJsonPath('reason', 'not_draft');

    expect(Transaction::query()->adjustments()->count())->toBe(1)
        ->and(StockMovements::baseStock($scope['product_id'], $scope['variation_id'], $scope['branch_id']))->toBe(4.0);
});

test('the count is compared with the frozen quantity, so a later sale stays its own movement', function () {
    $scope = tktScope();
    $take = tktOpen($scope);
    $sale = trpDoc(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'customer_id' => $scope['contact_id'], 'supplier_id' => $scope['contact_id']], 'sell', ['status' => 'final']);
    prdSellLine($sale, $scope, 2, 5);

    // 10 on the shelf when counted (the sale came after), so no difference: stock is now 8 from the sale alone
    tktCount($take, [$scope['variation_id'] => 10]);
    $this->postJson('/api/stock-takes/'.$take->id.'/complete')->assertSuccessful()->assertJson(['adjustment_id' => null]);

    expect(StockMovements::baseStock($scope['product_id'], $scope['variation_id'], $scope['branch_id']))->toBe(8.0);
});

test('a decrease the branch can no longer cover is refused and nothing changes', function () {
    $scope = tktScope();
    $take = tktOpen($scope);
    $sale = trpDoc(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'customer_id' => $scope['contact_id'], 'supplier_id' => $scope['contact_id']], 'sell', ['status' => 'final']);
    prdSellLine($sale, $scope, 8, 5);

    // the shelf was empty at count time (10 -> 0) but only 2 remain in the system now
    tktCount($take, [$scope['variation_id'] => 0]);

    $this->postJson('/api/stock-takes/'.$take->id.'/complete')->assertUnprocessable()->assertJsonValidationErrors(['purchaselines']);

    expect($take->refresh()->status)->toBe('draft')
        ->and($take->adjustment_id)->toBeNull()
        ->and(Transaction::query()->adjustments()->count())->toBe(0)
        ->and(StockMovements::baseStock($scope['product_id'], $scope['variation_id'], $scope['branch_id']))->toBe(2.0);
});

// --- show, list, delete -----------------------------------------------------------------------

test('a sheet shows its lines, differences and summary', function () {
    $scope = tktScope();
    $second = tktProduct($scope, 'Basmati Broken', 4);
    $take = tktOpen($scope);
    tktCount($take, [$scope['variation_id'] => 7]);

    $response = $this->getJson('/api/stock-takes/'.$take->id)->assertSuccessful();

    $lines = collect($response->json('lines'))->keyBy('variation_id');

    expect($response->json('reference'))->toBe('ST-00001')
        ->and($response->json('status'))->toBe('draft')
        ->and($response->json('branch_name'))->toBe('Purchase Branch')
        ->and($response->json('company_name'))->toBe('Purchase Test Company')
        ->and($response->json('summary'))->toBe(['total_lines' => 2, 'counted_lines' => 1, 'differing_lines' => 1])
        ->and($lines[$scope['variation_id']]['system_qty'])->toBe(10)
        ->and($lines[$scope['variation_id']]['counted_qty'])->toBe(7)
        ->and($lines[$scope['variation_id']]['difference'])->toBe(-3)
        ->and($lines[$scope['variation_id']]['name'])->toBe('Premium Basmati Rice dummy')
        ->and($lines[$scope['variation_id']]['unit_name'])->toBe('KG')
        ->and($lines[$second['variation_id']]['counted_qty'])->toBeNull()
        ->and($lines[$second['variation_id']]['difference'])->toBeNull();

    $this->getJson('/api/stock-takes/999999')->assertNotFound();
});

test('a completed sheet shows the invoice number of its adjustment', function () {
    $scope = tktScope();
    $take = tktOpen($scope);
    tktCount($take, [$scope['variation_id'] => 5]);
    $this->postJson('/api/stock-takes/'.$take->id.'/complete')->assertSuccessful();

    $response = $this->getJson('/api/stock-takes/'.$take->id)->assertSuccessful();

    expect($response->json('status'))->toBe('completed')
        ->and($response->json('adjustment_invoice_no'))->toStartWith('SA-');
});

test('the list shows sheets with their progress, searches, filters and sorts safely', function () {
    $scope = tktScope();
    tktProduct($scope, 'Basmati Broken', 4);
    $first = tktOpen($scope, ['note' => 'Zone A shelves']);
    $second = tktOpen($scope, ['note' => 'Cold room']);
    tktCount($second, [$scope['variation_id'] => 10]);
    $this->postJson('/api/stock-takes/'.$second->id.'/complete')->assertSuccessful();

    $rows = fn (array $query) => collect($this->getJson('/api/stock-takes?'.http_build_query($query))->assertSuccessful()->json('data.data'));

    expect($rows(['sort_by' => 'reference', 'sort_type' => 'desc'])->pluck('reference')->all())->toBe(['ST-00002', 'ST-00001'])
        ->and($rows(['search' => 'Cold'])->pluck('reference')->all())->toBe(['ST-00002'])
        ->and($rows(['search' => 'ST-00001'])->pluck('reference')->all())->toBe(['ST-00001'])
        ->and($rows(['status' => 'draft'])->pluck('reference')->all())->toBe(['ST-00001'])
        ->and($rows(['status' => 'completed'])->pluck('reference')->all())->toBe(['ST-00002'])
        ->and($rows(['status' => 'bogus'])->count())->toBe(2)
        ->and($rows(['sort_by' => 'reference', 'sort_type' => 'asc'])->pluck('reference')->all())->toBe(['ST-00001', 'ST-00002'])
        ->and($rows(['branch_id' => $scope['branch_id']])->count())->toBe(2)
        ->and($rows([])->firstWhere('reference', 'ST-00002')['counted_lines_count'])->toBe(1)
        ->and($rows([])->firstWhere('reference', 'ST-00002')['lines_count'])->toBe(2)
        ->and($rows([])->firstWhere('reference', 'ST-00001')['branch_name'])->toBe('Purchase Branch');

    $this->getJson('/api/stock-takes?sort_by='.urlencode('id; drop table stock_takes').'&sort_type=sideways')->assertSuccessful();
    $this->getJson('/api/stock-takes?sort_by=password')->assertSuccessful();

    expect(StockTake::query()->count())->toBe(2)
        ->and($first->id)->not->toBe($second->id);
});

test('a draft sheet can be deleted, listed in the trash, restored and deleted for good with its lines', function () {
    $scope = tktScope();
    $take = tktOpen($scope);

    $this->deleteJson('/api/stock-takes/'.$take->id)->assertSuccessful();

    expect(StockTake::query()->find($take->id))->toBeNull();

    $this->getJson('/api/stock-takes/trash')->assertSuccessful()->assertJsonPath('data.data.0.reference', 'ST-00001');
    $this->getJson('/api/stock-takes')->assertJsonPath('trash_count', 1);

    $this->postJson('/api/stock-takes/restore_records', [$take->id])->assertSuccessful();
    expect(StockTake::query()->find($take->id))->not->toBeNull();

    $this->postJson('/api/stock-takes/bulk_delete', [$take->id])->assertSuccessful();
    $this->postJson('/api/stock-takes/bulk_delete_per', [$take->id])->assertSuccessful();

    expect(StockTake::withTrashed()->find($take->id))->toBeNull()
        ->and(StockTakeLine::query()->where('stock_take_id', $take->id)->count())->toBe(0);
});

test('a completed sheet cannot be deleted: it is the record of an adjustment', function () {
    $scope = tktScope();
    $take = tktOpen($scope);
    tktCount($take, [$scope['variation_id'] => 5]);
    $this->postJson('/api/stock-takes/'.$take->id.'/complete')->assertSuccessful();

    $this->deleteJson('/api/stock-takes/'.$take->id)->assertSuccessful();
    $this->postJson('/api/stock-takes/bulk_delete', [$take->id])->assertSuccessful();

    expect(StockTake::query()->find($take->id))->not->toBeNull();
});

// --- permissions and isolation -----------------------------------------------------------------

test('every write action is forbidden without its menu permission', function () {
    $scope = tktScope();
    $take = tktOpen($scope);
    Sanctum::actingAs(tktStaff($scope));

    $this->postJson('/api/stock-takes', tktPayload($scope))->assertForbidden();
    $this->putJson('/api/stock-takes/'.$take->id.'/counts', ['counts' => [1 => 5]])->assertForbidden();
    $this->postJson('/api/stock-takes/'.$take->id.'/complete')->assertForbidden();

    // delete and restore answer "406" in place of doing anything
    $this->deleteJson('/api/stock-takes/'.$take->id)->assertSuccessful()->assertContent('"406"');
    $this->postJson('/api/stock-takes/bulk_delete', [$take->id])->assertSuccessful()->assertContent('"406"');

    expect(StockTake::query()->count())->toBe(1)
        ->and($take->refresh()->status)->toBe('draft');
});

test('each permission opens only its own action', function () {
    $scope = tktScope();
    $take = tktOpen($scope);
    Sanctum::actingAs(tktStaff($scope, ['/stocktake/count']));

    $this->putJson('/api/stock-takes/'.$take->id.'/counts', ['counts' => [tktLine($take, $scope['variation_id'])->id => 3]])->assertSuccessful();
    $this->postJson('/api/stock-takes/'.$take->id.'/complete')->assertForbidden();
    $this->postJson('/api/stock-takes', tktPayload($scope))->assertForbidden();
});

test('a company user can open, count and complete in their own company', function () {
    $scope = tktScope();
    Sanctum::actingAs(tktStaff($scope, ['/stocktake/add', '/stocktake/count', '/stocktake/complete']));

    $this->postJson('/api/stock-takes', tktPayload($scope, ['company_id' => null]))->assertSuccessful();

    $take = StockTake::query()->firstOrFail();

    tktCount($take, [$scope['variation_id'] => 9]);
    $this->postJson('/api/stock-takes/'.$take->id.'/complete')->assertSuccessful();

    expect($take->refresh()->status)->toBe('completed')
        ->and($take->created_by)->not->toBeNull();
});

test('a company user always counts their own company, whatever company the request names', function () {
    $scope = tktScope();
    $foreign = tktForeign();
    Sanctum::actingAs(tktStaff($scope, ['/stocktake/add']));

    $this->postJson('/api/stock-takes', tktPayload($scope, ['company_id' => $foreign['company_id']]))->assertSuccessful();

    expect(StockTake::query()->firstOrFail()->company_id)->toBe($scope['company_id']);

    $this->postJson('/api/stock-takes', tktPayload($scope, ['branch_id' => $foreign['branch_id']]))->assertUnprocessable()->assertJsonValidationErrors(['branch_id']);
});

test('a user tied to a branch can only count that branch, a company admin any', function () {
    $scope = tktScope();
    $otherBranch = trpBranch($scope['company_id'], 'Second Branch');
    Sanctum::actingAs(tktStaff($scope, ['/stocktake/add'], 'storekeeper', $scope['branch_id']));

    $this->postJson('/api/stock-takes', tktPayload($scope, ['branch_id' => $otherBranch]))->assertUnprocessable()->assertJsonValidationErrors(['branch_id']);
    $this->postJson('/api/stock-takes', tktPayload($scope))->assertSuccessful();

    Sanctum::actingAs(tktStaff($scope, ['/stocktake/add']));

    $this->postJson('/api/stock-takes', tktPayload($scope, ['branch_id' => $otherBranch]))->assertSuccessful();
});

test('a user cannot see or change the sheets of another company or of another branch', function () {
    $scope = tktScope();
    $otherBranch = trpBranch($scope['company_id'], 'Second Branch');
    $mine = tktOpen($scope);
    $otherBranchSheet = tktOpen($scope, ['branch_id' => $otherBranch]);
    $foreign = tktForeign();
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/stock-takes', tktPayload($foreign))->assertSuccessful();
    $theirs = StockTake::query()->where('company_id', $foreign['company_id'])->firstOrFail();

    Sanctum::actingAs(tktStaff($scope, ['/stocktake/count', '/stocktake/complete', '/stocktake/delete'], 'storekeeper', $scope['branch_id']));

    expect(collect($this->getJson('/api/stock-takes')->assertSuccessful()->json('data.data'))->pluck('id')->all())->toBe([$mine->id]);

    foreach ([$theirs, $otherBranchSheet] as $hidden) {
        $this->getJson('/api/stock-takes/'.$hidden->id)->assertNotFound();
        $this->putJson('/api/stock-takes/'.$hidden->id.'/counts', ['counts' => [1 => 5]])->assertNotFound();
        $this->postJson('/api/stock-takes/'.$hidden->id.'/complete')->assertNotFound();
        $this->postJson('/api/stock-takes/bulk_delete', [$hidden->id])->assertSuccessful();

        expect($hidden->refresh()->trashed())->toBeFalse();
    }
});

test('the stock take api requires authentication', function () {
    $this->getJson('/api/stock-takes')->assertUnauthorized();
    $this->postJson('/api/stock-takes', [])->assertUnauthorized();
    $this->getJson('/api/stock-takes/1')->assertUnauthorized();
    $this->putJson('/api/stock-takes/1/counts', [])->assertUnauthorized();
    $this->postJson('/api/stock-takes/1/complete')->assertUnauthorized();
});

// --- pages -------------------------------------------------------------------------------------

test('guests are sent away from the stock take pages', function (string $routeName, array $parameters) {
    $this->get(route($routeName, $parameters))->assertRedirect();
})->with([
    'list' => ['stocktake', []],
    'add' => ['stocktake.add', []],
    'view' => ['stocktake.view', [3]],
    'trash' => ['stocktake.trash', []],
]);

test('the superadmin can open every stock take page', function (string $routeName, array $parameters, string $component) {
    $this->actingAs(User::query()->findOrFail(1))
        ->get(route($routeName, $parameters))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component($component));
})->with([
    'list' => ['stocktake', [], 'stocktake/index'],
    'add' => ['stocktake.add', [], 'stocktake/add'],
    'view' => ['stocktake.view', [3], 'stocktake/view'],
    'trash' => ['stocktake.trash', [], 'stocktake/trash'],
]);

test('the view page passes the sheet id', function () {
    $this->actingAs(User::query()->findOrFail(1))->get(route('stocktake.view', 12))
        ->assertInertia(fn ($page) => $page->component('stocktake/view')->where('id', '12'));
});

test('a user is let into exactly the stock take pages their menu permissions name', function () {
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true]);
    grantMenuPermission($role->id, '/stocktake');
    grantMenuPermission($role->id, '/stocktake/:id/view');
    $user = createStaffUserForRole($role);

    $this->actingAs($user)->get(route('stocktake'))->assertSuccessful();
    $this->actingAs($user)->get(route('stocktake.view', 3))->assertSuccessful();
    $this->actingAs($user)->get(route('stocktake.add'))->assertForbidden();
    $this->actingAs($user)->get(route('stocktake.trash'))->assertForbidden();

    $bare = createStaffUserForRole(Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true]));

    foreach (['stocktake' => [], 'stocktake.add' => [], 'stocktake.view' => [3], 'stocktake.trash' => []] as $name => $parameters) {
        $this->actingAs($bare)->get(route($name, $parameters))->assertForbidden();
    }
});
