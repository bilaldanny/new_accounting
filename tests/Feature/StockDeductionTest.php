<?php

use App\Models\Product;
use App\Models\ProductDetail;
use App\Models\PurchaseLine;
use App\Models\SellLine;
use App\Models\Transaction;
use App\Models\Unit;
use App\Models\User;
use App\Services\StockMovements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;

/**
 * A sell scope (customer, product, chart accounts) plus $stock base units received in its branch.
 *
 * @return array<string, mixed>
 */
function stockSaleScope(float $stock = 10): array
{
    $scope = seedSellScope();
    receiveBaseStock($scope, $stock);

    return $scope;
}

/**
 * Receive stock through a purchase order, optionally in a larger unit ($packing base units each).
 *
 * @param  array<string, mixed>  $scope
 */
function receiveBaseStock(array $scope, float $quantity, ?int $unitId = null, int $packing = 1, ?int $branchId = null): Transaction
{
    static $counter = 0;
    $counter++;

    $purchase = Transaction::query()->create([
        'company_id' => $scope['company_id'],
        'branch_id' => $branchId ?? $scope['branch_id'],
        'contact_id' => $scope['contact_id'],
        'invoice_no' => "PO-STK-{$counter}",
        'type' => Transaction::TYPE_PURCHASE,
        'status' => 'received',
        'payment_status' => 'due',
        'transaction_date' => now(),
        'final_amount' => 100,
        'total_item' => 1,
    ]);

    PurchaseLine::query()->create([
        'transaction_id' => $purchase->id,
        'product_id' => $scope['product_id'],
        'variation_id' => $scope['variation_id'],
        'itemtype_id' => $scope['itemtype_id'],
        'unit_id' => $unitId ?? $scope['unit_id'],
        'quantity' => $quantity,
        'quantity_received' => $quantity,
        'purchase_rate' => 100,
        'pp_without_discount' => 100,
        'default_sell_price' => 120,
        'packing_qty' => $packing,
    ]);

    return $purchase;
}

/** @param  array<string, mixed>  $scope */
function stockNow(array $scope, ?int $branchId = null, ?int $unitId = null): float
{
    return PurchaseLine::currentStock($scope['product_id'], $scope['variation_id'], $unitId ?? $scope['unit_id'], $branchId ?? $scope['branch_id']);
}

