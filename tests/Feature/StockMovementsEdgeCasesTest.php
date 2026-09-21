<?php

use App\Models\Product;
use App\Models\ProductDetail;
use App\Models\PurchaseLine;
use App\Models\Transaction;
use App\Services\StockMovements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Edge cases of the one definition of stock (StockMovements) that the day-to-day sale tests do not reach:
 * odd packing values, deleted and pending documents, the as-of date, transfers between branches, partial
 * receipts and the kinds breakdown. Each test builds the documents it needs straight in the database.
 */
function smqScope(): array
{
    return seedPurchaseScope();
}

/**
 * A purchase order line for the scope's product.
 *
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $line
 * @param  array<string, mixed>  $document
 */
function smqPurchase(array $scope, array $line = [], array $document = []): Transaction
{
    static $number = 0;
    $number++;

    $purchase = Transaction::query()->create(array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'contact_id' => $scope['contact_id'],
        'invoice_no' => 'SMQ-PO-'.$number,
        'type' => Transaction::TYPE_PURCHASE,
        'status' => 'received',
        'payment_status' => 'due',
        'transaction_date' => '2026-09-10',
        'final_amount' => 100,
        'total_item' => 1,
    ], $document));

    PurchaseLine::query()->create(array_merge([
        'transaction_id' => $purchase->id,
        'product_id' => $scope['product_id'],
        'variation_id' => $scope['variation_id'],
        'itemtype_id' => $scope['itemtype_id'],
        'unit_id' => $scope['unit_id'],
        'quantity' => 10,
        'quantity_received' => 10,
        'purchase_rate' => 10,
        'pp_without_discount' => 10,
        'default_sell_price' => 12,
        'packing_qty' => 1,
    ], $line));

    return $purchase;
}

/**
 * A completed or pending stock adjustment / transfer document with one line.
 *
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $document
 */
function smqDocument(array $scope, string $type, float $quantity, array $document = [], array $line = []): Transaction
{
    static $number = 0;
    $number++;

    $transaction = Transaction::query()->create(array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'invoice_no' => 'SMQ-DOC-'.$number,
        'type' => $type,
        'status' => 'completed',
        'transaction_date' => '2026-09-10',
        'total_item' => 1,
    ], $document));

    PurchaseLine::query()->create(array_merge([
        'transaction_id' => $transaction->id,
        'product_id' => $scope['product_id'],
        'variation_id' => $scope['variation_id'],
        'itemtype_id' => $scope['itemtype_id'],
        'unit_id' => $scope['unit_id'],
        'quantity' => $type === Transaction::TYPE_TRANSFER ? $quantity : 0,
        'quantity_adjustment' => $type === Transaction::TYPE_ADJUSTMENT ? $quantity : 0,
        'packing_qty' => 1,
    ], $line));

    return $transaction;
}

/**
 * A second product of the company with received stock in the scope's branch.
 *
 * @param  array<string, mixed>  $scope
 * @return array{product_id: int, variation_id: int}
 */
function smqOtherProduct(array $scope, float $stock): array
{
    $product = Product::query()->create([
        'company_id' => $scope['company_id'], 'unit_id' => $scope['unit_id'], 'itemtype_id' => $scope['itemtype_id'],
        'name' => 'Something Else', 'sku' => 'SMQ-OTHER', 'type' => 'single', 'active' => true,
    ]);
    $detail = ProductDetail::query()->create([
        'product_id' => $product->id, 'name' => 'Something Else', 'sku' => 'SMQ-OTHER-1', 'variation_name' => 'dummy',
        'default_purchase_price' => 1, 'dpp_unit_price' => 1, 'largequantity' => 1, 'smallquantity' => 1, 'profit_percent' => 1, 'default_sell_price' => 2,
    ]);

    smqPurchase(array_merge($scope, ['product_id' => $product->id, 'variation_id' => $detail->id]), ['quantity_received' => $stock]);

    return ['product_id' => $product->id, 'variation_id' => $detail->id];
}

