<?php

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
});

/**
 * @param  array<string, mixed>  $scope
 */
function ssTrack(array $scope, string $type): void
{
    DB::table('products')->where('id', $scope['product_id'])->update(['tracking_type' => $type]);
}

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $tracking
 */
function ssReceive(array $scope, int $quantity, array $tracking): TestResponse
{
    $purchase = createPurchaseRecord($scope, ['status' => 'approved', 'invoice_no' => 'PO-SS-'.DB::table('transactions')->count()]);
    $line = $purchase->purchaselines()->first();
    $line->update(['quantity' => $quantity]);

    return test()->postJson('/api/receiving-notes', validReceivingNotePayload($scope, $purchase, [
        'purchaselines' => [array_merge(['id' => $line->id, 'quantity_received' => $quantity], $tracking)],
    ]));
}

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $tracking
 */
function ssSell(array $scope, int $quantity, array $tracking): TestResponse
{
    $payload = validSellPayload($scope, ['final_amount' => 120 * $quantity, 'invoice_no' => 'INV-SS-'.DB::table('transactions')->count()]);
    $payload['selllines'][0] = array_merge($payload['selllines'][0], ['quantity' => $quantity, 'row_subtotal' => 120 * $quantity], $tracking);

    return test()->postJson('/api/sells', $payload);
}

test('the traceability log follows a serial from the supplier to the customer and shows a deleted sale as reversed', function () {
    $scope = seedSellScope();
    ssTrack($scope, 'serial');
    ssReceive($scope, 2, ['serials' => ['IMEI-1', 'IMEI-2']])->assertSuccessful();
    ssSell($scope, 1, ['serials' => ['IMEI-1']])->assertSuccessful();

    $rows = prpRows(prpGet('serial-traceability', ['search' => 'IMEI-1', 'show_record' => 50, 'sort_by' => 'id', 'sort_type' => 'asc']));
    expect($rows->pluck('movement')->all())->toBe(['Received', 'Sold'])
        ->and($rows->pluck('counts')->all())->toBe(['Yes', 'Yes'])
        ->and($rows->pluck('direction')->all())->toBe(['In', 'Out'])
        ->and($rows->last()['document_type'])->toBe('Sell');

    $this->deleteJson('/api/sells/'.Transaction::query()->where('type', 'sell')->value('id'))->assertSuccessful();

    $response = prpGet('serial-traceability', ['search' => 'IMEI-1', 'sort_by' => 'id', 'sort_type' => 'asc']);
    expect(prpRows($response)->pluck('counts')->all())->toBe(['Yes', 'Reversed'])
        ->and($response->json('summary.reversed'))->toBe(1)
        ->and($response->json('summary.serials'))->toBe(1);

    expect(prpRows(prpGet('serial-traceability', ['status' => 'sale']))->count())->toBe(1)
        ->and(prpRows(prpGet('serial-traceability', ['search' => 'nothing-like-it']))->count())->toBe(0);
});

test('the batch expiry report lists batches with stock by how soon they expire', function () {
    $scope = seedSellScope();
    ssTrack($scope, 'batch');
    $body = ['company_id' => $scope['company_id'], 'product_id' => $scope['product_id'], 'branch_id' => $scope['branch_id']];
    $today = now();

    foreach ([['PAST', $today->copy()->subDays(10), 5], ['SOON', $today->copy()->addDays(10), 7], ['LATER', $today->copy()->addDays(100), 20], ['NONE', null, 3]] as [$no, $date, $qty]) {
        $this->postJson('/api/stock-tracking/batches', $body + ['batch_no' => $no, 'expiry_date' => $date?->toDateString(), 'qty' => $qty])->assertSuccessful();
    }

    $response = prpGet('batch-expiry', ['company_id' => $scope['company_id'], 'show_record' => 50]);
    $rows = prpRows($response);

    expect($rows->pluck('batch_no')->all())->toBe(['PAST', 'SOON', 'LATER', 'NONE'])
        ->and($rows->pluck('status')->all())->toBe(['Expired', 'Expiring soon', 'OK', 'No expiry date'])
        ->and($rows->pluck('days_left')->all())->toBe([-10, 10, 100, null])
        ->and($response->json('summary'))->toMatchArray(['batches' => 4, 'qty' => 35, 'expired_qty' => 5, 'expiring_qty' => 7]);

    expect(prpRows(prpGet('batch-expiry', ['status' => 'Expiring soon']))->pluck('batch_no')->all())->toBe(['SOON'])
        ->and(prpRows(prpGet('batch-expiry', ['end_date' => $today->copy()->addDays(30)->toDateString()]))->pluck('batch_no')->all())->toBe(['PAST', 'SOON']);

    // A batch that is used up is no longer listed.
    $id = DB::table('stock_batches')->where('batch_no', 'SOON')->value('id');
    $this->postJson("/api/stock-tracking/batches/{$id}/write-off", $body + ['qty' => 7])->assertSuccessful();
    expect(prpRows(prpGet('batch-expiry'))->pluck('batch_no')->all())->not->toContain('SOON');
});

