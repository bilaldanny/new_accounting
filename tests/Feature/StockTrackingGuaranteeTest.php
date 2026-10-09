<?php

use App\Models\Product;
use App\Models\User;
use App\Services\StockMovements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The guarantee of the tracking layer: a product that is not tracked behaves exactly as before, and tracking a product never
 * changes the stock the rest of the app reads (`StockMovements` knows nothing of serials or batches).
 */
beforeEach(function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
});

/**
 * @return int the number of rows in every tracking table
 */
function stgTrackingRows(): int
{
    return collect(['stock_serials', 'stock_serial_movements', 'stock_batches', 'stock_batch_movements'])->sum(fn (string $table): int => DB::table($table)->count());
}

/**
 * Receive 10 through a receiving note, sell 4 and return the branch stock.
 *
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $receiveExtra
 * @param  array<string, mixed>  $sellExtra
 */
function stgReceiveAndSell(array $scope, array $receiveExtra, array $sellExtra): float
{
    $purchase = createPurchaseRecord($scope, ['status' => 'approved']);
    $line = $purchase->purchaselines()->first();
    $line->update(['quantity' => 10]);

    test()->postJson('/api/receiving-notes', validReceivingNotePayload($scope, $purchase, [
        'purchaselines' => [array_merge(['id' => $line->id, 'quantity_received' => 10], $receiveExtra)],
    ]))->assertSuccessful();

    $payload = validSellPayload($scope, ['final_amount' => 480]);
    $payload['selllines'][0] = array_merge($payload['selllines'][0], ['quantity' => 4, 'row_subtotal' => 480], $sellExtra);
    test()->postJson('/api/sells', $payload)->assertSuccessful();

    return StockMovements::baseStock($scope['product_id'], $scope['variation_id'], $scope['branch_id']);
}

test('a product that is not tracked is received, sold and transferred as before, and the tracking tables stay empty', function () {
    $scope = seedSellScope();
    expect(DB::table('products')->where('id', $scope['product_id'])->value('tracking_type'))->toBe('none');

    // Tracking data sent for an untracked product is simply not looked at.
    $stock = stgReceiveAndSell($scope, ['serials' => ['IGNORED'], 'batches' => [['batch_no' => 'X', 'qty' => 1]]], ['serials' => ['IGNORED']]);

    expect($stock)->toBe(6.0)->and(stgTrackingRows())->toBe(0);
});

test('tracking a product by serial leaves its stock exactly what it is without tracking', function () {
    $scope = seedSellScope();
    DB::table('products')->where('id', $scope['product_id'])->update(['tracking_type' => 'serial']);
    $serials = array_map(fn (int $n): string => "SN-{$n}", range(1, 10));

    $stock = stgReceiveAndSell($scope, ['serials' => $serials], ['serials' => array_slice($serials, 0, 4)]);

    expect($stock)->toBe(6.0)->and(stgTrackingRows())->toBeGreaterThan(0);
});

test('tracking a product by batch leaves its stock exactly what it is without tracking', function () {
    $scope = seedSellScope();
    DB::table('products')->where('id', $scope['product_id'])->update(['tracking_type' => 'batch']);

    $stock = stgReceiveAndSell($scope, ['batches' => [['batch_no' => 'B1', 'expiry_date' => '2027-12-31', 'qty' => 10]]], []);

    expect($stock)->toBe(6.0);
});

/**
 * Transfer 3 of 10 completed, and return the stock of both branches.
 *
 * @return array{0: float, 1: float}
 */
function stgTransfer(string $type): array
{
    $scope = seedStockTransferScope();
    DB::table('products')->where('id', $scope['product_id'])->update(['tracking_type' => $type]);

    if ($type === 'serial') {
        test()->postJson('/api/stock-tracking/serials', ['company_id' => $scope['company_id'], 'product_id' => $scope['product_id'], 'branch_id' => $scope['branch_id'], 'serials' => 'A,B,C'])->assertSuccessful();
    }

    $payload = validStockTransferPayload($scope, ['status' => 'completed']);
    $payload['purchaselines'][0]['quantity'] = 3;
    $payload['purchaselines'][0] += $type === 'serial' ? ['serials' => ['A', 'B', 'C']] : [];
    test()->postJson('/api/stocktransfers', $payload)->assertSuccessful();

    return [StockMovements::baseStock($scope['product_id'], $scope['variation_id'], $scope['branch_id']), StockMovements::baseStock($scope['product_id'], $scope['variation_id'], $scope['tobranch_id'])];
}

test('a transfer of an untracked product moves its stock as before', function () {
    expect(stgTransfer('none'))->toBe([7.0, 3.0])->and(stgTrackingRows())->toBe(0);
});

test('a transfer of a serial product moves exactly the same stock', function () {
    expect(stgTransfer('serial'))->toBe([7.0, 3.0]);
});

test('the stock definition does not read the tracking tables', function () {
    $sql = StockMovements::query()->toSql();

    expect($sql)->not->toContain('stock_serial')->and($sql)->not->toContain('stock_batch')->and($sql)->not->toContain('tracking');
});

test('a product keeps its tracking when a client that does not know the field saves it', function () {
    $scope = seedSellScope();
    DB::table('products')->where('id', $scope['product_id'])->update(['tracking_type' => 'batch']);
    $product = Product::query()->findOrFail($scope['product_id']);
    $apply = new ReflectionMethod($product, 'applyTrackingType');

    $apply->invoke($product, (object) ['name' => 'x']);
    $apply->invoke($product, (object) ['tracking_type' => 'rubbish']);

    expect($product->tracking_type)->toBe('batch');
});
