<?php

use App\Models\Product;
use App\Models\PurchaseLine;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StockMovements;
use App\Services\StockTracking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
});

/**
 * @param  array<string, mixed>  $scope
 */
function stTrack(array $scope, string $type): void
{
    DB::table('products')->where('id', $scope['product_id'])->update(['tracking_type' => $type]);
}

/**
 * Status of every serial of the scope's product, by serial number.
 *
 * @param  array<string, mixed>  $scope
 * @return array<string, array{status: string, branch: int|null}>
 */
function stSerials(array $scope): array
{
    return DB::query()->fromSub(StockTracking::serialStates(), 'x')->where('x.product_id', $scope['product_id'])->get()
        ->mapWithKeys(fn ($row) => [$row->serial_no => ['status' => StockTracking::serialStatus($row), 'branch' => $row->branch_id === null ? null : (int) $row->branch_id]])->all();
}

/**
 * Quantity left of every batch of the scope's product in a branch, by batch number.
 *
 * @param  array<string, mixed>  $scope
 * @return array<string, float>
 */
function stBatches(array $scope, ?int $branchId = null): array
{
    return DB::query()->fromSub(StockTracking::batchStock(), 'x')->where('x.product_id', $scope['product_id'])
        ->when($branchId !== null, fn ($q) => $q->where('x.branch_id', $branchId))->get()
        ->mapWithKeys(fn ($row) => [$row->batch_no => round((float) $row->qty, 4)])->all();
}

/**
 * Receives a purchase of $quantity units through a receiving note, with the given tracking data on its line.
 *
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $tracking
 */