function smqStock(array $scope, ?int $branchId = null, array $filters = []): float
{
    return StockMovements::baseStock($scope['product_id'], $scope['variation_id'], $branchId ?? $scope['branch_id']);
}

test('a packing quantity of zero, negative or below one counts as one', function (mixed $packing) {
    $scope = smqScope();
    smqPurchase($scope, ['quantity_received' => 4, 'packing_qty' => $packing]);

    expect(smqStock($scope))->toBe(4.0);
})->with([[0], [-3], [0.5]]);

test('a packing quantity multiplies the received quantity into base units', function () {
    $scope = smqScope();
    smqPurchase($scope, ['quantity_received' => 3, 'packing_qty' => 12]);

    expect(smqStock($scope))->toBe(36.0);
});

test('a deleted document is not stock: purchase, transfer and adjustment', function () {
    $scope = smqScope();
    $otherBranch = trpBranch($scope['company_id'], 'Second Branch');
    $purchase = smqPurchase($scope, ['quantity_received' => 10]);
    $adjustment = smqDocument($scope, Transaction::TYPE_ADJUSTMENT, 5);
    $transfer = smqDocument($scope, Transaction::TYPE_TRANSFER, 3, ['tobranch_id' => $otherBranch]);

    expect(smqStock($scope))->toBe(12.0)
        ->and(smqStock($scope, $otherBranch))->toBe(3.0);

    $purchase->delete();
    expect(smqStock($scope))->toBe(2.0);

    $adjustment->delete();
    expect(smqStock($scope))->toBe(-3.0);

    $transfer->delete();
    expect(smqStock($scope))->toBe(0.0)
        ->and(smqStock($scope, $otherBranch))->toBe(0.0);
});

test('only completed adjustments and transfers move stock; a pending one waits', function () {
    $scope = smqScope();
    $otherBranch = trpBranch($scope['company_id'], 'Second Branch');
    smqPurchase($scope, ['quantity_received' => 10]);
    $adjustment = smqDocument($scope, Transaction::TYPE_ADJUSTMENT, -4, ['status' => 'pending']);
    $transfer = smqDocument($scope, Transaction::TYPE_TRANSFER, 6, ['status' => 'pending', 'tobranch_id' => $otherBranch]);

    expect(smqStock($scope))->toBe(10.0)
        ->and(smqStock($scope, $otherBranch))->toBe(0.0);

    $adjustment->update(['status' => 'completed']);
    $transfer->update(['status' => 'completed']);

    expect(smqStock($scope))->toBe(0.0)
        ->and(smqStock($scope, $otherBranch))->toBe(6.0);
});

test('a transfer leaves one branch and arrives in the other, so the company total is unchanged', function () {
    $scope = smqScope();
    $otherBranch = trpBranch($scope['company_id'], 'Second Branch');
    smqPurchase($scope, ['quantity_received' => 20]);
    smqDocument($scope, Transaction::TYPE_TRANSFER, 7.5, ['tobranch_id' => $otherBranch]);

    expect(smqStock($scope))->toBe(12.5)
        ->and(smqStock($scope, $otherBranch))->toBe(7.5)
        ->and(StockMovements::baseStock($scope['product_id'], $scope['variation_id']))->toBe(20.0);
});

test('a purchase counts what was received, not what was ordered, and returns come back out', function () {
    $scope = smqScope();
    smqPurchase($scope, ['quantity' => 100, 'quantity_received' => 30, 'quantity_returned' => 5]);

    expect(smqStock($scope))->toBe(25.0);
});

test('fractional quantities add up exactly to six decimals', function () {
    $scope = smqScope();
    smqPurchase($scope, ['quantity_received' => 0.1]);
    smqPurchase($scope, ['quantity_received' => 0.2]);
    smqPurchase($scope, ['quantity_received' => 0.3]);

    expect(smqStock($scope))->toBe(0.6);
});

