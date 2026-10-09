<?php

use App\Models\Transaction;
use App\Models\User;
use App\Services\StockMovements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
});

/**
 * The warehouse columns of a document straight from the table.
 *
 * @return array{warehouse_id: int|null, towarehouse_id: int|null}
 */
function dwTag(int $transactionId): array
{
    $row = DB::table('transactions')->where('id', $transactionId)->first(['warehouse_id', 'towarehouse_id']);

    return ['warehouse_id' => $row->warehouse_id === null ? null : (int) $row->warehouse_id, 'towarehouse_id' => $row->towarehouse_id === null ? null : (int) $row->towarehouse_id];
}

function dwId(TestResponse $response, string $type): int
{
    $response->assertSuccessful();

    return (int) Transaction::query()->where('type', $type)->latest('id')->value('id');
}

test('a purchase order can name a warehouse of its branch, is unassigned without one, and keeps its tag when a client does not send the field', function () {
    $scope = seedPurchaseScope();
    $warehouse = wgWarehouse($scope['company_id'], $scope['branch_id'], 'Main');

    $untagged = dwId($this->postJson('/api/purchases', validPurchasePayload($scope)), 'purchaseorder');
    $tagged = dwId($this->postJson('/api/purchases', validPurchasePayload($scope, ['warehouse_id' => $warehouse, 'invoice_no' => 'PO-TAGGED'])), 'purchaseorder');

    expect(dwTag($untagged)['warehouse_id'])->toBeNull()
        ->and(dwTag($tagged)['warehouse_id'])->toBe($warehouse);

    // An update that does not send the field leaves the tag; an empty one clears it; another warehouse replaces it.
    $second = wgWarehouse($scope['company_id'], $scope['branch_id'], 'Second');
    $this->putJson("/api/purchases/{$tagged}", validPurchasePayload($scope, ['invoice_no' => 'PO-TAGGED']))->assertSuccessful();
    expect(dwTag($tagged)['warehouse_id'])->toBe($warehouse);

    $this->putJson("/api/purchases/{$tagged}", validPurchasePayload($scope, ['invoice_no' => 'PO-TAGGED', 'warehouse_id' => $second]))->assertSuccessful();
    expect(dwTag($tagged)['warehouse_id'])->toBe($second);

    $this->putJson("/api/purchases/{$tagged}", validPurchasePayload($scope, ['invoice_no' => 'PO-TAGGED', 'warehouse_id' => '']))->assertSuccessful();
    expect(dwTag($tagged)['warehouse_id'])->toBeNull();
});

