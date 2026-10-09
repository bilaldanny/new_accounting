<?php

use App\Models\CashCollection;
use App\Models\CreditLimitRequest;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-15 12:00:00');
    Cache::flush();
    Sanctum::actingAs(User::query()->findOrFail(1));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $attributes
 */
function p1Doc(array $scope, string $type, float $amount, string $date, array $attributes = []): Transaction
{
    return Transaction::query()->create(array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'contact_id' => $scope['contact_id'],
        'invoice_no' => strtoupper($type).'-'.fake()->unique()->numerify('######'),
        'type' => $type,
        'status' => 'final',
        'payment_status' => 'due',
        'transaction_date' => $date.' 10:00:00',
        'final_amount' => $amount,
        'total_item' => 1,
    ], $attributes));
}

/**
 * @return array<string, mixed>
 */
function p1Get(string $widget, int $companyId, array $query = []): array
{
    return test()->getJson("/api/dashboard/{$widget}?".http_build_query(array_merge(['company_id' => $companyId], $query)))
        ->assertSuccessful()
        ->json('data');
}

test('the profit card also gives cost of goods, gross profit and gross margin', function () {
    $scope = seedSellScope();

    $card = p1Get('financial', $scope['company_id'])['net_profit'];

    expect($card)->toHaveKeys(['net_profit', 'expenses', 'cogs', 'gross_profit', 'gross_margin'])
        ->and($card['gross_margin'])->toEqual(0);
});

test('the trends card gives six months of net sales and purchases, oldest first', function () {
    $scope = seedSellScope();
    p1Doc($scope, 'sell', 1000, '2026-09-10');
    p1Doc($scope, 'sell', 400, '2026-08-20');
    p1Doc($scope, 'purchaseorder', 250, '2026-08-05', ['status' => 'approved']);
    p1Doc($scope, 'sell', 9000, '2026-03-01');

    $trends = p1Get('trends', $scope['company_id'])['trends'];

    expect($trends)->toHaveCount(6)
        ->and($trends[0]['month'])->toBe('2026-04')
        ->and($trends[5]['month'])->toBe('2026-09')
        ->and($trends[5]['sales'])->toEqual(1000)
        ->and($trends[4]['sales'])->toEqual(400)
        ->and($trends[4]['purchases'])->toEqual(250)
        ->and(collect($trends)->sum('sales'))->toEqual(1400);
});

test('the profit trend is one company only and has a month per entry', function () {
    $scope = seedSellScope();

    $profit = p1Get('trends', $scope['company_id'])['profit_trend'];

    expect($profit)->toHaveCount(6)->and($profit[0])->toHaveKeys(['month', 'net_profit']);

    $answer = $this->getJson('/api/dashboard/trends')->assertSuccessful();

    expect($answer->json('needs_company'))->toContain('profit_trend')
        ->and($answer->json('data'))->toHaveKey('trends')
        ->and($answer->json('data'))->not->toHaveKey('profit_trend');
});

