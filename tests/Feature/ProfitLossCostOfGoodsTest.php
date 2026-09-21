<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * The cost of goods sold of the profit and loss: opening stock + purchases - closing stock, with stock
 * valued at the weighted average purchase cost on the day (the Stock Report's value). Purchases are the
 * 6xx postings of the period.
 */

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function plcQuery(array $extra = []): array
{
    return array_merge(['show_record' => 100, 'start_date' => '2026-07-01', 'end_date' => '2026-07-31'], $extra);
}

/**
 * A company with the accounts a trading business posts to, and no stock yet.
 *
 * @return array<string, int>
 */
function plcScope(): array
{
    $scope = trpScope();
    prpFinancialYear($scope['company_id']);

    foreach ([['212-00001', 'Cash in Hand', 'dr'], ['311-00001', 'Trade Creditor', 'cr'], ['511-00001', 'Local Sales', 'cr'], ['611-00001', 'Local Purchases', 'dr'], ['441-00001', 'General Expense', 'dr']] as [$code, $name, $nature]) {
        ldgAccount($scope, $code, $name, 't', $nature);
    }

    return $scope;
}

/**
 * A purchase of stock: the document (which is what stock and cost are read from) and the ledger posting
 * (which is what the profit and loss counts as purchases).
 *
 * @param  array<string, int>  $scope
 * @param  array{product_id: int, variation_id: int, unit_id: int}  $product
 */
function plcPurchase(array $scope, array $product, float $quantity, float $rate, string $date): void
{
    prdPurchase($scope, $product, $quantity, $rate, $date);
    ldgVoucher($scope, 'PE-'.uniqid(), $date, [['611-00001', $quantity * $rate, 0], ['311-00001', 0, $quantity * $rate]]);
}

/**
 * A sale of stock and its revenue posting.
 *
 * @param  array<string, int>  $scope
 * @param  array{product_id: int, variation_id: int, unit_id: int}  $product
 */
function plcSale(array $scope, array $product, float $quantity, float $price, string $date): void
{
    prdSale($scope, $product, $quantity, $price, $date);
    ldgVoucher($scope, 'SE-'.uniqid(), $date, [['212-00001', $quantity * $price, 0], ['511-00001', 0, $quantity * $price]]);
}

function plcStatement(int $companyId, array $extra = []): TestResponse
{
    return prpGet('profit-loss', plcQuery(['company_id' => $companyId] + $extra));
}

/**
 * The amount of a stock or subtotal line of the cost of goods sold section, by its label.
 */
function plcLine(TestResponse $response, string $name): ?float
{
    $row = prpRows($response)->firstWhere('name', $name);

    return $row === null ? null : (float) $row['amount'];
}

test('cost of goods sold is opening stock plus purchases less closing stock', function () {
    $scope = plcScope();
    trpActAsSuperadmin();

    $product = prdProduct($scope, 'Traded Goods');
    plcPurchase($scope, $product, 10, 10, '2026-06-15');
    plcPurchase($scope, $product, 10, 20, '2026-07-10');
    plcSale($scope, $product, 8, 50, '2026-07-20');
    ldgVoucher($scope, 'EX-00001', '2026-07-25', [['441-00001', 50, 0], ['212-00001', 0, 50]]);

    $response = plcStatement($scope['company_id']);

    // Opening: 10 units on 30 June at 10 = 100. Closing: 10 + 10 - 8 = 12 units on 31 July at (100 + 200) / 20 = 15 = 180.
    expect(plcLine($response, 'Opening stock'))->toBe(100.0)
        ->and(plcLine($response, 'Local Purchases'))->toBe(200.0)
        ->and(plcLine($response, 'Add: purchases'))->toBe(200.0)
        ->and(plcLine($response, 'Less: closing stock'))->toBe(-180.0)
        ->and(plcLine($response, 'Total cost of goods sold'))->toBe(120.0)
        ->and(plcLine($response, 'Total revenue'))->toBe(400.0)
        ->and(plcLine($response, 'Gross profit'))->toBe(280.0)
        ->and(plcLine($response, 'Total expenses'))->toBe(50.0)
        ->and(plcLine($response, 'Net profit'))->toBe(230.0)
        ->and($response->json('summary'))->toMatchArray(['uncosted_stock' => 0, 'from' => '2026-07-01', 'to' => '2026-07-31'])
        ->and($response->json('summary.opening_stock'))->toEqual(100)
        ->and($response->json('summary.purchases'))->toEqual(200)
        ->and($response->json('summary.closing_stock'))->toEqual(180)
        ->and($response->json('summary.cogs'))->toEqual(120)
        ->and($response->json('summary.gross_profit'))->toEqual(280)
        ->and($response->json('summary.net_profit'))->toEqual(230);
});

test('the cost of goods sold section reads in the order opening, purchases, closing, total', function () {
    $scope = plcScope();
    trpActAsSuperadmin();

    $product = prdProduct($scope, 'Ordered');
    plcPurchase($scope, $product, 10, 10, '2026-06-15');
    plcPurchase($scope, $product, 5, 10, '2026-07-10');

    $section = prpRows(plcStatement($scope['company_id']))->where('section', 'cogs')->map(fn (array $row): string => $row['line'].': '.$row['name'])->values()->all();

    expect($section)->toBe([
        'header: Cost of goods sold',
        'stock: Opening stock',
        'account: Local Purchases',
        'subtotal: Add: purchases',
        'stock: Less: closing stock',
        'total: Total cost of goods sold',
    ]);
});

test('a product with no opening stock works and only its purchases and closing stock count', function () {
    $scope = plcScope();
    trpActAsSuperadmin();

    $product = prdProduct($scope, 'Brand New');
    plcPurchase($scope, $product, 10, 20, '2026-07-10');
    plcSale($scope, $product, 4, 50, '2026-07-20');

    $response = plcStatement($scope['company_id']);

    // Nothing before July: opening 0. Closing 6 units at 20 = 120, so 4 units cost 200 - 120 = 80.
    expect(plcLine($response, 'Opening stock'))->toBe(0.0)
        ->and(plcLine($response, 'Less: closing stock'))->toBe(-120.0)
        ->and(plcLine($response, 'Total cost of goods sold'))->toBe(80.0)
        ->and(plcLine($response, 'Gross profit'))->toBe(120.0);
});

test('stock that is still on the shelf is not a cost, which the purchases alone would have made it', function () {
    $scope = plcScope();
    trpActAsSuperadmin();

    $product = prdProduct($scope, 'Building Stock');
    plcPurchase($scope, $product, 10, 20, '2026-07-10');
    ldgVoucher($scope, 'EX-00001', '2026-07-12', [['441-00001', 30, 0], ['212-00001', 0, 30]]);

    $response = plcStatement($scope['company_id']);

    // 200 purchased and all of it unsold: cost of goods sold 0, not 200, so the loss is only the 30 of expense.
    expect(plcLine($response, 'Add: purchases'))->toBe(200.0)
        ->and(plcLine($response, 'Total cost of goods sold'))->toBe(0.0)
        ->and(plcLine($response, 'Net loss'))->toBe(-30.0)
        ->and($response->json('summary.net_profit'))->toEqual(-30);
});

test('a business with no stock and no purchases has no cost of goods sold', function () {
    $scope = plcScope();
    trpActAsSuperadmin();

    ldgVoucher($scope, 'SE-00001', '2026-07-05', [['212-00001', 100, 0], ['511-00001', 0, 100]]);

    $response = plcStatement($scope['company_id']);

    expect(plcLine($response, 'Opening stock'))->toBe(0.0)
        ->and(plcLine($response, 'Less: closing stock'))->toBe(0.0)
        ->and(plcLine($response, 'Total cost of goods sold'))->toBe(0.0)
        ->and(plcLine($response, 'Gross profit'))->toBe(100.0);
});

test('the opening stock is the stock at the end of the day before the period and closing includes the last day', function () {
    $scope = plcScope();
    trpActAsSuperadmin();

    $product = prdProduct($scope, 'Boundary');
    plcPurchase($scope, $product, 5, 10, '2026-07-09 23:59:59');
    plcPurchase($scope, $product, 5, 10, '2026-07-10 00:00:00');
    plcPurchase($scope, $product, 5, 10, '2026-07-31 23:59:59');
    plcPurchase($scope, $product, 5, 10, '2026-08-01 00:00:00');

    $response = plcStatement($scope['company_id'], ['start_date' => '2026-07-10', 'end_date' => '2026-07-31']);

    // Opening: only the purchase of the 9th (5 x 10). Closing: the 9th, 10th and 31st (15 x 10); the 1st of August is later.
    expect(plcLine($response, 'Opening stock'))->toBe(50.0)
        ->and(plcLine($response, 'Less: closing stock'))->toBe(-150.0)
        ->and(plcLine($response, 'Add: purchases'))->toBe(100.0)
        ->and(plcLine($response, 'Total cost of goods sold'))->toBe(0.0);
});

test('the closing stock is the total value of the stock report for that day and the opening is its value the day before', function () {
    $scope = plcScope();
    trpActAsSuperadmin();

    $first = prdProduct($scope, 'First Product');
    $second = prdProduct($scope, 'Second Product');
    plcPurchase($scope, $first, 10, 10, '2026-06-15');
    plcPurchase($scope, $second, 4, 25, '2026-06-20');
    plcPurchase($scope, $first, 10, 20, '2026-07-10');
    plcSale($scope, $first, 8, 50, '2026-07-20');
    plcSale($scope, $second, 1, 60, '2026-07-21');

    $response = plcStatement($scope['company_id']);
    $closing = prpGet('stock', ['company_id' => $scope['company_id'], 'end_date' => '2026-07-31', 'show_record' => 100]);
    $opening = prpGet('stock', ['company_id' => $scope['company_id'], 'end_date' => '2026-06-30', 'show_record' => 100]);

    expect($response->json('summary.closing_stock'))->toEqual($closing->json('summary.stock_value'))
        ->and($response->json('summary.opening_stock'))->toEqual($opening->json('summary.stock_value'))
        ->and($response->json('summary.opening_stock'))->toEqual(200)
        ->and($response->json('summary.closing_stock'))->toEqual(255);
});

test('a product with stock but no purchase has no cost and is counted, not valued at zero silently', function () {
    $scope = plcScope();
    trpActAsSuperadmin();

    $costed = prdProduct($scope, 'Costed');
    $uncosted = prdProduct($scope, 'Uncosted');
    plcPurchase($scope, $costed, 10, 10, '2026-07-05');
    $adjustment = trpDoc($scope, 'adjustment', ['status' => 'completed', 'transaction_date' => '2026-07-06', 'contact_id' => null]);
    prdPurchaseLine($adjustment, $uncosted, 0, 0, ['quantity_received' => 0, 'quantity_adjustment' => 7]);

    $response = plcStatement($scope['company_id']);

    expect(plcLine($response, 'Less: closing stock'))->toBe(-100.0)
        ->and($response->json('summary.uncosted_stock'))->toBe(1);
});

test('stock that is oversold is valued negative like the stock report does', function () {
    $scope = plcScope();
    trpActAsSuperadmin();

    $product = prdProduct($scope, 'Oversold');
    plcPurchase($scope, $product, 5, 10, '2026-07-05');
    plcSale($scope, $product, 8, 30, '2026-07-10');

    $response = plcStatement($scope['company_id']);
    $stock = prpRows(prpGet('stock', ['company_id' => $scope['company_id'], 'end_date' => '2026-07-31', 'show_record' => 100]))->first();

    // 5 - 8 = -3 units at 10.
    expect($stock['current_stock'])->toEqual(-3)
        ->and(plcLine($response, 'Less: closing stock'))->toBe(30.0)
        ->and(plcLine($response, 'Total cost of goods sold'))->toBe(80.0);
});

test('the branch filter values the stock of that branch and another company\'s stock never counts', function () {
    $scope = plcScope();
    $otherBranch = trpBranch($scope['company_id'], 'Second Branch');
    $stranger = trpScope('2');
    trpActAsSuperadmin();

    $product = prdProduct($scope, 'Two Branches');
    plcPurchase($scope, $product, 10, 10, '2026-07-05');
    plcPurchase(array_merge($scope, ['branch_id' => $otherBranch]), $product, 10, 30, '2026-07-06');
    prdPurchase($stranger, prdProduct($stranger, 'Foreign Stock'), 100, 100, '2026-07-05');

    $all = plcStatement($scope['company_id']);
    $branch = plcStatement($scope['company_id'], ['branch_id' => $otherBranch]);
    $branchStock = prpGet('stock', ['company_id' => $scope['company_id'], 'branch_id' => $otherBranch, 'end_date' => '2026-07-31', 'show_record' => 100]);

    // Company-wide average cost (10 x 10 + 10 x 30) / 20 = 20; 20 units = 400, 10 in the branch = 200.
    expect($all->json('summary.closing_stock'))->toEqual(400)
        ->and($branch->json('summary.closing_stock'))->toEqual(200)
        ->and($branch->json('summary.closing_stock'))->toEqual($branchStock->json('summary.stock_value'));
});

test('a deleted product is not stock and the cost is per company', function () {
    $scope = plcScope();
    trpActAsSuperadmin();

    $kept = prdProduct($scope, 'Kept');
    $gone = prdProduct($scope, 'Deleted');
    plcPurchase($scope, $kept, 10, 10, '2026-07-05');
    plcPurchase($scope, $gone, 10, 500, '2026-07-05');
    DB::table('products')->where('id', $gone['product_id'])->update(['deleted_at' => now()]);

    expect(plcLine(plcStatement($scope['company_id']), 'Less: closing stock'))->toBe(-100.0);
});