test('a warehouse of another branch, an inactive or a deleted one is refused and nothing is saved', function () {
    $scope = seedPurchaseScope();
    $otherBranch = DB::table('branches')->insertGetId(['code' => 'DWB002', 'company_id' => $scope['company_id'], 'name' => 'Other branch', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $elsewhere = wgWarehouse($scope['company_id'], $otherBranch, 'Elsewhere');
    $inactive = wgWarehouse($scope['company_id'], $scope['branch_id'], 'Closed');
    $deleted = wgWarehouse($scope['company_id'], $scope['branch_id'], 'Gone');
    DB::table('warehouses')->where('id', $inactive)->update(['is_active' => 0]);
    DB::table('warehouses')->where('id', $deleted)->update(['deleted_at' => now()]);
    $before = Transaction::query()->count();

    foreach ([$elsewhere, $inactive, $deleted, 999999] as $bad) {
        $this->postJson('/api/purchases', validPurchasePayload($scope, ['warehouse_id' => $bad]))->assertUnprocessable()->assertJsonValidationErrors(['warehouse_id']);
    }

    expect(Transaction::query()->count())->toBe($before);
});

test('a sale can name the warehouse it is sold from', function () {
    $scope = seedSellScope();
    $warehouse = wgWarehouse($scope['company_id'], $scope['branch_id'], 'Shop floor');

    $id = dwId($this->postJson('/api/sells', validSellPayload($scope, ['warehouse_id' => $warehouse])), 'sell');
    expect(dwTag($id)['warehouse_id'])->toBe($warehouse);

    $otherBranch = DB::table('branches')->insertGetId(['code' => 'DWS002', 'company_id' => $scope['company_id'], 'name' => 'Other branch', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $this->postJson('/api/sells', validSellPayload($scope, ['warehouse_id' => wgWarehouse($scope['company_id'], $otherBranch, 'Elsewhere')]))->assertUnprocessable()->assertJsonValidationErrors(['warehouse_id']);
});

test('a stock adjustment can name its warehouse', function () {
    $scope = seedStockAdjustmentScope();
    $warehouse = wgWarehouse($scope['company_id'], $scope['branch_id'], 'Main');

    $id = dwId($this->postJson('/api/stockadjustments', validStockAdjustmentPayload($scope, ['warehouse_id' => $warehouse])), 'adjustment');

    expect(dwTag($id))->toBe(['warehouse_id' => $warehouse, 'towarehouse_id' => null]);
});

test('a stock transfer names the warehouse it leaves and the one it arrives in, each of its own branch', function () {
    $scope = seedStockTransferScope();
    $from = wgWarehouse($scope['company_id'], $scope['branch_id'], 'Source');
    $to = wgWarehouse($scope['company_id'], $scope['tobranch_id'], 'Destination');

    $id = dwId($this->postJson('/api/stocktransfers', validStockTransferPayload($scope, ['warehouse_id' => $from, 'towarehouse_id' => $to])), 'transfer');
    expect(dwTag($id))->toBe(['warehouse_id' => $from, 'towarehouse_id' => $to]);

    // The destination warehouse must belong to the destination branch, the source one to the source branch.
    $this->postJson('/api/stocktransfers', validStockTransferPayload($scope, ['warehouse_id' => $from, 'towarehouse_id' => $from]))->assertUnprocessable()->assertJsonValidationErrors(['towarehouse_id']);
    $this->postJson('/api/stocktransfers', validStockTransferPayload($scope, ['warehouse_id' => $to]))->assertUnprocessable()->assertJsonValidationErrors(['warehouse_id']);

    // Only the source can be given: the arrival stays unassigned.
    $half = dwId($this->postJson('/api/stocktransfers', validStockTransferPayload($scope, ['warehouse_id' => $from])), 'transfer');
    expect(dwTag($half))->toBe(['warehouse_id' => $from, 'towarehouse_id' => null]);
});

test('a transfer tagged through the API shows up in the Warehouse Stock report and branch stock is unchanged by the tags', function () {
    $scope = seedStockTransferScope();
    trpActAsSuperadmin();
    $from = wgWarehouse($scope['company_id'], $scope['branch_id'], 'Source');
    $to = wgWarehouse($scope['company_id'], $scope['tobranch_id'], 'Destination');

    $untagged = dwId($this->postJson('/api/stocktransfers', validStockTransferPayload($scope, ['status' => 'completed'])), 'transfer');
    $branchStock = fn (int $branch): float => StockMovements::baseStock($scope['product_id'], $scope['variation_id'], $branch);
    $before = [$branchStock($scope['branch_id']), $branchStock($scope['tobranch_id'])];

    $this->putJson("/api/stocktransfers/{$untagged}", validStockTransferPayload($scope, ['status' => 'completed', 'warehouse_id' => $from, 'towarehouse_id' => $to]))->assertSuccessful();

    expect([$branchStock($scope['branch_id']), $branchStock($scope['tobranch_id'])])->toEqual($before);

    $rows = prpRows(prpGet('warehouse-stock', ['show_record' => 100]))->keyBy('warehouse_name');
    expect($rows['Destination']['on_hand'])->toEqual(4)->and($rows->has('Source'))->toBeTrue();
});