function stReceive(array $scope, int $quantity, array $tracking = [], string $invoice = 'PO-ST-1'): TestResponse
{
    $purchase = createPurchaseRecord($scope, ['status' => 'approved', 'invoice_no' => $invoice]);
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
function stSell(array $scope, int $quantity, array $tracking = [], array $overrides = []): TestResponse
{
    $payload = validSellPayload($scope, $overrides);
    $payload['selllines'][0] = array_merge($payload['selllines'][0], ['quantity' => $quantity, 'row_subtotal' => 120 * $quantity], $tracking);
    $payload['final_amount'] = 120 * $quantity;

    return test()->postJson('/api/sells', $payload);
}

function stLatestSell(): Transaction
{
    return Transaction::query()->where('type', 'sell')->latest('id')->firstOrFail();
}

test('a serial product must be received with exactly one serial number per unit, and each serial is then in stock', function () {
    $scope = seedSellScope();
    stTrack($scope, 'serial');

    stReceive($scope, 3)->assertUnprocessable()->assertJsonValidationErrors(['purchaselines.0.serials']);
    stReceive($scope, 3, ['serials' => ['S1', 'S2']])->assertUnprocessable()->assertJsonValidationErrors(['purchaselines.0.serials']);
    stReceive($scope, 3, ['serials' => ['S1', 'S1', 'S2']])->assertUnprocessable()->assertJsonValidationErrors(['purchaselines.0.serials']);
    expect(stSerials($scope))->toBe([]);

    stReceive($scope, 3, ['serials' => ['S1', 'S2', 'S3']])->assertSuccessful();

    expect(stSerials($scope))->toBe([
        'S1' => ['status' => 'in_stock', 'branch' => $scope['branch_id']],
        'S2' => ['status' => 'in_stock', 'branch' => $scope['branch_id']],
        'S3' => ['status' => 'in_stock', 'branch' => $scope['branch_id']],
    ]);

    // A serial that is in stock cannot be received again.
    stReceive($scope, 1, ['serials' => ['S2']], 'PO-ST-2')->assertUnprocessable()->assertJsonValidationErrors(['purchaselines.0.serials']);
});

test('deleting a receiving note takes its serials out of stock again', function () {
    $scope = seedSellScope();
    stTrack($scope, 'serial');
    stReceive($scope, 2, ['serials' => ['A1', 'A2']])->assertSuccessful();
    $note = Transaction::query()->receivingNotes()->firstOrFail();

    $this->deleteJson('/api/receiving-notes/'.$note->id)->assertSuccessful();

    expect(collect(stSerials($scope))->pluck('status')->unique()->all())->toBe(['none']);
});

test('a sale must name the serials it sells, they leave stock and come back when the sale is deleted or changed', function () {
    $scope = seedSellScope();
    stTrack($scope, 'serial');
    stReceive($scope, 3, ['serials' => ['S1', 'S2', 'S3']])->assertSuccessful();

    stSell($scope, 2)->assertUnprocessable()->assertJsonValidationErrors(['selllines.0.serials']);
    stSell($scope, 2, ['serials' => ['S1', 'NOPE']])->assertUnprocessable()->assertJsonValidationErrors(['selllines.0.serials']);
    stSell($scope, 2, ['serials' => ['S1']])->assertUnprocessable()->assertJsonValidationErrors(['selllines.0.serials']);

    stSell($scope, 2, ['serials' => ['S1', 'S2']])->assertSuccessful();
    $sale = stLatestSell();
    expect(array_column(stSerials($scope), 'status'))->toBe(['sold', 'sold', 'in_stock']);

    // A serial that is sold cannot be sold again.
    stSell($scope, 1, ['serials' => ['S1']], ['invoice_no' => 'INV-X2'])->assertUnprocessable();

    // Changing the sale releases what it no longer sells.
    $payload = validSellPayload($scope);
    $payload['selllines'][0] = array_merge($payload['selllines'][0], ['quantity' => 1, 'row_subtotal' => 120, 'serials' => ['S2']]);
    $payload['final_amount'] = 120;
    $this->putJson('/api/sells/'.$sale->id, $payload)->assertSuccessful();
    expect(array_column(stSerials($scope), 'status'))->toBe(['in_stock', 'sold', 'in_stock']);

    $this->deleteJson('/api/sells/'.$sale->id)->assertSuccessful();
    expect(array_column(stSerials($scope), 'status'))->toBe(['in_stock', 'in_stock', 'in_stock']);
});

test('a draft sale needs no serials and holds none', function () {
    $scope = seedSellScope();
    stTrack($scope, 'serial');
    stReceive($scope, 1, ['serials' => ['S1']])->assertSuccessful();

    stSell($scope, 1, [], ['status' => 'draft'])->assertSuccessful();

    expect(stSerials($scope)['S1']['status'])->toBe('in_stock');
});

test('a serial is only sold from the branch it is in', function () {
    $scope = seedSellScope();
    $other = DB::table('branches')->insertGetId(['code' => 'STB002', 'company_id' => $scope['company_id'], 'name' => 'Other', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    stTrack($scope, 'serial');
    stReceive($scope, 1, ['serials' => ['S1']])->assertSuccessful();

    stSell($scope, 1, ['serials' => ['S1']], ['branch_id' => $other])->assertUnprocessable()->assertJsonValidationErrors(['selllines.0.serials']);
});

test('a stock transfer moves the serials: reserved at the source, in the other branch once completed', function () {
    $scope = seedStockTransferScope();
    stTrack($scope, 'serial');
    $this->postJson('/api/stock-tracking/serials', ['company_id' => $scope['company_id'], 'product_id' => $scope['product_id'], 'branch_id' => $scope['branch_id'], 'serials' => "T1\nT2\nT3"])->assertSuccessful()->assertJsonPath('registered', 3);

    $payload = validStockTransferPayload($scope, ['status' => 'pending']);
    $payload['purchaselines'][0]['quantity'] = 2;
    $this->postJson('/api/stocktransfers', $payload)->assertUnprocessable()->assertJsonValidationErrors(['purchaselines.0.serials']);

    $payload['purchaselines'][0]['serials'] = ['T1', 'T2'];
    $this->postJson('/api/stocktransfers', $payload)->assertSuccessful();
    $transfer = Transaction::query()->where('type', 'transfer')->firstOrFail();

    expect(array_column(stSerials($scope), 'status'))->toBe(['in_transit', 'in_transit', 'in_stock']);

    $this->putJson('/api/stocktransfers/'.$transfer->id, array_merge($payload, ['status' => 'completed']))->assertSuccessful();

    $states = stSerials($scope);
    expect($states['T1'])->toBe(['status' => 'in_stock', 'branch' => $scope['tobranch_id']])
        ->and($states['T3'])->toBe(['status' => 'in_stock', 'branch' => $scope['branch_id']]);
});

test('a batch product is received with batches and expiry dates that add up to the received quantity', function () {
    $scope = seedSellScope();
    stTrack($scope, 'batch');

    stReceive($scope, 10)->assertUnprocessable()->assertJsonValidationErrors(['purchaselines.0.batches']);
    stReceive($scope, 10, ['batches' => [['batch_no' => 'B1', 'expiry_date' => '2027-01-31', 'qty' => 4]]])->assertUnprocessable()->assertJsonValidationErrors(['purchaselines.0.batches']);
    expect(stBatches($scope))->toBe([]);

    stReceive($scope, 10, ['batches' => [['batch_no' => 'B1', 'expiry_date' => '2027-01-31', 'qty' => 4], ['batch_no' => 'B2', 'expiry_date' => '2027-06-30', 'qty' => 6]]])->assertSuccessful();

    expect(stBatches($scope))->toBe(['B1' => 4.0, 'B2' => 6.0]);

    // The same batch cannot arrive with another expiry date.
    stReceive($scope, 1, ['batches' => [['batch_no' => 'B1', 'expiry_date' => '2027-02-28', 'qty' => 1]]], 'PO-ST-2')->assertUnprocessable()->assertJsonValidationErrors(['purchaselines.0.batches']);
});

test('a sale draws batches the earliest expiry first, skips expired ones and can be told which batch to use', function () {
    $scope = seedSellScope();
    stTrack($scope, 'batch');
    $b = $scope['branch_id'];
    $this->postJson('/api/stock-tracking/batches', ['company_id' => $scope['company_id'], 'product_id' => $scope['product_id'], 'branch_id' => $b, 'batch_no' => 'LATE', 'expiry_date' => '2027-12-31', 'qty' => 10])->assertSuccessful();
    $this->postJson('/api/stock-tracking/batches', ['company_id' => $scope['company_id'], 'product_id' => $scope['product_id'], 'branch_id' => $b, 'batch_no' => 'EARLY', 'expiry_date' => '2027-03-31', 'qty' => 5])->assertSuccessful();
    $this->postJson('/api/stock-tracking/batches', ['company_id' => $scope['company_id'], 'product_id' => $scope['product_id'], 'branch_id' => $b, 'batch_no' => 'OLD', 'expiry_date' => '2026-01-31', 'qty' => 50])->assertSuccessful();

    // The sale date is 2026-08-26: OLD has expired and is never drawn; 7 units = 5 from EARLY then 2 from LATE.
    stSell($scope, 7)->assertSuccessful();
    expect(stBatches($scope))->toBe(['LATE' => 8.0, 'EARLY' => 0.0, 'OLD' => 50.0]);

    stSell($scope, 9, [], ['invoice_no' => 'INV-X2'])->assertUnprocessable()->assertJsonValidationErrors(['selllines.0.batches']);

    // Naming batches: by id and quantity; an expired one is refused; so is more than it has.
    $ids = DB::table('stock_batches')->pluck('id', 'batch_no');
    stSell($scope, 2, ['batches' => [['batch_id' => $ids['OLD'], 'qty' => 2]]], ['invoice_no' => 'INV-X3'])->assertUnprocessable()->assertJsonValidationErrors(['selllines.0.batches']);
    stSell($scope, 20, ['batches' => [['batch_id' => $ids['LATE'], 'qty' => 20]]], ['invoice_no' => 'INV-X4'])->assertUnprocessable();
    stSell($scope, 3, ['batches' => [['batch_id' => $ids['LATE'], 'qty' => 3]]], ['invoice_no' => 'INV-X5'])->assertSuccessful();
    expect(stBatches($scope)['LATE'])->toBe(5.0);

    // Deleting the first sale gives its batches back.
    $this->deleteJson('/api/sells/'.Transaction::query()->where('type', 'sell')->orderBy('id')->value('id'))->assertSuccessful();
    expect(stBatches($scope)['EARLY'])->toBe(5.0)->and(stBatches($scope)['LATE'])->toBe(7.0);
});

test('a transfer carries batches to the other branch', function () {
    $scope = seedStockTransferScope();
    stTrack($scope, 'batch');
    $this->postJson('/api/stock-tracking/batches', ['company_id' => $scope['company_id'], 'product_id' => $scope['product_id'], 'branch_id' => $scope['branch_id'], 'batch_no' => 'B1', 'expiry_date' => '2027-05-31', 'qty' => 10])->assertSuccessful();

    $payload = validStockTransferPayload($scope, ['status' => 'completed']);
    $payload['purchaselines'][0]['quantity'] = 4;
    $this->postJson('/api/stocktransfers', $payload)->assertSuccessful();

    expect(stBatches($scope, $scope['branch_id']))->toBe(['B1' => 6.0])->and(stBatches($scope, $scope['tobranch_id']))->toBe(['B1' => 4.0]);
});

test('manual entries register, write off and refuse what cannot be done', function () {
    $scope = seedSellScope();
    $body = ['company_id' => $scope['company_id'], 'product_id' => $scope['product_id'], 'branch_id' => $scope['branch_id']];

    $this->postJson('/api/stock-tracking/serials', $body + ['serials' => 'X1'])->assertUnprocessable()->assertJsonValidationErrors(['product_id']);
    $this->postJson('/api/stock-tracking/batches', $body + ['batch_no' => 'B', 'qty' => 1])->assertUnprocessable();

    stTrack($scope, 'serial');
    $this->postJson('/api/stock-tracking/serials', $body + ['serials' => 'X1, X2', 'note' => 'Opening'])->assertSuccessful()->assertJsonPath('registered', 2);
    $this->postJson('/api/stock-tracking/serials', $body + ['serials' => 'X1'])->assertUnprocessable()->assertJsonValidationErrors(['serials']);

    $id = DB::table('stock_serials')->where('serial_no', 'X1')->value('id');
    $this->postJson("/api/stock-tracking/serials/{$id}/write-off", ['company_id' => $scope['company_id'], 'note' => 'Lost'])->assertSuccessful();
    $this->postJson("/api/stock-tracking/serials/{$id}/write-off", ['company_id' => $scope['company_id']])->assertUnprocessable();
    expect(stSerials($scope)['X1']['status'])->toBe('written_off');

    // A written-off serial can be registered again (found).
    $this->postJson('/api/stock-tracking/serials', $body + ['serials' => 'X1'])->assertSuccessful();
    expect(stSerials($scope)['X1']['status'])->toBe('in_stock');

    $this->getJson('/api/stock-tracking/available-serials?'.http_build_query(['company_id' => $scope['company_id'], 'product_id' => $scope['product_id'], 'branch_id' => $scope['branch_id']]))->assertSuccessful()->assertExactJson(['X1', 'X2']);
    $this->getJson('/api/stock-tracking/serials?company_id='.$scope['company_id'].'&status=in_stock')->assertJsonPath('data.total', 2);
});

test('batch quantities can be registered and written off, never below what is there', function () {
    $scope = seedSellScope();
    stTrack($scope, 'batch');
    $body = ['company_id' => $scope['company_id'], 'product_id' => $scope['product_id'], 'branch_id' => $scope['branch_id']];

    $this->postJson('/api/stock-tracking/batches', $body + ['batch_no' => 'B1', 'expiry_date' => '2026-01-01', 'qty' => 5])->assertSuccessful();
    $id = DB::table('stock_batches')->value('id');

    $this->postJson("/api/stock-tracking/batches/{$id}/write-off", ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'qty' => 6])->assertUnprocessable()->assertJsonValidationErrors(['qty']);
    $this->postJson("/api/stock-tracking/batches/{$id}/write-off", ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'qty' => 2, 'note' => 'Damaged'])->assertSuccessful();

    expect(stBatches($scope))->toBe(['B1' => 3.0]);
    $this->getJson('/api/stock-tracking/batches?company_id='.$scope['company_id'])->assertJsonPath('data.0.qty', 3)->assertJsonPath('data.0.expiry_state', 'expired');
});

test('a product with serial or batch records cannot be switched between the two', function () {
    $scope = seedSellScope();
    stTrack($scope, 'serial');
    $this->postJson('/api/stock-tracking/serials', ['company_id' => $scope['company_id'], 'product_id' => $scope['product_id'], 'branch_id' => $scope['branch_id'], 'serials' => 'X1'])->assertSuccessful();
    $product = Product::query()->findOrFail($scope['product_id']);
    $apply = new ReflectionMethod(Product::class, 'applyTrackingType');

    expect(fn () => $apply->invoke($product, (object) ['tracking_type' => 'batch']))->toThrow(ValidationException::class);

    $apply->invoke($product, (object) ['tracking_type' => 'none']);
    expect($product->tracking_type)->toBe('none');

    $apply->invoke($product, (object) []);
    expect($product->tracking_type)->toBe('none');
});

test('another company cannot read or change this company\'s serials', function () {
    $scope = seedSellScope();
    stTrack($scope, 'serial');
    $this->postJson('/api/stock-tracking/serials', ['company_id' => $scope['company_id'], 'product_id' => $scope['product_id'], 'branch_id' => $scope['branch_id'], 'serials' => 'X1'])->assertSuccessful();
    $other = DB::table('companies')->insertGetId(['code' => 'CO-ST-2', 'name' => 'Other', 'is_active' => 1, 'max_users' => 5, 'max_branches' => 1, 'created_at' => now(), 'updated_at' => now()]);

    $this->getJson('/api/stock-tracking/serials?company_id='.$other)->assertJsonPath('data.total', 0);
    $this->postJson('/api/stock-tracking/serials', ['company_id' => $other, 'product_id' => $scope['product_id'], 'branch_id' => $scope['branch_id'], 'serials' => 'Y1'])->assertUnprocessable();
    $id = DB::table('stock_serials')->value('id');
    $this->postJson("/api/stock-tracking/serials/{$id}/write-off", ['company_id' => $other])->assertNotFound();
});

test('the received quantity of a serial product still counts in stock like any other', function () {
    $scope = seedSellScope();
    stTrack($scope, 'serial');
    stReceive($scope, 3, ['serials' => ['S1', 'S2', 'S3']])->assertSuccessful();

    expect(StockMovements::baseStock($scope['product_id'], $scope['variation_id'], $scope['branch_id']))->toBe(3.0)
        ->and(PurchaseLine::query()->where('product_id', $scope['product_id'])->sum('quantity_received'))->toEqual(3);
});