/**
 * A sale of $quantity base units at 120 each (no shipping), as the sale form or the POS would send it.
 *
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function stockSalePayload(array $scope, float $quantity, array $overrides = []): array
{
    return validSellPayload($scope, array_merge([
        'final_amount' => $quantity * 120,
        'shipping_charges' => 0,
        'selllines' => [array_merge(validSellPayload($scope)['selllines'][0], ['quantity' => $quantity, 'row_subtotal' => $quantity * 120])],
    ], $overrides));
}

function setStockCutover(?string $at): void
{
    if (DB::table('settings')->doesntExist()) {
        DB::table('settings')->insert(['name' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
    }

    DB::table('settings')->update(['stock_sales_cutover_at' => $at]);
}

beforeEach(function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
});

// ---------------------------------------------------------------- sales reduce stock

test('saving a sale reduces stock immediately, without waiting for an issue note', function () {
    $scope = stockSaleScope(10);

    $this->postJson('/api/sells', stockSalePayload($scope, 3))->assertSuccessful();

    expect(stockNow($scope))->toBe(7.0)
        ->and(Transaction::query()->sells()->firstOrFail()->status)->toBe('final');
});

test('draft and quotation sales do not touch stock', function (string $status) {
    $scope = stockSaleScope(10);

    $this->postJson('/api/sells', stockSalePayload($scope, 3, ['status' => $status]))->assertSuccessful();

    expect(stockNow($scope))->toBe(10.0);
})->with(['draft', 'quotation']);

test('editing a sale moves stock by the difference and deleting it gives everything back', function () {
    $scope = stockSaleScope(10);
    $id = $this->postJson('/api/sells', stockSalePayload($scope, 3))->assertSuccessful()->json('id');

    $lineId = SellLine::query()->where('transaction_id', $id)->value('id');
    $line = array_merge(stockSalePayload($scope, 5)['selllines'][0], ['id' => $lineId]);
    $this->putJson("/api/sells/{$id}", stockSalePayload($scope, 5, ['selllines' => [$line]]))->assertSuccessful();

    expect(stockNow($scope))->toBe(5.0);

    $this->postJson('/api/sells/bulk_delete', [$id])->assertSuccessful();

    expect(stockNow($scope))->toBe(10.0);
});

test('turning a final sale into a draft gives its stock back', function () {
    $scope = stockSaleScope(10);
    $id = $this->postJson('/api/sells', stockSalePayload($scope, 4))->assertSuccessful()->json('id');
    expect(stockNow($scope))->toBe(6.0);

    $this->postJson('/api/sells/statusupdate', ['ids' => [$id], 'status' => 'draft'])->assertSuccessful();

    expect(stockNow($scope))->toBe(10.0);
});

test('stock is per branch and a null branch is the total', function () {
    $scope = stockSaleScope(10);
    $otherBranchId = DB::table('branches')->insertGetId([
        'code' => 'STKB02', 'company_id' => $scope['company_id'], 'name' => 'Second Branch', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    receiveBaseStock($scope, 6, branchId: $otherBranchId);

    $this->postJson('/api/sells', stockSalePayload($scope, 4))->assertSuccessful();

    expect(stockNow($scope))->toBe(6.0)
        ->and(stockNow($scope, $otherBranchId))->toBe(6.0)
        ->and(PurchaseLine::currentStock($scope['product_id'], $scope['variation_id'], $scope['unit_id'], null))->toBe(12.0);
});

// ---------------------------------------------------------------- sale returns

test('a sale return gives the returned stock back straight away and deleting it takes it out again', function () {
    $scope = stockSaleScope(10);
    $sell = createIssuedSell($scope, ['invoice_no' => 'INV-RET']);
    $sell->selllines()->update(['quantity' => 4, 'quantity_issue' => 4]);

    expect(stockNow($scope))->toBe(6.0);

    $returnId = $this->postJson('/api/sell-returns', validSellReturnPayload($scope, $sell, [
        'selllines' => [['id' => $sell->selllines()->first()->id, 'quantity_returned' => 3]],
    ]))->assertSuccessful()->json('id') ?? Transaction::query()->sellReturns()->value('id');

    expect(stockNow($scope))->toBe(9.0);

    $this->deleteJson("/api/sell-returns/{$returnId}")->assertSuccessful();

    expect(stockNow($scope))->toBe(6.0);
});

// ---------------------------------------------------------------- POS versus the sale form

test('the POS refuses to sell more than is in stock and nothing is saved', function () {
    $scope = stockSaleScope(10);

    $response = $this->postJson('/api/sells', stockSalePayload($scope, 11, ['is_pos' => true]))->assertUnprocessable();

    $response->assertJsonPath('code', 'insufficient_stock')
        ->assertJsonValidationErrors(['selllines']);

    expect($response->json('errors.selllines.0'))->toContain('Premium Basmati Rice', 'requested 11', 'available 10')
        ->and($response->json('shortages.0.requested'))->toEqual(11)
        ->and($response->json('shortages.0.available'))->toEqual(10)
        ->and(Transaction::query()->sells()->count())->toBe(0)
        ->and(stockNow($scope))->toBe(10.0);
});

test('the POS may sell exactly what is in stock and then nothing more', function () {
    $scope = stockSaleScope(10);

    $this->postJson('/api/sells', stockSalePayload($scope, 10, ['is_pos' => true]))->assertSuccessful();
    expect(stockNow($scope))->toBe(0.0);

    $this->postJson('/api/sells', stockSalePayload($scope, 1, ['is_pos' => true]))->assertUnprocessable()
        ->assertJsonPath('code', 'insufficient_stock');
});

test('the normal sale form may oversell and is only warned', function () {
    $scope = stockSaleScope(10);

    $response = $this->postJson('/api/sells', stockSalePayload($scope, 12))->assertSuccessful();

    expect($response->json('stock_warnings'))->toHaveCount(1)
        ->and($response->json('stock_warnings.0.product_id'))->toBe($scope['product_id'])
        ->and($response->json('stock_warnings.0.requested'))->toEqual(12)
        ->and($response->json('stock_warnings.0.available'))->toEqual(10)
        ->and(Transaction::query()->sells()->count())->toBe(1)
        ->and(stockNow($scope))->toBe(-2.0);
});

test('a sale within stock returns no warnings', function () {
    $scope = stockSaleScope(10);

    $this->postJson('/api/sells', stockSalePayload($scope, 4))->assertSuccessful()
        ->assertJsonPath('stock_warnings', []);
});

test('the POS does not check drafts and quotations because they take no stock', function (string $status) {
    $scope = stockSaleScope(10);

    $this->postJson('/api/sells', stockSalePayload($scope, 50, ['is_pos' => true, 'status' => $status]))->assertSuccessful();

    expect(stockNow($scope))->toBe(10.0);
})->with(['draft', 'quotation']);

test('two lines of the same product are added together before the POS checks them', function () {
    $scope = stockSaleScope(10);
    $line = fn (float $quantity) => array_merge(stockSalePayload($scope, $quantity)['selllines'][0]);

    $this->postJson('/api/sells', stockSalePayload($scope, 12, ['is_pos' => true, 'selllines' => [$line(6), $line(6)]]))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'insufficient_stock');
});

test('editing a sale does not count its own quantity against itself', function () {
    $scope = stockSaleScope(10);
    $id = $this->postJson('/api/sells', stockSalePayload($scope, 6))->assertSuccessful()->json('id');
    $lineId = SellLine::query()->where('transaction_id', $id)->value('id');
    $edit = fn (float $quantity, array $extra = []) => stockSalePayload($scope, $quantity, array_merge([
        'selllines' => [array_merge(stockSalePayload($scope, $quantity)['selllines'][0], ['id' => $lineId])],
    ], $extra));

    // 4 already sold elsewhere leaves 4 + this sale's own 6 = up to 10 for the edited sale.
    $this->putJson("/api/sells/{$id}", $edit(10, ['is_pos' => true]))->assertSuccessful();
    expect(stockNow($scope))->toBe(0.0);

    $this->putJson("/api/sells/{$id}", $edit(11, ['is_pos' => true]))->assertUnprocessable()
        ->assertJsonPath('code', 'insufficient_stock');
    expect(stockNow($scope))->toBe(0.0);
});

test('the sale show payload reports stock as it was before that sale', function () {
    $scope = stockSaleScope(10);
    $id = $this->postJson('/api/sells', stockSalePayload($scope, 4))->assertSuccessful()->json('id');

    expect(stockNow($scope))->toBe(6.0);

    $this->getJson("/api/sells/{$id}")->assertSuccessful()
        ->assertJsonPath('selllines.0.current_stock', 10);
});

test('the product search that feeds the forms shows the reduced stock', function () {
    $scope = stockSaleScope(10);
    $this->postJson('/api/sells', stockSalePayload($scope, 4))->assertSuccessful();

    $response = $this->getJson('/api/sells/search-products?company_id='.$scope['company_id'].'&branch_id='.$scope['branch_id'].'&search=Basmati')->assertSuccessful();

    expect($response->json('0.current_stock'))->toEqual(6);
});

// ---------------------------------------------------------------- base-unit conversion

test('stock is tracked in the base unit whatever unit a line uses', function () {
    $scope = stockSaleScope(10);
    $carton = Unit::query()->create([
        'company_id' => $scope['company_id'], 'parent_id' => $scope['unit_id'], 'name' => 'Carton', 'short_name' => 'CTN',
        'type' => 'large', 'active' => true, 'auto_adjustment' => false,
    ]);
    $detail = ProductDetail::query()->findOrFail($scope['variation_id']);
    expect((int) $detail->largequantity)->toBe(10);

    // +2 cartons of 10 received, -1 carton sold: 10 + 20 - 10 = 20 base units.
    receiveBaseStock($scope, 2, $carton->id, 10);
    $cartonLine = array_merge(stockSalePayload($scope, 1)['selllines'][0], ['unit_id' => $carton->id, 'packing_qty' => 10, 'quantity' => 1, 'row_subtotal' => 1200]);
    $this->postJson('/api/sells', stockSalePayload($scope, 1, ['final_amount' => 1200, 'selllines' => [$cartonLine]]))->assertSuccessful();

    $units = collect(Transaction::unitsForProduct($scope['product_id'], $scope['variation_id'], $scope['unit_id'], $scope['branch_id'], (int) $detail->smallquantity, (int) $detail->largequantity))->keyBy('id');

    expect(stockNow($scope))->toBe(20.0)
        ->and(stockNow($scope, unitId: $carton->id))->toBe(2.0)
        ->and((float) $units[$scope['unit_id']]['unit_qty'])->toBe(20.0)
        ->and((float) $units[$carton->id]['unit_qty'])->toBe(2.0)
        ->and($units[$carton->id]['packing_qty'])->toBe(10);
});

test('a larger unit only reports whole units and the base unit stays exact', function () {
    $scope = stockSaleScope(25);
    $carton = Unit::query()->create([
        'company_id' => $scope['company_id'], 'parent_id' => $scope['unit_id'], 'name' => 'Carton', 'short_name' => 'CTN',
        'type' => 'large', 'active' => true, 'auto_adjustment' => false,
    ]);

    expect(stockNow($scope))->toBe(25.0)
        ->and(stockNow($scope, unitId: $carton->id))->toBe(2.0);
});

test('the POS compares a carton sale in base units', function () {
    $scope = stockSaleScope(25);
    $carton = Unit::query()->create([
        'company_id' => $scope['company_id'], 'parent_id' => $scope['unit_id'], 'name' => 'Carton', 'short_name' => 'CTN',
        'type' => 'large', 'active' => true, 'auto_adjustment' => false,
    ]);
    $cartons = fn (float $quantity) => array_merge(stockSalePayload($scope, $quantity)['selllines'][0], ['unit_id' => $carton->id, 'packing_qty' => 10, 'quantity' => $quantity]);

    // 3 cartons = 30 base units against 25 in stock.
    $this->postJson('/api/sells', stockSalePayload($scope, 3, ['is_pos' => true, 'selllines' => [$cartons(3)]]))
        ->assertUnprocessable()
        ->assertJsonPath('shortages.0.requested', 30);

    $this->postJson('/api/sells', stockSalePayload($scope, 2, ['is_pos' => true, 'selllines' => [$cartons(2)]]))->assertSuccessful();
    expect(stockNow($scope))->toBe(5.0);
});

// ---------------------------------------------------------------- the cutover

test('a sale created before the cutover is not deducted and neither is its return', function () {
    $scope = stockSaleScope(10);
    $old = createIssuedSell($scope, ['invoice_no' => 'INV-OLD']);
    $old->selllines()->update(['quantity' => 4, 'quantity_issue' => 4]);
    Transaction::query()->whereKey($old->id)->update(['created_at' => '2026-01-01 10:00:00']);

    setStockCutover('2026-06-01 00:00:00');

    expect(stockNow($scope))->toBe(10.0);

    $this->postJson('/api/sell-returns', validSellReturnPayload($scope, $old->fresh(), [
        'selllines' => [['id' => $old->selllines()->first()->id, 'quantity_returned' => 2]],
    ]))->assertSuccessful();

    expect(stockNow($scope))->toBe(10.0);

    $this->postJson('/api/sells', stockSalePayload($scope, 3))->assertSuccessful();

    expect(stockNow($scope))->toBe(7.0);
});

test('a sale created exactly at the cutover counts', function () {
    $scope = stockSaleScope(10);
    $sell = createSellRecord($scope, ['invoice_no' => 'INV-EDGE']);
    $sell->selllines()->update(['quantity' => 2]);
    Transaction::query()->whereKey($sell->id)->update(['created_at' => '2026-06-01 00:00:00']);

    setStockCutover('2026-06-01 00:00:00');

    expect(stockNow($scope))->toBe(8.0);
});

test('with no cutover every sale counts', function () {
    $scope = stockSaleScope(10);
    $old = createSellRecord($scope, ['invoice_no' => 'INV-ANY']);
    $old->selllines()->update(['quantity' => 2]);
    Transaction::query()->whereKey($old->id)->update(['created_at' => '2020-01-01 00:00:00']);

    setStockCutover(null);

    expect(stockNow($scope))->toBe(8.0);
});

test('editing a sale that predates the cutover neither deducts it nor warns', function () {
    $scope = stockSaleScope(10);
    $id = $this->postJson('/api/sells', stockSalePayload($scope, 3))->assertSuccessful()->json('id');
    Transaction::query()->whereKey($id)->update(['created_at' => '2026-01-01 10:00:00']);
    setStockCutover('2026-06-01 00:00:00');

    expect(stockNow($scope))->toBe(10.0);

    $lineId = SellLine::query()->where('transaction_id', $id)->value('id');
    $line = array_merge(stockSalePayload($scope, 50)['selllines'][0], ['id' => $lineId]);

    $this->putJson("/api/sells/{$id}", stockSalePayload($scope, 50, ['is_pos' => true, 'selllines' => [$line]]))
        ->assertSuccessful()
        ->assertJsonPath('stock_warnings', []);

    expect(stockNow($scope))->toBe(10.0);
});

test('the cutover migration stamps existing settings with now and leaves a fresh install open', function () {
    $migration = require database_path('migrations/2026_09_19_121243_add_stock_sales_cutover_to_settings_table.php');

    expect(Schema::hasColumn('settings', 'stock_sales_cutover_at'))->toBeTrue();

    $migration->down();
    expect(Schema::hasColumn('settings', 'stock_sales_cutover_at'))->toBeFalse();

    DB::table('settings')->insert(['name' => 'Existing', 'created_at' => now(), 'updated_at' => now()]);
    $migration->up();

    $stamp = DB::table('settings')->value('stock_sales_cutover_at');
    expect($stamp)->not->toBeNull()
        ->and(abs(now()->diffInSeconds($stamp)))->toBeLessThan(5);

    DB::table('settings')->delete();
    $migration->down();
    $migration->up();

    expect(DB::table('settings')->count())->toBe(0)
        ->and(StockMovements::salesCutover())->toBeNull();
});

test('before the migration has run stock keeps its old behaviour instead of failing', function () {
    $scope = stockSaleScope(10);
    $migration = require database_path('migrations/2026_09_19_121243_add_stock_sales_cutover_to_settings_table.php');

    $migration->down();
    createSellRecord($scope, ['invoice_no' => 'INV-BEFORE'])->selllines()->update(['quantity' => 4]);

    expect(stockNow($scope))->toBe(10.0);

    $migration->up();
    setStockCutover(null);

    expect(stockNow($scope))->toBe(6.0);
});

// ---------------------------------------------------------------- guards and the report still agree

test('the transfer guard sees stock that has been sold', function () {
    $scope = stockSaleScope(10);
    $toBranchId = DB::table('branches')->insertGetId([
        'code' => 'STKB03', 'company_id' => $scope['company_id'], 'name' => 'Destination', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->postJson('/api/sells', stockSalePayload($scope, 8))->assertSuccessful();

    $this->postJson('/api/stocktransfers', validStockTransferPayload(array_merge($scope, ['tobranch_id' => $toBranchId]), ['status' => 'pending']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['purchaselines']);

    $ok = validStockTransferPayload(array_merge($scope, ['tobranch_id' => $toBranchId]), ['status' => 'pending']);
    $ok['purchaselines'][0]['quantity'] = 2;
    $this->postJson('/api/stocktransfers', $ok)->assertSuccessful();
});

test('the adjustment guard sees stock that has been sold', function () {
    $scope = stockSaleScope(10);
    $this->postJson('/api/sells', stockSalePayload($scope, 8))->assertSuccessful();

    $decrease = fn (float $quantity) => validStockAdjustmentPayload($scope, [
        'purchaselines' => [array_merge(validStockAdjustmentPayload($scope)['purchaselines'][0], ['quantity_adjustment' => -$quantity])],
    ]);

    $this->postJson('/api/stockadjustments', $decrease(5))->assertUnprocessable()->assertJsonValidationErrors(['purchaselines']);
    $this->postJson('/api/stockadjustments', $decrease(2))->assertSuccessful();

    expect(stockNow($scope))->toBe(0.0);
});

test('the movement query is the only place that defines stock', function () {
    // The sale check reads stock through StockMovements; it may not query the line tables or carry a
    // formula of its own.
    foreach (['app/Services/SaleStockCheck.php'] as $path) {
        $source = file_get_contents(base_path($path));

        expect($source)->toContain('StockMovements::')
            ->and($source)->not->toContain('purchase_lines')
            ->and($source)->not->toContain('sell_lines');
    }

    expect(file_get_contents(base_path('app/Models/PurchaseLine.php')))->toContain('StockMovements::inUnit(');
});
