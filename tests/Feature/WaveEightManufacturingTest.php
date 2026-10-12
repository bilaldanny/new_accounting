<?php

use App\Models\User;
use App\Services\Manufacturing\ManufacturingSettings;
use App\Services\Reports\AverageCost;
use App\Services\StockMovements;
use App\Services\Valuation\CostSource;
use App\Services\Valuation\ValuationMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Wave 8, manufacturing: versioned multi-level BOMs, the production schedule and its material requirements, work orders (issue,
 * labour, scrap, quality, receipt with by-products), costing through the company's valuation method, WIP and the guarded ledger
 * posting. Posting is off by default and the guarantee test says the ledger does not move without it.
 */
const W8_PATHS = [
    '/manufacturing', '/manufacturing/manage', '/manufacturing/produce', '/manufacturing/approve',
    '/report/production-summary', '/report/wip-report', '/report/material-variance', '/report/scrap-report',
];

function w8Staff(array $scope, array $paths = W8_PATHS, bool $branchBound = true): User
{
    return fcoStaff($scope, $paths, $branchBound);
}

/**
 * A company with a chair factory: wood and screws in stock, a chair, its by-product (sawdust) and the accounts of the posting.
 *
 * @return array<string, mixed>
 */
function w8World(): array
{
    $scope = fcoScope();
    $products = [];

    foreach (['wood' => 'Wood', 'screws' => 'Screws', 'chair' => 'Chair', 'dust' => 'Sawdust', 'frame' => 'Frame'] as $key => $name) {
        $products[$key] = prdProduct($scope, $name, [], ['default_purchase_price' => 10, 'default_sell_price' => 20]);
    }

    w8Receive($scope, $products['wood'], 100, 10);
    w8Receive($scope, $products['screws'], 200, 1);

    return $scope + ['p' => $products];
}