test('receivable aging puts what is still unpaid into the 30, 60, 90 and over 90 day buckets', function () {
    $scope = seedSellScope();

    p1Doc($scope, 'sell', 100, '2026-09-10');
    $partial = p1Doc($scope, 'sell', 1000, '2026-08-06', ['payment_status' => 'partial']);
    p1Doc($scope, 'sell', 300, '2026-07-01');
    p1Doc($scope, 'sell', 700, '2026-05-01');
    p1Doc($scope, 'sell', 5000, '2026-09-01', ['status' => 'draft']);
    $paid = p1Doc($scope, 'sell', 800, '2026-05-01', ['payment_status' => 'paid']);

    foreach ([[$partial, 400], [$paid, 800]] as [$invoice, $amount]) {
        DB::table('payments')->insert([
            'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'transaction_id' => $invoice->id,
            'contact_id' => $scope['contact_id'], 'amount' => $amount, 'method' => 'cash', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $aging = p1Get('aging', $scope['company_id'])['receivable_aging'];
    $buckets = collect($aging['buckets'])->pluck('amount', 'label');

    expect($buckets['0-30 days'])->toEqual(100)
        ->and($buckets['31-60 days'])->toEqual(600)
        ->and($buckets['61-90 days'])->toEqual(300)
        ->and($buckets['Over 90 days'])->toEqual(700)
        ->and($aging['total'])->toEqual(1700);
});

test('payable aging reads purchases instead of sales', function () {
    $scope = seedSellScope();

    p1Doc($scope, 'purchaseorder', 500, '2026-09-01', ['status' => 'approved']);
    p1Doc($scope, 'purchaseorder', 900, '2026-09-01', ['status' => 'pending']);
    p1Doc($scope, 'sell', 111, '2026-09-01');

    $aging = p1Get('aging', $scope['company_id'])['payable_aging'];

    expect($aging['total'])->toEqual(500)->and($aging['buckets'][0]['count'])->toBe(1);
});

test('the movement card lists the best sellers of the last 30 days and not older sales', function () {
    $scope = seedSellScope();
    $recent = p1Doc($scope, 'sell', 240, '2026-09-10');
    $old = p1Doc($scope, 'sell', 999, '2026-04-10');

    foreach ([[$recent, 5], [$old, 50]] as [$sale, $quantity]) {
        DB::table('sell_lines')->insert([
            'transaction_id' => $sale->id, 'product_id' => $scope['product_id'], 'variation_id' => $scope['variation_id'],
            'unit_id' => $scope['unit_id'], 'quantity' => $quantity, 'unit_price' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $movement = p1Get('movement', $scope['company_id'])['inventory_movement'];

    expect($movement['top_selling'])->toHaveCount(1)
        ->and($movement['top_selling'][0]['name'])->toBe('Premium Basmati Rice')
        ->and($movement['top_selling'][0]['quantity'])->toEqual(5)
        ->and($movement['dead_stock'])->toBeArray();
});

test('stock with no sale in 90 days is dead stock and a recent sale takes it off the list', function () {
    $scope = seedSellScope();

    $received = createPurchaseRecord($scope, ['status' => 'received', 'invoice_no' => 'PO-DEAD-1', 'transaction_date' => '2026-05-01 10:00:00']);
    $received->purchaselines()->update(['quantity_received' => 10]);

    $dead = p1Get('movement', $scope['company_id'])['inventory_movement']['dead_stock'];

    expect($dead)->toHaveCount(1)->and($dead[0]['product_id'])->toBe($scope['product_id']);

    $sale = p1Doc($scope, 'sell', 100, '2026-09-01');
    DB::table('sell_lines')->insert([
        'transaction_id' => $sale->id, 'product_id' => $scope['product_id'], 'variation_id' => $scope['variation_id'],
        'unit_id' => $scope['unit_id'], 'quantity' => 1, 'unit_price' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);
    Cache::flush();

    expect(p1Get('movement', $scope['company_id'])['inventory_movement']['dead_stock'])->toBe([]);
});

test('the approvals card counts the Approval Center queues too', function () {
    $scope = seedSellScope();

    p1Doc($scope, Transaction::TYPE_PURCHASE_RETURN, 50, '2026-09-10', ['status' => 'pending']);
    p1Doc($scope, Transaction::TYPE_ADJUSTMENT, 0, '2026-09-10', ['status' => 'pending']);
    p1Doc($scope, Transaction::TYPE_ADJUSTMENT, 0, '2026-09-10', ['status' => 'completed']);
    CashCollection::query()->create([
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'contact_id' => $scope['contact_id'],
        'reference' => 'CC-1', 'collected_on' => '2026-09-10', 'amount' => 10, 'status' => 'pending',
    ]);
    CreditLimitRequest::query()->create([
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'contact_id' => $scope['contact_id'],
        'current_limit' => 1, 'requested_limit' => 2, 'status' => 'pending',
    ]);

    $approvals = p1Get('approvals', $scope['company_id']);

    expect($approvals['purchasereturn'])->toBe(1)
        ->and($approvals['stockadjustment'])->toBe(1)
        ->and($approvals['stocktransfer'])->toBe(0)
        ->and($approvals['cashcollection'])->toBe(1)
        ->and($approvals['pricelist'])->toBe(0)
        ->and($approvals['creditlimit'])->toBe(1);
});

test('the recent payments feed is newest first, with direction and the invoice', function () {
    $scope = seedSellScope();
    $sale = p1Doc($scope, 'sell', 500, '2026-09-10');

    foreach ([100, 150] as $amount) {
        DB::table('payments')->insert([
            'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'transaction_id' => $sale->id,
            'contact_id' => $scope['contact_id'], 'amount' => $amount, 'method' => 'cash', 'paid_on' => '2026-09-12', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $payments = p1Get('recent', $scope['company_id'])['recent_payments'];

    expect($payments)->toHaveCount(2)
        ->and($payments[0]['amount'])->toEqual(150)
        ->and($payments[0]['direction'])->toBe('received')
        ->and($payments[0]['invoice_no'])->toBe($sale->invoice_no);
});

test('the date filter moves the dashboard\'s today', function () {
    $scope = seedSellScope();
    p1Doc($scope, 'sell', 700, '2026-08-20');

    $sales = p1Get('sales', $scope['company_id'], ['as_of' => '2026-08-31'])['sales'];

    expect($sales['as_of'])->toBe('2026-08-31')->and($sales['this_month'])->toEqual(700);

    $this->getJson('/api/dashboard/sales?as_of=not-a-date')->assertUnprocessable();
});

test('the notification bell counts what waits for the user and nothing else', function () {
    $scope = seedSellScope();
    p1Doc($scope, Transaction::TYPE_PURCHASE_RETURN, 50, '2026-09-10', ['status' => 'pending']);
    CreditLimitRequest::query()->create([
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'contact_id' => $scope['contact_id'],
        'current_limit' => 1, 'requested_limit' => 2, 'status' => 'pending',
    ]);

    $response = $this->getJson('/api/notifications/counts-by-type?company_id='.$scope['company_id'])->assertSuccessful();

    expect($response->json('counts_by_type'))->toBe(['purchasereturn' => 1, 'creditlimit' => 1])
        ->and($response->json('total'))->toBe(2);
});