test('stock can go negative and is reported as it is', function () {
    $scope = smqScope();
    smqPurchase($scope, ['quantity_received' => 2]);
    smqDocument($scope, Transaction::TYPE_ADJUSTMENT, -5);

    expect(smqStock($scope))->toBe(-3.0);
});

test('the as-of day keeps only documents dated on or before it', function () {
    $scope = smqScope();
    smqPurchase($scope, ['quantity_received' => 10], ['transaction_date' => '2026-09-01']);
    smqPurchase($scope, ['quantity_received' => 4], ['transaction_date' => '2026-09-10']);
    smqDocument($scope, Transaction::TYPE_ADJUSTMENT, -1, ['transaction_date' => '2026-09-20']);

    $asOf = fn (string $day): float => (float) round(DB::query()->fromSub(StockMovements::query([
        'product_id' => $scope['product_id'], 'variation_id' => $scope['variation_id'], 'branch_id' => $scope['branch_id'], 'as_of' => $day,
    ]), 'm')->sum('m.qty'), 6);

    expect($asOf('2026-08-31'))->toBe(0.0)
        ->and($asOf('2026-09-01'))->toBe(10.0)
        ->and($asOf('2026-09-09'))->toBe(10.0)
        ->and($asOf('2026-09-10'))->toBe(14.0)
        ->and($asOf('2026-09-19'))->toBe(14.0)
        ->and($asOf('2026-09-20'))->toBe(13.0);
});

test('another product or variation is never mixed in', function () {
    $scope = smqScope();
    smqPurchase($scope, ['quantity_received' => 10]);
    $otherProduct = smqOtherProduct($scope, 99);

    expect(smqStock($scope))->toBe(10.0)
        ->and(StockMovements::baseStock($otherProduct['product_id'], $otherProduct['variation_id'], $scope['branch_id']))->toBe(99.0)
        ->and(StockMovements::baseStock($scope['product_id'], $otherProduct['variation_id'], $scope['branch_id']))->toBe(0.0);
});

test('the kinds breakdown always adds up to the plain quantity', function () {
    $scope = smqScope();
    $otherBranch = trpBranch($scope['company_id'], 'Second Branch');
    smqPurchase($scope, ['quantity_received' => 20, 'quantity_returned' => 3, 'packing_qty' => 2]);
    smqDocument($scope, Transaction::TYPE_ADJUSTMENT, -4);
    smqDocument($scope, Transaction::TYPE_TRANSFER, 5, ['tobranch_id' => $otherBranch]);
    $sale = trpDoc(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'customer_id' => $scope['contact_id'], 'supplier_id' => $scope['contact_id']], 'sell', ['status' => 'final']);
    prdSellLine($sale, $scope, 6, 5, ['quantity_returned' => 2]);

    foreach ([$scope['branch_id'], $otherBranch] as $branchId) {
        $filters = ['product_id' => $scope['product_id'], 'variation_id' => $scope['variation_id'], 'branch_id' => $branchId];

        $plain = round((float) DB::query()->fromSub(StockMovements::query($filters), 'm')->sum('m.qty'), 6);
        $broken = DB::query()->fromSub(StockMovements::query($filters + ['with_kinds' => true]), 'm')->selectRaw('m.kind, sum(m.qty) as qty')->groupBy('m.kind')->pluck('qty', 'kind');

        expect(round((float) $broken->sum(), 6))->toBe($plain);
    }

    // 40 received (20 x packing 2) - 6 returned to the supplier - 4 adjusted - 5 transferred - 4 net sold
    expect(smqStock($scope))->toBe(21.0);
});

test('a sale return of more than was sold is not clamped: the stock follows the numbers on the lines', function () {
    $scope = smqScope();
    smqPurchase($scope, ['quantity_received' => 10]);
    $sale = trpDoc(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'customer_id' => $scope['contact_id'], 'supplier_id' => $scope['contact_id']], 'sell', ['status' => 'final']);
    prdSellLine($sale, $scope, 2, 5, ['quantity_returned' => 3]);

    expect(smqStock($scope))->toBe(11.0);
});
