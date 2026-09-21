<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Product purchase and product sell reports (one row per document line), the particular item purchase
 * and sell reports (per product and contact) and the product sell summary (per product, with stock).
 */

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function plrQuery(array $extra = []): array
{
    return array_merge(['show_record' => 100], $extra);
}

test('a purchase line shows its base units and its amount from rate, quantity and packing', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $product = prdProduct($scope, 'Box of Pens');
    [, $lineId] = prdPurchase($scope, $product, 4, 2.5, '2026-09-10', [], ['packing_qty' => 6, 'discount_percent' => 5]);

    $row = prpRow(prpGet('product-purchase', plrQuery()), $lineId);

    expect($row['product_name'])->toBe('Box of Pens')
        ->and($row['sku'])->toStartWith('SKU-')
        ->and($row['unit_name'])->not->toBe('')
        ->and($row['quantity'])->toEqual(4)
        ->and($row['base_quantity'])->toEqual(24)
        ->and($row['unit_price'])->toEqual(2.5)
        ->and($row['discount_percent'])->toEqual(5)
        ->and($row['amount'])->toEqual(60)
        ->and($row['net_amount'])->toEqual(60)
        ->and($row['transaction_date'])->toBe('2026-09-10')
        ->and($row['contact_name'])->toBe('Farm Supplies 1');
});

test('a sell line shows its stored subtotal and what was returned comes off', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $product = prdProduct($scope, 'Widget');
    [, $lineId] = prdSale($scope, $product, 10, 50, '2026-09-10', [], ['quantity_returned' => 4]);

    $row = prpRow(prpGet('product-sell', plrQuery()), $lineId);

    expect($row['amount'])->toEqual(500)
        ->and($row['returned_quantity'])->toEqual(4)
        ->and($row['net_quantity'])->toEqual(6)
        ->and($row['net_amount'])->toEqual(300)
        ->and($row['contact_name'])->toBe('Acme Retail 1');
});

test('lines count like their document, not the old received or issue only rule', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $product = prdProduct($scope, 'Statuses');

    $purchaseIds = [];
    foreach (['draft' => false, 'pending' => true, 'approved' => true, 'received' => true] as $status => $counts) {
        [, $line] = prdPurchase($scope, $product, 1, 10, '2026-09-10', ['status' => $status]);
        $purchaseIds[$status] = $line;
    }
    [, $deletedLine] = prdPurchase($scope, $product, 1, 10, '2026-09-10', ['deleted_at' => now()]);

    $sellIds = [];
    foreach (['draft' => false, 'quotation' => false, 'final' => true, 'approved' => true, 'issue' => true] as $status => $counts) {
        [, $line] = prdSale($scope, $product, 1, 10, '2026-09-10', ['status' => $status]);
        $sellIds[$status] = $line;
    }
    prdPurchase($scope, $product, 1, 10, '2026-09-10', ['type' => 'sell']);

    $purchases = prpRows(prpGet('product-purchase', plrQuery()))->pluck('id')->sort()->values()->all();
    $sells = prpRows(prpGet('product-sell', plrQuery()))->pluck('id')->all();

    expect($purchases)->toBe(collect([$purchaseIds['pending'], $purchaseIds['approved'], $purchaseIds['received']])->sort()->values()->all())
        ->and($purchases)->not->toContain($deletedLine)
        ->and(collect($sells)->intersect([$sellIds['draft'], $sellIds['quotation']])->all())->toBe([])
        ->and(collect($sells)->intersect([$sellIds['final'], $sellIds['approved'], $sellIds['issue']])->count())->toBe(3);
});

test('the date range is inclusive at both ends', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $product = prdProduct($scope, 'Dated');
    $ids = [];
    foreach (['2026-09-04 23:59:59', '2026-09-05 00:00:00', '2026-09-06 23:59:59', '2026-09-07 00:00:00'] as $date) {
        [, $ids[]] = prdSale($scope, $product, 1, 10, $date);
    }

    $inRange = prpRows(prpGet('product-sell', plrQuery(['start_date' => '2026-09-05', 'end_date' => '2026-09-06'])))->pluck('id')->sort()->values()->all();

    expect($inRange)->toBe([$ids[1], $ids[2]]);
});