test('the dashboard warns about batches that have expired or are about to', function () {
    $scope = seedSellScope();
    ssTrack($scope, 'batch');
    $body = ['company_id' => $scope['company_id'], 'product_id' => $scope['product_id'], 'branch_id' => $scope['branch_id']];
    $this->postJson('/api/stock-tracking/batches', $body + ['batch_no' => 'PAST', 'expiry_date' => now()->subDays(3)->toDateString(), 'qty' => 5])->assertSuccessful();
    $this->postJson('/api/stock-tracking/batches', $body + ['batch_no' => 'SOON', 'expiry_date' => now()->addDays(5)->toDateString(), 'qty' => 2])->assertSuccessful();
    $this->postJson('/api/stock-tracking/batches', $body + ['batch_no' => 'LATER', 'expiry_date' => now()->addDays(90)->toDateString(), 'qty' => 9])->assertSuccessful();

    $movement = $this->getJson('/api/dashboard/movement?company_id='.$scope['company_id'])->assertSuccessful()->json('data.inventory_movement');

    expect(array_column($movement['expiring'], 'batch_no'))->toBe(['PAST', 'SOON'])
        ->and($movement['expiry_counts'])->toBe(['expired' => 1, 'expiring' => 1])
        ->and($movement['expiring'][0]['days_left'])->toBe(-3)
        ->and($movement['dead_stock'])->toBeArray();
});

test('a company with no tracked products gets an empty expiry warning', function () {
    $scope = seedSellScope();

    $movement = $this->getJson('/api/dashboard/movement?company_id='.$scope['company_id'])->assertSuccessful()->json('data.inventory_movement');

    expect($movement['expiring'])->toBe([])->and($movement['expiry_counts'])->toBe(['expired' => 0, 'expiring' => 0]);
});

test('the forms get the tracking of each line back when a document is opened again', function () {
    $scope = seedSellScope();
    ssTrack($scope, 'serial');
    ssReceive($scope, 2, ['serials' => ['A1', 'A2']])->assertSuccessful();

    $purchase = Transaction::query()->where('type', 'purchaseorder')->firstOrFail();
    $received = $this->getJson('/api/receiving-notes/purchase/'.$purchase->id)->assertSuccessful()->json('purchaselines.0');
    expect($received['tracking_type'])->toBe('serial')->and($received['serials'])->toBe(['A1', 'A2'])->and($received['packing_qty'])->toBe(1);

    ssSell($scope, 1, ['serials' => ['A2']])->assertSuccessful();
    $sale = Transaction::query()->where('type', 'sell')->firstOrFail();
    $line = $this->getJson('/api/sells/'.$sale->id)->assertSuccessful()->json('selllines.0');
    expect($line['tracking_type'])->toBe('serial')->and($line['serials'])->toBe(['A2'])->and($line['batches'])->toBe([]);

    $suggestions = $this->getJson('/api/purchases/search-products?'.http_build_query(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']]))->assertSuccessful()->json();
    expect(collect($suggestions)->firstWhere('product_id', $scope['product_id'])['tracking_type'])->toBe('serial');
});

test('batches come back on an opened sale in the unit of its line', function () {
    $scope = seedSellScope();
    ssTrack($scope, 'batch');
    $this->postJson('/api/stock-tracking/batches', ['company_id' => $scope['company_id'], 'product_id' => $scope['product_id'], 'branch_id' => $scope['branch_id'], 'batch_no' => 'B1', 'expiry_date' => '2027-12-31', 'qty' => 10])->assertSuccessful();

    ssSell($scope, 3, [])->assertSuccessful();
    $sale = Transaction::query()->where('type', 'sell')->firstOrFail();

    $line = $this->getJson('/api/sells/'.$sale->id)->json('selllines.0');
    expect($line['batches'])->toHaveCount(1)->and($line['batches'][0]['batch_no'])->toBe('B1')->and($line['batches'][0]['qty'])->toEqual(3);
});

test('the Serials and Batches page opens for an authorised user', function () {
    $this->get('/stocktracking')->assertSuccessful();
});

test('the tracking menu migration adds the page and both reports and grants them to companyadmin', function () {
    DB::table('permissions')->delete();
    DB::table('menus')->delete();
    $inventory = DB::table('menus')->insertGetId(['parent_id' => null, 'name' => 'Inventory', 'icon' => '', 'route_name' => 'invgroup', 'route_path' => '', 'menu_color' => '#000', 'sort_order' => 1, 'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 0, 'type' => 2, 'created_at' => now(), 'updated_at' => now()]);
    $reports = DB::table('menus')->insertGetId(['parent_id' => null, 'name' => 'Reports', 'icon' => '', 'route_name' => 'reportsgroup', 'route_path' => '', 'menu_color' => '#000', 'sort_order' => 2, 'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 0, 'type' => 2, 'created_at' => now(), 'updated_at' => now()]);

    foreach ([[$inventory, 'Warehouses', '/warehouse'], [$reports, 'Stock', '/report/stock']] as [$parent, $name, $path]) {
        DB::table('menus')->insert(['parent_id' => $parent, 'name' => $name, 'icon' => '', 'route_name' => ltrim($path, '/'), 'route_path' => $path, 'menu_color' => '#000', 'sort_order' => 1, 'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    $roleId = DB::table('roles')->insertGetId(['name' => 'companyadmin', 'company_id' => null, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    (require database_path('migrations/2026_10_26_100000_add_stock_tracking_menus.php'))->up();

    expect(DB::table('menus')->where('route_path', 'like', '/stocktracking%')->orderBy('route_path')->pluck('route_path')->all())->toBe(['/stocktracking', '/stocktracking/add', '/stocktracking/edit'])
        ->and((int) DB::table('menus')->where('route_path', '/stocktracking')->value('parent_id'))->toBe($inventory)
        ->and((int) DB::table('menus')->where('route_path', '/report/serial-traceability')->value('parent_id'))->toBe($reports)
        ->and((int) DB::table('menus')->where('route_path', '/report/batch-expiry')->value('parent_id'))->toBe($reports)
        ->and(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(5);
});
