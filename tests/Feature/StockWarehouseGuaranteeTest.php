<?php

use App\Models\Transaction;
use App\Services\Reports\StockValuation;
use App\Services\StockMovements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The guarantee behind the warehouse layer: naming a warehouse on stock documents must never change a branch-level
 * stock figure. The same books (stkLife: purchase, purchase return, sale, sale return, a transfer between two branches
 * and an adjustment) are measured untagged, then every document is tagged with a warehouse of its branch, and every
 * number that reads stock must come out identical: the movement rows, the stock of each branch, the Stock Report (all
 * branches and per branch) and the stock valuation behind the Profit & Loss and the Balance Sheet.
 */

/**
 * @param  array{scope: array<string, int>, other_branch: int, product: array<string, int>}  $life
 * @return array<string, mixed>
 */
function wgSnapshot(array $life): array
{
    $company = $life['scope']['company_id'];
    $product = $life['product'];
    $branchA = $life['scope']['branch_id'];
    $branchB = $life['other_branch'];

    $movements = StockMovements::query(['with_kinds' => true])->get()
        ->groupBy(fn (object $row): string => $row->branch_id.'|'.$row->kind)
        ->map(fn ($rows): float => round((float) $rows->sum('qty'), 6))
        ->sortKeys()
        ->all();

    $report = fn (array $query) => prpGet('stock', stkQuery($query));

    return [
        'movements' => $movements,
        'stock' => [
            'a' => StockMovements::baseStock($product['product_id'], $product['variation_id'], $branchA),
            'b' => StockMovements::baseStock($product['product_id'], $product['variation_id'], $branchB),
            'all' => StockMovements::baseStock($product['product_id'], $product['variation_id']),
        ],
        'report_all' => [prpRows($report([]))->all(), $report([])->json('summary')],
        'report_by_branch' => prpRows($report(['by_branch' => 'true']))->all(),
        'report_branch_a' => prpRows($report(['branch_id' => $branchA]))->all(),
        'report_as_of' => prpRows($report(['end_date' => '2026-09-07']))->all(),
        'valuation' => [
            'all' => app(StockValuation::class)->at($company, null, '2026-12-31'),
            'a' => app(StockValuation::class)->at($company, $branchA, '2026-12-31'),
            'b' => app(StockValuation::class)->at($company, $branchB, '2026-12-31'),
            'early' => app(StockValuation::class)->at($company, null, '2026-08-15'),
        ],
    ];
}

/**
 * A warehouse of a branch, straight into the table.
 */
function wgWarehouse(int $companyId, int $branchId, string $name): int
{
    return DB::table('warehouses')->insertGetId(['company_id' => $companyId, 'branch_id' => $branchId, 'name' => $name, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
}

test('tagging every stock document with a warehouse changes no branch-level figure', function () {
    $life = stkLife();
    trpActAsSuperadmin();
    $company = $life['scope']['company_id'];
    $branchA = $life['scope']['branch_id'];
    $branchB = $life['other_branch'];

    $before = wgSnapshot($life);

    // Sanity: the books are not empty, so "identical" means something.
    expect($before['stock']['all'])->toEqual(61)->and($before['stock']['a'])->toEqual(41)->and($before['stock']['b'])->toEqual(20)
        ->and($before['valuation']['all']['value'])->toBeGreaterThan(0);

    $a1 = wgWarehouse($company, $branchA, 'A Main');
    $a2 = wgWarehouse($company, $branchA, 'A Overflow');
    $b1 = wgWarehouse($company, $branchB, 'B Main');

    // Purchases into A Main; sales and adjustments out of A Overflow; the transfer leaves A Main and arrives in B Main.
    DB::table('transactions')->where('type', Transaction::TYPE_PURCHASE)->update(['warehouse_id' => $a1]);
    DB::table('transactions')->whereIn('type', ['sell', 'adjustment'])->update(['warehouse_id' => $a2]);
    DB::table('transactions')->where('type', 'transfer')->update(['warehouse_id' => $a1, 'towarehouse_id' => $b1]);

    // The tags really took: every kind of movement now carries a warehouse, so "identical" is not vacuous.
    $tagged = StockMovements::query(['with_kinds' => true])->get()->whereNotNull('warehouse_id')->pluck('kind')->unique()->sort()->values()->all();
    expect($tagged)->toBe(['adjustment', 'purchase', 'purchase_return', 'sale', 'sale_return', 'transfer_in', 'transfer_out']);

    $after = wgSnapshot($life);

    expect($after)->toEqual($before);
});

test('a half-tagged book (some documents tagged, some not) also changes nothing', function () {
    $life = stkLife();
    trpActAsSuperadmin();
    $before = wgSnapshot($life);

    $a1 = wgWarehouse($life['scope']['company_id'], $life['scope']['branch_id'], 'A Main');
    DB::table('transactions')->where('type', Transaction::TYPE_PURCHASE)->update(['warehouse_id' => $a1]);

    expect(StockMovements::query(['with_kinds' => true])->get()->whereNotNull('warehouse_id')->pluck('kind')->unique()->sort()->values()->all())->toBe(['adjustment', 'purchase', 'purchase_return'])
        ->and(wgSnapshot($life))->toEqual($before);
});