test('lines filter by product, brand, category, item type, contact and search', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $brandId = DB::table('brands')->insertGetId(['company_id' => $scope['company_id'], 'name' => 'Acme Brand', 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $categoryId = DB::table('categories')->insertGetId(['company_id' => $scope['company_id'], 'name' => 'Stationery', 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $typeId = DB::table('item_types')->insertGetId(['company_id' => $scope['company_id'], 'name' => 'Goods', 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    $pen = prdProduct($scope, 'Pen', ['brand_id' => $brandId, 'category_id' => $categoryId, 'itemtype_id' => $typeId], ['sku' => 'PEN-1']);
    $ink = prdProduct($scope, 'Ink');
    $other = prpContact($scope, 'customer', 'Other Customer');

    [, $penLine] = prdSale($scope, $pen, 1, 10, '2026-09-10', ['invoice_no' => 'INV-PEN']);
    [, $inkLine] = prdSale($scope, $ink, 1, 10, '2026-09-10', ['contact_id' => $other, 'invoice_no' => 'INV-INK']);

    $ids = fn (array $extra): array => prpRows(prpGet('product-sell', plrQuery($extra)))->pluck('id')->sort()->values()->all();

    expect($ids(['product_id' => $ink['product_id']]))->toBe([$inkLine])
        ->and($ids(['brand_id' => $brandId]))->toBe([$penLine])
        ->and($ids(['category_id' => $categoryId]))->toBe([$penLine])
        ->and($ids(['itemtype_id' => $typeId]))->toBe([$penLine])
        ->and($ids(['contact_id' => $other]))->toBe([$inkLine])
        ->and($ids(['search' => 'PEN-1']))->toBe([$penLine])
        ->and($ids(['search' => 'inv-ink']))->toBe([$inkLine])
        ->and($ids(['search' => 'other customer']))->toBe([$inkLine])
        ->and(prpRow(prpGet('product-sell', plrQuery()), $penLine)['brand_name'])->toBe('Acme Brand');
});

test('the summary totals the whole filtered set, not the page on screen', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $product = prdProduct($scope, 'Totals');
    prdSale($scope, $product, 10, 5, '2026-09-01', [], ['quantity_returned' => 2, 'packing_qty' => 2]);
    prdSale($scope, $product, 3, 100, '2026-09-02');

    $response = prpGet('product-sell', plrQuery(['show_record' => 1]));

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('summary.count'))->toBe(2)
        ->and($response->json('summary.quantity'))->toEqual(23)
        ->and($response->json('summary.net_quantity'))->toEqual(19)
        ->and($response->json('summary.amount'))->toEqual(400)
        ->and($response->json('summary.net_amount'))->toEqual(380);
});

test('rows sort by a known column and fall back to the newest first', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $product = prdProduct($scope, 'Sorted');
    [, $older] = prdSale($scope, $product, 1, 10, '2026-09-01');
    [, $newer] = prdSale($scope, $product, 1, 99, '2026-09-09');

    $order = fn (array $extra): array => prpRows(prpGet('product-sell', plrQuery($extra)))->pluck('id')->all();

    expect($order([]))->toBe([$newer, $older])
        ->and($order(['sort_by' => 'amount', 'sort_type' => 'asc']))->toBe([$older, $newer])
        ->and($order(['sort_by' => 'x; drop table users']))->toBe([$newer, $older]);
});

test('a company user sees only their own lines and a branch user only their branch', function () {
    $mine = trpScope('1');
    $theirs = trpScope('2');
    $otherBranch = trpBranch($mine['company_id'], 'Second Branch');

    $product = prdProduct($mine, 'Mine');
    $foreign = prdProduct($theirs, 'Theirs');
    [, $own] = prdSale($mine, $product, 1, 10, '2026-09-10');
    [, $elsewhere] = prdSale($mine, $product, 1, 10, '2026-09-10', ['branch_id' => $otherBranch]);
    prdSale($theirs, $foreign, 1, 10, '2026-09-10');

    Sanctum::actingAs(jeaUserWith($mine, ['/report/product-sell']));

    expect(prpRows(prpGet('product-sell', plrQuery(['company_id' => $theirs['company_id']])))->pluck('id')->all())->toBe([$own]);

    prpGet('product-sell', plrQuery(['search' => "' OR 1=1 --"]))->assertSuccessful()->assertJsonPath('summary.count', 0);

    trpActAsSuperadmin();

    expect(prpRows(prpGet('product-sell', plrQuery(['company_id' => $mine['company_id']])))->pluck('id')->sort()->values()->all())->toBe(collect([$own, $elsewhere])->sort()->values()->all());
});

test('the item reports group by product and contact in the base unit, net of returns', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $product = prdProduct($scope, 'Grouped');
    $otherSupplier = prpContact($scope, 'supplier', 'Second Supplier');
    $otherCustomer = prpContact($scope, 'customer', 'Second Customer');

    prdPurchase($scope, $product, 10, 2, '2026-09-01', [], ['packing_qty' => 3]);
    prdPurchase($scope, $product, 5, 4, '2026-09-02', [], ['quantity_returned' => 1]);
    prdPurchase($scope, $product, 7, 1, '2026-09-03', ['contact_id' => $otherSupplier]);
    prdSale($scope, $product, 4, 10, '2026-09-01');
    prdSale($scope, $product, 2, 10, '2026-09-02', [], ['quantity_returned' => 1]);
    prdSale($scope, $product, 3, 20, '2026-09-03', ['contact_id' => $otherCustomer]);

    $purchases = prpRows(prpGet('item-purchase', plrQuery()));
    $sells = prpRows(prpGet('item-sell', plrQuery()));

    $mainSupplier = $purchases->firstWhere('contact_name', 'Farm Supplies 1');
    $mainCustomer = $sells->firstWhere('contact_name', 'Acme Retail 1');

    expect($purchases)->toHaveCount(2)
        ->and($mainSupplier['invoices'])->toBe(2)
        // 10 x 3 = 30 base units at 2 = 60, then 5 - 1 = 4 units at 4 = 16
        ->and($mainSupplier['quantity'])->toEqual(34)
        ->and($mainSupplier['amount'])->toEqual(76)
        ->and($purchases->firstWhere('contact_name', 'Second Supplier')['quantity'])->toEqual(7)
        ->and($sells)->toHaveCount(2)
        ->and($mainCustomer['quantity'])->toEqual(5)
        ->and($mainCustomer['amount'])->toEqual(50)
        ->and($sells->firstWhere('contact_name', 'Second Customer')['amount'])->toEqual(60)
        ->and(prpGet('item-sell', plrQuery())->json('summary'))->toMatchArray(['count' => 2]);
});

test('the sell summary carries sales and the stock left on the last day of the range', function () {
    $scope = trpScope();
    trpActAsSuperadmin();

    $product = prdProduct($scope, 'Stocked');
    $unsold = prdProduct($scope, 'Never Sold');
    prdPurchase($scope, $product, 100, 5, '2026-08-01');
    prdPurchase($scope, $unsold, 50, 5, '2026-08-01');
    prdSale($scope, $product, 30, 10, '2026-09-05', [], ['quantity_returned' => 5]);
    prdSale($scope, $product, 10, 10, '2026-09-20');

    $row = prpRows(prpGet('product-sell-summary', plrQuery()))->firstWhere('product_id', $product['product_id']);
    $early = prpRows(prpGet('product-sell-summary', plrQuery(['end_date' => '2026-09-10'])))->firstWhere('product_id', $product['product_id']);

    expect($row['quantity'])->toEqual(40)
        ->and($row['returned_quantity'])->toEqual(5)
        ->and($row['net_quantity'])->toEqual(35)
        ->and($row['net_amount'])->toEqual(350)
        ->and($row['current_stock'])->toEqual(65)
        // by the 10th only the first sale had happened: 100 - (30 - 5)
        ->and($early['net_quantity'])->toEqual(25)
        ->and($early['current_stock'])->toEqual(75)
        ->and(prpRows(prpGet('product-sell-summary', plrQuery()))->pluck('product_id')->all())->not->toContain($unsold['product_id']);
});