function w8Receive(array $scope, array $product, float $quantity, float $rate): void
{
    $id = DB::table('transactions')->insertGetId([
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'contact_id' => $scope['supplier_id'] ?? $scope['contact_id'], 'invoice_no' => 'W8-PO-'.uniqid(),
        'type' => 'purchaseorder', 'status' => 'received', 'payment_status' => 'due', 'transaction_date' => '2026-09-01', 'final_amount' => $quantity * $rate, 'total_item' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    prdPurchaseLine($id, $product, $quantity, $rate);
}

function w8Stock(array $scope, string $key): float
{
    return StockMovements::baseStock($scope['p'][$key]['product_id'], $scope['p'][$key]['variation_id'], $scope['branch_id']);
}

/**
 * The chair BOM: 4 wood and 8 screws per chair, a by-product of 0.5 sawdust taking 5 % of the cost, and one 30 minute operation at
 * 60 labour and 30 overhead an hour.
 */
function w8ChairBom(array $scope, bool $activate = true): int
{
    $id = (int) test()->postJson('/api/manufacturing/boms', [
        'product_id' => $scope['p']['chair']['product_id'], 'name' => 'Chair', 'output_qty' => 1,
        'items' => [
            ['component_product_id' => $scope['p']['wood']['product_id'], 'quantity' => 4],
            ['component_product_id' => $scope['p']['screws']['product_id'], 'quantity' => 8],
        ],
        'outputs' => [['product_id' => $scope['p']['dust']['product_id'], 'kind' => 'byproduct', 'quantity' => 0.5, 'cost_share_percent' => 5]],
        'operations' => [['name' => 'Assembly', 'minutes' => 30, 'labor_rate_per_hour' => 60, 'overhead_rate_per_hour' => 30]],
    ])->assertCreated()->json('data.id');

    if ($activate) {
        test()->postJson("/api/boms/{$id}/approve")->assertOk();
    }

    return $id;
}

function w8Order(array $scope, float $quantity = 10, bool $release = true, ?string $start = '2026-09-14'): int
{
    $id = (int) test()->postJson('/api/manufacturing/work-orders', [
        'product_id' => $scope['p']['chair']['product_id'], 'planned_qty' => $quantity, 'planned_start' => $start,
    ])->assertCreated()->json('data.id');

    if ($release) {
        test()->postJson("/api/work-orders/{$id}/approve")->assertOk();
    }

    return $id;
}

function w8IssueAll(array $scope, int $order, string $date = '2026-09-15'): void
{
    $materials = DB::table('work_order_materials')->where('work_order_id', $order)->get();
    test()->postJson("/api/manufacturing/work-orders/{$order}/issue", ['date' => $date, 'lines' => $materials->map(fn (object $m): array => [
        'product_id' => (int) $m->product_id, 'variation_id' => (int) $m->variation_id, 'quantity' => (float) $m->required_qty,
    ])->all()])->assertOk();
}

function w8PostingOn(array $scope): void
{
    app(ManufacturingSettings::class)->save($scope['company_id'], [
        'posting_enabled' => true,
        'accounts' => [
            'raw_material_inventory' => $scope['allowance_id'], 'wip' => $scope['prepaid_id'], 'finished_goods_inventory' => $scope['bank_id'],
            'labor_applied' => $scope['accrued_id'], 'overhead_applied' => $scope['deferred_id'], 'scrap_loss' => $scope['rent_id'],
        ],
    ]);
}

function w8Books(int $companyId): array
{
    return [DB::table('t_accounts')->where('company_id', $companyId)->count(), DB::table('t_account_details')->count(), fcoTrialBalance($companyId)];
}

// ---------------------------------------------------------------------------------------------------------------------
// bills of materials

test('a BOM is versioned: only a draft is edited, activating a version archives the active one and a work order keeps its version', function () {
    $scope = w8World();
    Sanctum::actingAs(w8Staff($scope));
    $v1 = w8ChairBom($scope);
    $early = w8Order($scope, 2, false);

    expect(DB::table('boms')->where('id', $v1)->value('status'))->toBe('active')->and((int) DB::table('work_orders')->where('id', $early)->value('bom_version'))->toBe(1);

    $this->putJson("/api/manufacturing/boms/{$v1}", ['items' => [['component_product_id' => $scope['p']['wood']['product_id'], 'quantity' => 1]]])->assertUnprocessable();
    $this->deleteJson("/api/manufacturing/boms/{$v1}")->assertUnprocessable();

    $v2 = (int) $this->postJson("/api/manufacturing/boms/{$v1}/new-version")->assertCreated()->json('data.id');
    expect((int) DB::table('boms')->where('id', $v2)->value('version'))->toBe(2)->and(DB::table('bom_items')->where('bom_id', $v2)->count())->toBe(2);

    $this->putJson("/api/manufacturing/boms/{$v2}", ['items' => [
        ['component_product_id' => $scope['p']['wood']['product_id'], 'quantity' => 5], ['component_product_id' => $scope['p']['screws']['product_id'], 'quantity' => 8],
    ]])->assertCreated();
    $this->postJson("/api/boms/{$v2}/approve")->assertOk();

    expect(DB::table('boms')->where('id', $v1)->value('status'))->toBe('archived')->and(DB::table('boms')->where('id', $v2)->value('status'))->toBe('active');

    $late = w8Order($scope, 2, false);
    expect((int) DB::table('work_orders')->where('id', $late)->value('bom_version'))->toBe(2)
        ->and((float) DB::table('work_order_materials')->where('work_order_id', $late)->where('product_id', $scope['p']['wood']['product_id'])->value('required_qty'))->toBe(10.0)
        ->and((float) DB::table('work_order_materials')->where('work_order_id', $early)->where('product_id', $scope['p']['wood']['product_id'])->value('required_qty'))->toBe(8.0);
});

test('a BOM refuses its own product, a loop through a sub-assembly, an unstocked or foreign component and a double line', function () {
    $scope = w8World();
    $other = fcoOtherCompany();
    $foreign = prdProduct($other, 'Foreign part');
    Sanctum::actingAs(w8Staff($scope));
    $chair = $scope['p']['chair']['product_id'];
    $frame = $scope['p']['frame']['product_id'];
    $wood = $scope['p']['wood']['product_id'];

    $this->postJson('/api/manufacturing/boms', ['product_id' => $chair, 'items' => [['component_product_id' => $chair, 'quantity' => 1]]])->assertUnprocessable();
    $this->postJson('/api/manufacturing/boms', ['product_id' => $chair, 'items' => [['component_product_id' => $foreign['product_id'], 'quantity' => 1]]])->assertUnprocessable();
    $this->postJson('/api/manufacturing/boms', ['product_id' => $chair, 'items' => [['component_product_id' => $wood, 'quantity' => 1], ['component_product_id' => $wood, 'quantity' => 2]]])->assertUnprocessable();
    $this->postJson('/api/manufacturing/boms', ['product_id' => $foreign['product_id'], 'items' => [['component_product_id' => $wood, 'quantity' => 1]]])->assertUnprocessable();
    $this->postJson('/api/manufacturing/boms', ['product_id' => $chair, 'items' => [['component_product_id' => $wood, 'quantity' => 0]]])->assertUnprocessable();

    // chair needs a frame, a frame needs a chair: the second activation is refused
    $a = (int) $this->postJson('/api/manufacturing/boms', ['product_id' => $chair, 'items' => [['component_product_id' => $frame, 'quantity' => 1]]])->assertCreated()->json('data.id');
    $this->postJson("/api/boms/{$a}/approve")->assertOk();
    $b = (int) $this->postJson('/api/manufacturing/boms', ['product_id' => $frame, 'items' => [['component_product_id' => $chair, 'quantity' => 1]]])->assertCreated()->json('data.id');
    $this->postJson("/api/boms/{$b}/approve")->assertUnprocessable();
    expect(DB::table('boms')->where('id', $b)->value('status'))->toBe('draft');
});

test('a BOM is exploded through its sub-assemblies to raw materials and costed with the unit costs of the company', function () {
    $scope = w8World();
    Sanctum::actingAs(w8Staff($scope));
    $wood = $scope['p']['wood']['product_id'];
    $screws = $scope['p']['screws']['product_id'];

    $frame = (int) $this->postJson('/api/manufacturing/boms', ['product_id' => $scope['p']['frame']['product_id'], 'items' => [
        ['component_product_id' => $wood, 'quantity' => 2], ['component_product_id' => $screws, 'quantity' => 4],
    ]])->assertCreated()->json('data.id');
    $this->postJson("/api/boms/{$frame}/approve")->assertOk();

    $chair = (int) $this->postJson('/api/manufacturing/boms', ['product_id' => $scope['p']['chair']['product_id'], 'items' => [
        ['component_product_id' => $scope['p']['frame']['product_id'], 'quantity' => 1], ['component_product_id' => $wood, 'quantity' => 1, 'scrap_percent' => 10],
    ], 'operations' => [['name' => 'Finish', 'minutes' => 60, 'labor_rate_per_hour' => 100, 'overhead_rate_per_hour' => 20]]])->assertCreated()->json('data.id');
    $this->postJson("/api/boms/{$chair}/approve")->assertOk();

    $shown = $this->getJson("/api/manufacturing/boms/{$chair}?quantity=10")->assertOk()->json('data');
    $materials = collect($shown['explosion']['materials'])->keyBy('product_id');

    // 10 chairs: 10 frames (20 wood, 40 screws) and 11 wood with the 10 % scrap
    expect((float) $materials[$wood]['quantity'])->toBe(31.0)->and((float) $materials[$screws]['quantity'])->toBe(40.0)
        ->and(collect($shown['explosion']['tree'])->firstWhere('is_assembly', true)['name'])->toBe('Frame')
        ->and($shown['cost']['labor'])->toEqual(1000)->and($shown['cost']['overhead'])->toEqual(200);

    // the frame is itself valued at what its parts cost: the chair BOM costs its direct components only (the frame has no stock cost yet)
    expect($shown['cost']['unpriced'])->toContain('Frame');
});

// ---------------------------------------------------------------------------------------------------------------------
// work orders

test('a work order issues materials, takes labour, receives the finished good and its by-product at the production cost', function () {
    $scope = w8World();
    Sanctum::actingAs(w8Staff($scope));
    w8ChairBom($scope);
    $order = w8Order($scope, 10);
    $before = w8Books($scope['company_id']);

    // not released yet: nothing can be issued
    $draft = w8Order($scope, 1, false);
    $this->postJson("/api/manufacturing/work-orders/{$draft}/issue", ['lines' => [['product_id' => $scope['p']['wood']['product_id'], 'quantity' => 1]]])->assertUnprocessable();

    w8IssueAll($scope, $order);
    expect(w8Stock($scope, 'wood'))->toBe(60.0)->and(w8Stock($scope, 'screws'))->toBe(120.0);

    $this->postJson("/api/manufacturing/work-orders/{$order}/standard-labor")->assertOk();
    $this->postJson("/api/manufacturing/work-orders/{$order}/standard-labor")->assertUnprocessable();

    $shown = $this->getJson("/api/manufacturing/work-orders/{$order}")->assertOk()->json('data');
    expect((float) $shown['material_cost'])->toBe(480.0)->and((float) $shown['labor_cost'])->toBe(300.0)->and((float) $shown['overhead_cost'])->toBe(150.0)->and((float) $shown['wip_value'])->toBe(930.0);

    $received = $this->postJson("/api/manufacturing/work-orders/{$order}/receive", ['quantity' => 10, 'date' => '2026-09-20'])->assertOk()->json('data');

    // 930 less 5 % to the sawdust = 883.50 over 10 chairs
    expect($received['finished_unit_cost'])->toEqual(88.35)->and($received['absorbed'])->toEqual(930)
        ->and(w8Stock($scope, 'chair'))->toBe(10.0)->and(w8Stock($scope, 'dust'))->toBe(5.0)
        ->and(DB::table('work_orders')->where('id', $order)->value('status'))->toBe('completed')
        ->and(round((float) AverageCost::byVariation($scope['company_id'])[$scope['p']['chair']['variation_id']], 2))->toBe(88.35)
        ->and(round((float) AverageCost::byVariation($scope['company_id'])[$scope['p']['dust']['variation_id']], 2))->toBe(9.3)
        ->and(w8Books($scope['company_id']))->toBe($before);
});

test('the finished good is valued by the valuation method of the company, FIFO included', function () {
    $scope = w8World();
    Sanctum::actingAs(w8Staff($scope));
    w8ChairBom($scope);
    $order = w8Order($scope, 10);
    w8IssueAll($scope, $order);
    $this->postJson("/api/manufacturing/work-orders/{$order}/standard-labor")->assertOk();
    $this->postJson("/api/manufacturing/work-orders/{$order}/receive", ['quantity' => 10, 'date' => '2026-09-20'])->assertOk();

    $variation = $scope['p']['chair']['variation_id'];
    app(ValuationMethod::class)->record($scope['company_id'], 'fifo', '2026-09-01', null, null, null);
    $fifo = app(CostSource::class)->stockCosts($scope['company_id'], '2026-09-30');
    app(ValuationMethod::class)->record($scope['company_id'], 'standard', '2026-09-25', null, null, null);
    DB::table('standard_costs')->insert(['company_id' => $scope['company_id'], 'variation_id' => $variation, 'effective_from' => '2026-09-25', 'cost' => 90, 'created_at' => now(), 'updated_at' => now()]);
    $standard = app(CostSource::class)->stockCosts($scope['company_id'], '2026-09-30');

    expect(round((float) $fifo[$variation], 2))->toBe(88.35)->and((float) $standard[$variation])->toBe(90.0);
});

test('a partial receipt takes its share of what the work order holds and the last receipt takes the rest', function () {
    $scope = w8World();
    Sanctum::actingAs(w8Staff($scope));
    w8ChairBom($scope);
    $order = w8Order($scope, 10);
    w8IssueAll($scope, $order);
    $this->postJson("/api/manufacturing/work-orders/{$order}/standard-labor")->assertOk();

    $first = $this->postJson("/api/manufacturing/work-orders/{$order}/receive", ['quantity' => 4, 'date' => '2026-09-18'])->assertOk()->json('data');
    expect($first['absorbed'])->toEqual(372)->and($first['finished_unit_cost'])->toEqual(88.35)
        ->and(DB::table('work_orders')->where('id', $order)->value('status'))->toBe('in_progress');

    $last = $this->postJson("/api/manufacturing/work-orders/{$order}/receive", ['quantity' => 6, 'date' => '2026-09-20'])->assertOk()->json('data');
    expect($last['absorbed'])->toEqual(558)->and(w8Stock($scope, 'chair'))->toBe(10.0)
        ->and((float) DB::table('work_orders')->where('id', $order)->value('absorbed_cost'))->toBe(930.0)
        ->and(DB::table('work_orders')->where('id', $order)->value('status'))->toBe('completed');
});

test('backflush issues what the received quantity needs, and insufficient stock refuses the issue and moves nothing', function () {
    $scope = w8World();
    Sanctum::actingAs(w8Staff($scope));
    w8ChairBom($scope);
    $order = w8Order($scope, 10);

    $this->postJson("/api/manufacturing/work-orders/{$order}/receive", ['quantity' => 5, 'backflush' => true, 'date' => '2026-09-20'])->assertOk();
    expect(w8Stock($scope, 'wood'))->toBe(80.0)->and(w8Stock($scope, 'chair'))->toBe(5.0)
        ->and((float) DB::table('work_orders')->where('id', $order)->value('material_cost'))->toBe(240.0);

    // 100 wood in stock, 10 chairs of the second order need 40 but only 80 are left: a third order of 30 chairs needs 120
    $big = w8Order($scope, 30);
    $materials = DB::table('work_order_materials')->where('work_order_id', $big)->get();
    $this->postJson("/api/manufacturing/work-orders/{$big}/issue", ['lines' => $materials->map(fn (object $m): array => ['product_id' => (int) $m->product_id, 'quantity' => (float) $m->required_qty])->all()])->assertUnprocessable();
    expect(w8Stock($scope, 'wood'))->toBe(80.0)->and(DB::table('work_order_issues')->where('work_order_id', $big)->count())->toBe(0);
});

test('abnormal scrap is a loss out of the work order, normal scrap stays in the cost, and the scrap report lists both', function () {
    $scope = w8World();
    Sanctum::actingAs(w8Staff($scope));
    w8ChairBom($scope);
    $order = w8Order($scope, 10);
    w8IssueAll($scope, $order);

    $this->postJson("/api/manufacturing/work-orders/{$order}/scrap", ['quantity' => 1, 'is_normal' => true, 'reason' => 'Offcuts', 'date' => '2026-09-16'])->assertOk();
    $this->postJson("/api/manufacturing/work-orders/{$order}/scrap", ['quantity' => 2, 'is_normal' => false, 'reason' => 'Dropped', 'date' => '2026-09-16'])->assertOk();

    // 480 of material over 10 planned units = 48 a unit: 2 abnormal units cost 96
    $row = DB::table('work_orders')->where('id', $order)->first();
    expect((float) $row->scrap_loss)->toBe(96.0)->and((float) $row->scrap_qty)->toBe(3.0)->and((float) $row->material_cost - (float) $row->scrap_loss)->toBe(384.0);

    $report = $this->getJson('/api/reports/scrap-report?start_date=2026-09-01&end_date=2026-09-30')->assertOk();
    expect($report->json('summary.entries'))->toBe(2)->and($report->json('summary.loss'))->toEqual(96)->and($report->json('summary.abnormal_qty'))->toEqual(2);
});

test('quality checks can hold a receipt back until enough has passed', function () {
    $scope = w8World();
    Sanctum::actingAs(w8Staff($scope));
    app(ManufacturingSettings::class)->save($scope['company_id'], ['require_qc_before_receipt' => true]);
    w8ChairBom($scope);
    $order = w8Order($scope, 10);
    w8IssueAll($scope, $order);

    $this->postJson("/api/manufacturing/work-orders/{$order}/receive", ['quantity' => 5, 'date' => '2026-09-20'])->assertUnprocessable();
    $this->postJson("/api/manufacturing/work-orders/{$order}/checks", ['check_name' => 'Joint strength', 'result' => 'fail', 'qty_inspected' => 6, 'qty_failed' => 1, 'scrap_failed' => true, 'date' => '2026-09-19'])->assertOk();
    $this->postJson("/api/manufacturing/work-orders/{$order}/receive", ['quantity' => 6, 'date' => '2026-09-20'])->assertUnprocessable();
    $this->postJson("/api/manufacturing/work-orders/{$order}/receive", ['quantity' => 5, 'date' => '2026-09-20'])->assertOk();

    expect(w8Stock($scope, 'chair'))->toBe(5.0)->and((float) DB::table('work_orders')->where('id', $order)->value('scrap_qty'))->toBe(1.0)
        ->and(DB::table('work_order_scraps')->where('is_normal', false)->count())->toBe(1);
});

test('over-issue can be switched off, a cancelled work order with activity is refused and a closed one holds nothing', function () {
    $scope = w8World();
    Sanctum::actingAs(w8Staff($scope));
    app(ManufacturingSettings::class)->save($scope['company_id'], ['allow_over_issue' => false]);
    w8ChairBom($scope);
    $order = w8Order($scope, 10);

    $this->postJson("/api/manufacturing/work-orders/{$order}/issue", ['lines' => [['product_id' => $scope['p']['wood']['product_id'], 'quantity' => 41]]])->assertUnprocessable();
    w8IssueAll($scope, $order);
    $this->postJson("/api/manufacturing/work-orders/{$order}/cancel")->assertUnprocessable();

    $this->postJson("/api/manufacturing/work-orders/{$order}/receive", ['quantity' => 5, 'date' => '2026-09-20'])->assertOk();
    $this->postJson("/api/manufacturing/work-orders/{$order}/labor", ['operation' => 'Late work', 'hours' => 2, 'labor_rate' => 50, 'overhead_rate' => 10, 'date' => '2026-09-21'])->assertOk();
    $closed = $this->postJson("/api/manufacturing/work-orders/{$order}/close", ['date' => '2026-09-30'])->assertOk()->json('data.variance');

    // 480 held when 5 of 10 were received (240 absorbed); 120 of labour and overhead came after: 600 - 240 = 360 is the variance
    expect($closed)->toEqual(360)->and((float) DB::table('work_orders')->where('id', $order)->value('scrap_loss'))->toBe(360.0)
        ->and($this->getJson('/api/reports/wip-report')->json('summary.wip_value'))->toEqual(0);

    $fresh = w8Order($scope, 1, false);
    $this->postJson("/api/manufacturing/work-orders/{$fresh}/cancel")->assertOk();
});

// ---------------------------------------------------------------------------------------------------------------------
// schedule

test('the production schedule compares plans with work orders and sets the material need against stock', function () {
    $scope = w8World();
    Sanctum::actingAs(w8Staff($scope));
    w8ChairBom($scope);
    $this->postJson('/api/manufacturing/plans', ['product_id' => $scope['p']['chair']['product_id'], 'period_start' => '2026-09-14', 'planned_qty' => 30])->assertCreated();
    $this->postJson('/api/manufacturing/plans', ['product_id' => $scope['p']['chair']['product_id'], 'period_start' => '2026-09-14', 'planned_qty' => 30])->assertCreated();
    w8Order($scope, 5, true, '2026-09-15');

    $plans = $this->getJson('/api/manufacturing/plans?from=2026-09-01&to=2026-09-30')->assertOk()->json();
    $row = $plans['data'][0];

    // 30 planned, 5 on a work order: 25 chairs to cover = 100 wood (100 on hand) and 200 screws (200 on hand)
    expect(count($plans['data']))->toBe(1)->and($row['to_cover'])->toEqual(25)->and($row['work_order_qty'])->toEqual(5);
    $wood = collect($plans['materials'])->firstWhere('name', 'Wood');
    expect($wood['required'])->toEqual(100)->and($wood['on_hand'])->toEqual(100)->and($wood['shortage'])->toEqual(0);

    $this->postJson('/api/manufacturing/plans', ['product_id' => $scope['p']['chair']['product_id'], 'period_start' => '2026-09-14', 'planned_qty' => 40])->assertCreated();
    $wood = collect($this->getJson('/api/manufacturing/plans?from=2026-09-01&to=2026-09-30')->json('materials'))->firstWhere('name', 'Wood');
    expect($wood['required'])->toEqual(140)->and($wood['shortage'])->toEqual(40);
});

// ---------------------------------------------------------------------------------------------------------------------
// ledger posting (guarded)

test('with posting off the whole production cycle leaves vouchers, ledger and trial balance exactly as they were', function () {
    $scope = w8World();
    Sanctum::actingAs(w8Staff($scope));
    $before = w8Books($scope['company_id']);

    w8ChairBom($scope);
    $order = w8Order($scope, 10);
    w8IssueAll($scope, $order);
    $this->postJson("/api/manufacturing/work-orders/{$order}/standard-labor")->assertOk();
    $this->postJson("/api/manufacturing/work-orders/{$order}/scrap", ['quantity' => 1, 'is_normal' => false, 'date' => '2026-09-16'])->assertOk();
    $this->postJson("/api/manufacturing/work-orders/{$order}/receive", ['quantity' => 9, 'date' => '2026-09-20'])->assertOk();
    $this->getJson('/api/reports/production-summary?start_date=2026-09-01&end_date=2026-09-30')->assertOk();

    expect(w8Books($scope['company_id']))->toBe($before)->and(DB::table('work_order_issues')->whereNotNull('voucher_id')->count())->toBe(0)
        ->and(DB::table('work_order_receipts')->whereNotNull('voucher_id')->count())->toBe(0);
});

test('with posting on every step posts a balanced entry, the WIP account follows the WIP report and a locked month refuses the step', function () {
    $scope = w8World();
    Sanctum::actingAs(w8Staff($scope));
    w8ChairBom($scope);
    $order = w8Order($scope, 10);

    // switched on but not mapped: refused before any stock moves
    DB::table('manufacturing_settings')->insert(['company_id' => $scope['company_id'], 'settings' => json_encode(['posting_enabled' => true, 'accounts' => []]), 'created_at' => now(), 'updated_at' => now()]);
    $this->postJson("/api/manufacturing/work-orders/{$order}/issue", ['lines' => [['product_id' => $scope['p']['wood']['product_id'], 'quantity' => 4]]])->assertUnprocessable()->assertJsonValidationErrors('accounts');
    expect(w8Stock($scope, 'wood'))->toBe(100.0);

    w8PostingOn($scope);

    // a locked month: the issue is refused and the stock and ledger are untouched
    fcoLockThrough($scope['company_id'], '2026-09-30');
    $books = w8Books($scope['company_id']);
    $this->postJson("/api/manufacturing/work-orders/{$order}/issue", ['date' => '2026-09-15', 'lines' => [['product_id' => $scope['p']['wood']['product_id'], 'quantity' => 4]]])->assertUnprocessable();
    expect(w8Stock($scope, 'wood'))->toBe(100.0)->and(w8Books($scope['company_id']))->toBe($books)->and(DB::table('work_order_issues')->count())->toBe(0);
    DB::table('period_locks')->delete();

    w8IssueAll($scope, $order);
    $this->postJson("/api/manufacturing/work-orders/{$order}/standard-labor")->assertOk();

    // materials 480 + labour 300 + overhead 150 in WIP
    expect(fcoBalance($scope['prepaid_id']))->toBe(930.0)->and(fcoBalance($scope['allowance_id']))->toBe(-480.0)
        ->and(fcoBalance($scope['accrued_id']))->toBe(-300.0)->and(fcoBalance($scope['deferred_id']))->toBe(-150.0)
        ->and($this->getJson('/api/reports/wip-report')->json('summary.wip_value'))->toEqual(930);

    $this->postJson("/api/manufacturing/work-orders/{$order}/receive", ['quantity' => 10, 'date' => '2026-09-20'])->assertOk();

    expect(fcoBalance($scope['prepaid_id']))->toBe(0.0)->and(fcoBalance($scope['bank_id']))->toBe(930.0)
        ->and(fcoTrialBalance($scope['company_id'])['debit'])->toBe(fcoTrialBalance($scope['company_id'])['credit'])
        ->and($this->getJson('/api/reports/wip-report')->json('summary.wip_value'))->toEqual(0);
});

// ---------------------------------------------------------------------------------------------------------------------
// isolation, permissions, approvals, reports

test('BOMs, work orders and plans of another company are not found and the company in the body is ignored', function () {
    $scope = w8World();
    $other = fcoOtherCompany();
    $theirs = $other + ['p' => ['chair' => prdProduct($other, 'Their chair'), 'wood' => prdProduct($other, 'Their wood')]];
    Sanctum::actingAs(w8Staff($theirs));
    $bom = (int) $this->postJson('/api/manufacturing/boms', ['product_id' => $theirs['p']['chair']['product_id'], 'items' => [['component_product_id' => $theirs['p']['wood']['product_id'], 'quantity' => 1]]])->assertCreated()->json('data.id');
    $this->postJson("/api/boms/{$bom}/approve")->assertOk();
    $order = (int) $this->postJson('/api/manufacturing/work-orders', ['product_id' => $theirs['p']['chair']['product_id'], 'planned_qty' => 1])->assertCreated()->json('data.id');

    Sanctum::actingAs(w8Staff($scope));
    $this->getJson("/api/manufacturing/boms/{$bom}")->assertNotFound();
    $this->postJson("/api/boms/{$bom}/approve")->assertNotFound();
    $this->postJson("/api/manufacturing/boms/{$bom}/new-version")->assertNotFound();
    $this->getJson("/api/manufacturing/work-orders/{$order}")->assertNotFound();
    $this->postJson("/api/work-orders/{$order}/approve")->assertNotFound();
    $this->postJson("/api/manufacturing/work-orders/{$order}/receive", ['quantity' => 1])->assertNotFound();

    $created = $this->postJson('/api/manufacturing/boms', [
        'product_id' => $scope['p']['chair']['product_id'], 'company_id' => $other['company_id'], 'items' => [['component_product_id' => $scope['p']['wood']['product_id'], 'quantity' => 1]],
    ])->assertCreated()->json('data.id');
    expect((int) DB::table('boms')->where('id', $created)->value('company_id'))->toBe($scope['company_id']);

    $listed = collect($this->getJson('/api/manufacturing/boms')->json('data'))->pluck('id')->all();
    expect($listed)->toContain($created)->not->toContain($bom);

    $this->postJson('/api/manufacturing/work-orders', ['product_id' => $theirs['p']['chair']['product_id'], 'planned_qty' => 1])->assertUnprocessable();
});

test('manufacturing endpoints need their own permission and the creator cannot release their own work order under maker-checker', function () {
    $scope = w8World();
    Sanctum::actingAs(w8Staff($scope, W8_PATHS));
    w8ChairBom($scope);

    Sanctum::actingAs(w8Staff($scope, ['/manufacturing']));
    $this->getJson('/api/manufacturing/boms')->assertOk();
    $this->postJson('/api/manufacturing/boms', ['product_id' => 1, 'items' => [['component_product_id' => 2, 'quantity' => 1]]])->assertForbidden();
    $this->postJson('/api/manufacturing/work-orders', ['product_id' => $scope['p']['chair']['product_id'], 'planned_qty' => 1])->assertForbidden();
    $this->postJson('/api/boms/1/approve')->assertForbidden();
    $this->postJson('/api/work-orders/1/approve')->assertForbidden();
    $this->getJson('/api/reports/wip-report')->assertForbidden();

    DB::table('security_settings')->insert(['company_id' => $scope['company_id'], 'maker_checker' => 1, 'policy_enabled' => 0, 'expiry_days' => 30, 'created_at' => now(), 'updated_at' => now()]);
    $maker = w8Staff($scope);
    Sanctum::actingAs($maker);
    $order = w8Order($scope, 2, false);
    $this->postJson("/api/work-orders/{$order}/approve")->assertUnprocessable();

    Sanctum::actingAs(w8Staff($scope));
    $this->postJson("/api/work-orders/{$order}/approve")->assertOk();
});

test('a work order release can be put behind an approval workflow and the settings validate their values', function () {
    $scope = w8World();
    $first = w8Staff($scope, [...W8_PATHS, '/approvalcenter', '/approvalcenter/manage']);
    $second = w8Staff($scope, ['/manufacturing', '/manufacturing/approve']);
    Sanctum::actingAs($first);
    w8ChairBom($scope);

    $this->postJson('/api/approval-center/workflows', [
        'name' => 'Release', 'document_type' => 'workorder', 'levels' => [
            ['label' => 'Production', 'mode' => 'any', 'approvers' => [['type' => 'user', 'id' => $first->id]]],
            ['label' => 'Plant head', 'mode' => 'any', 'approvers' => [['type' => 'user', 'id' => $second->id]]],
        ],
    ])->assertCreated();

    $order = w8Order($scope, 2, false);
    $this->postJson("/api/work-orders/{$order}/approve")->assertOk();
    expect(DB::table('work_orders')->where('id', $order)->value('status'))->toBe('draft');

    Sanctum::actingAs($second);
    $this->postJson("/api/work-orders/{$order}/approve")->assertOk();
    expect(DB::table('work_orders')->where('id', $order)->value('status'))->toBe('released');

    Sanctum::actingAs($first);
    $this->putJson('/api/manufacturing/settings', ['posting_enabled' => 'maybe'])->assertUnprocessable();
    $this->putJson('/api/manufacturing/settings', ['accounts' => ['wip' => 999999]])->assertUnprocessable();
    expect($this->getJson('/api/manufacturing/settings')->json('data.posting_enabled'))->toBeFalse();
});

test('the production reports are company scoped and add up from the work orders', function () {
    $scope = w8World();
    $other = fcoOtherCompany();
    Sanctum::actingAs(w8Staff($scope));
    w8ChairBom($scope);
    $order = w8Order($scope, 10);
    w8IssueAll($scope, $order);
    $this->postJson("/api/manufacturing/work-orders/{$order}/standard-labor")->assertOk();
    $this->postJson("/api/manufacturing/work-orders/{$order}/receive", ['quantity' => 10, 'date' => '2026-09-20'])->assertOk();

    $summary = $this->getJson('/api/reports/production-summary?start_date=2026-09-01&end_date=2026-09-30')->assertOk();
    expect($summary->json('summary.orders'))->toBe(1)->and($summary->json('summary.produced_qty'))->toEqual(10)->and($summary->json('summary.total_cost'))->toEqual(930);

    $variance = $this->getJson('/api/reports/material-variance?start_date=2026-09-01&end_date=2026-09-30')->assertOk();
    expect($variance->json('summary.lines'))->toBe(2)->and($variance->json('summary.over_issued'))->toBe(0)->and($variance->json('summary.issued_cost'))->toEqual(480);

    Sanctum::actingAs(w8Staff($other));
    expect($this->getJson('/api/reports/production-summary?start_date=2026-09-01&end_date=2026-09-30')->json('summary.orders'))->toBe(0)
        ->and($this->getJson('/api/reports/wip-report')->json('summary.orders'))->toBe(0);
});
