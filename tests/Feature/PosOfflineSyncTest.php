<?php

use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Task D: offline POS. The terminal caches a catalog and rings up cash / card sales offline; each carries a client UUID and is
 * replayed once, as one unit, idempotently. The server stays the authority: a sale it cannot take as sent is parked.
 */
const PO_POS = ['/sell/pos', '/posoffline'];
const PO_SUPERVISOR = ['/sell/pos', '/posoffline', '/posoffline/resolve'];

function poScope(): array
{
    $scope = seedSellScope();
    $cash = insertPurchaseChartAccount($scope, '111-00911', 'Cash in Hand', 'dr', false);
    insertPurchaseAccountMapping($scope, 'Cash', 'cash', $cash);
    DB::table('product_details')->where('id', $scope['variation_id'])->update(['default_sell_price' => 50]);

    return $scope + ['cash_account' => $cash];
}

function poSale(array $scope, array $extra = []): array
{
    return $extra + [
        'client_uuid' => 'uuid-'.str_pad((string) random_int(1, 999999999), 12, '0'), 'device_id' => 'T1', 'offline_receipt_no' => 'OFF-T1-000001',
        'sold_at' => now()->subHour()->toIso8601String(), 'contact_id' => $scope['customer_id'], 'total' => 100,
        'lines' => [['product_id' => $scope['product_id'], 'variation_id' => $scope['variation_id'], 'unit_id' => $scope['unit_id'], 'quantity' => 2, 'unit_price' => 50]],
        'payments' => [['method' => 'cash', 'payment_account' => $scope['cash_account'], 'amount' => 100]],
    ];
}

test('the catalog carries the branch products with their price and the cash accounts, and is company scoped', function () {
    $scope = poScope();
    $other = trpScope('7');
    prdProduct($other, 'Foreign Goods');
    Sanctum::actingAs(fcoStaff($scope, PO_POS));

    $catalog = $this->getJson('/api/pos/offline/catalog')->assertOk()->json();
    $row = collect($catalog['products'])->firstWhere('variation_id', $scope['variation_id']);

    expect($row['price'])->toEqual(50)->and(collect($catalog['products'])->pluck('name')->implode(','))->not->toContain('Foreign Goods')
        ->and(collect($catalog['payment_accounts'])->pluck('id')->all())->toContain($scope['cash_account'])
        ->and(collect($catalog['customers'])->pluck('id')->all())->toContain((int) $scope['customer_id'])
        ->and($catalog['catalog_version'])->not->toBe('');
});

test('an offline sale is posted once with its payment, and replaying the same UUID posts nothing more', function () {
    $scope = poScope();
    Sanctum::actingAs(fcoStaff($scope, PO_POS));
    $sale = poSale($scope);

    $first = $this->postJson('/api/pos/offline/sync', $sale)->assertOk();
    expect($first->json('status'))->toBe('synced')->and($first->json('repeat'))->toBeFalse()->and($first->json('invoice_no'))->not->toBeNull();

    $transaction = Transaction::query()->findOrFail($first->json('transaction_id'));
    expect((float) $transaction->final_amount)->toBe(100.0)->and($transaction->payment_status)->toBe('paid')
        ->and(Payment::query()->where('transaction_id', $transaction->id)->count())->toBe(1);

    // the terminal lost the answer and sends it again, twice
    $again = $this->postJson('/api/pos/offline/sync', $sale)->assertOk();
    $this->postJson('/api/pos/offline/sync', $sale)->assertOk();
    expect($again->json('repeat'))->toBeTrue()->and($again->json('invoice_no'))->toBe($first->json('invoice_no'))
        ->and(Transaction::query()->where('type', 'sell')->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(1)
        ->and(DB::table('pos_offline_sales')->count())->toBe(1);
});

test('a sale that takes stock below zero is posted and flagged, even when the company blocks negative stock online', function () {
    $scope = poScope();
    DB::table('company_settings')->updateOrInsert(['company_id' => $scope['company_id']], ['company_id' => $scope['company_id'], 'negative_stock_mode' => 'block', 'created_at' => now(), 'updated_at' => now()]);
    Sanctum::actingAs(fcoStaff($scope, [...PO_POS, '/sell/add']));

    // the same sale made online at the POS is refused
    $online = $this->postJson('/api/sells', [
        'branch_id' => $scope['branch_id'], 'contact_id' => $scope['customer_id'], 'transaction_date' => now()->toDateString(), 'is_pos' => true, 'final_amount' => 100,
        'selllines' => [['product_id' => $scope['product_id'], 'variation_id' => $scope['variation_id'], 'unit_id' => $scope['unit_id'], 'quantity' => 2, 'unit_price' => 50]],
    ]);
    expect($online->status())->toBe(422);

    $result = $this->postJson('/api/pos/offline/sync', poSale($scope))->assertOk();
    expect($result->json('status'))->toBe('synced')->and($result->json('oversold'))->toHaveCount(1)
        ->and($result->json('oversold.0.requested'))->toEqual(2);
});

test('a price that moved is parked, a supervisor can accept it at the price sold or discard it', function () {
    $scope = poScope();
    DB::table('product_details')->where('id', $scope['variation_id'])->update(['default_sell_price' => 60]);
    Sanctum::actingAs(fcoStaff($scope, PO_SUPERVISOR));

    $parked = $this->postJson('/api/pos/offline/sync', poSale($scope, ['client_uuid' => 'uuid-price-000000001']))->assertOk();
    expect($parked->json('status'))->toBe('conflict')->and($parked->json('conflict_code'))->toBe('price_changed')
        ->and($parked->json('conflict_detail.lines.0.current_price'))->toEqual(60)
        ->and(Transaction::query()->where('type', 'sell')->count())->toBe(0);

    // replaying a parked sale does not post it
    $this->postJson('/api/pos/offline/sync', poSale($scope, ['client_uuid' => 'uuid-price-000000001']))->assertOk()->assertJsonPath('status', 'conflict');
    expect(Transaction::query()->where('type', 'sell')->count())->toBe(0);

    $id = $parked->json('id');
    $accepted = $this->postJson("/api/pos/offline/sales/{$id}/resolve", ['action' => 'accept'])->assertOk();
    expect($accepted->json('status'))->toBe('synced')
        ->and((float) Transaction::query()->findOrFail($accepted->json('transaction_id'))->final_amount)->toBe(100.0);

    $second = $this->postJson('/api/pos/offline/sync', poSale($scope, ['client_uuid' => 'uuid-price-000000002']))->json('id');
    $this->postJson("/api/pos/offline/sales/{$second}/resolve", ['action' => 'discard'])->assertOk()->assertJsonPath('status', 'discarded');
    $this->postJson("/api/pos/offline/sales/{$second}/resolve", ['action' => 'accept'])->assertUnprocessable();
    expect(Transaction::query()->where('type', 'sell')->count())->toBe(1);
});

test('credit sales, discounts, other methods and accounts that are not cash or bank are refused and can be corrected and sent again', function () {
    $scope = poScope();
    Sanctum::actingAs(fcoStaff($scope, PO_POS));
    $uuid = 'uuid-retry-0000000001';

    $this->postJson('/api/pos/offline/sync', poSale($scope, ['client_uuid' => $uuid, 'payments' => [['method' => 'cash', 'payment_account' => $scope['cash_account'], 'amount' => 40]]]))->assertUnprocessable();
    $this->postJson('/api/pos/offline/sync', poSale($scope, ['client_uuid' => $uuid, 'payments' => [['method' => 'loyalty', 'payment_account' => $scope['cash_account'], 'amount' => 100]]]))->assertUnprocessable();
    $this->postJson('/api/pos/offline/sync', poSale($scope, ['client_uuid' => $uuid, 'payments' => [['method' => 'cash', 'payment_account' => 999999, 'amount' => 100]]]))->assertUnprocessable();

    $discounted = poSale($scope, ['client_uuid' => $uuid]);
    $discounted['lines'][0]['discount_percent'] = 10;
    $this->postJson('/api/pos/offline/sync', $discounted)->assertUnprocessable();
    expect(Transaction::query()->where('type', 'sell')->count())->toBe(0);

    // corrected under the same UUID
    $this->postJson('/api/pos/offline/sync', poSale($scope, ['client_uuid' => $uuid]))->assertOk()->assertJsonPath('status', 'synced');
    expect(Transaction::query()->where('type', 'sell')->count())->toBe(1);
});

test('a sale made in a period that is locked now is parked and a supervisor posts it on today', function () {
    $scope = poScope();
    fcoLockThrough($scope['company_id'], now()->subDay()->toDateString());
    Sanctum::actingAs(fcoStaff($scope, PO_SUPERVISOR));

    $parked = $this->postJson('/api/pos/offline/sync', poSale($scope, ['sold_at' => now()->subDays(5)->toIso8601String()]))->assertOk();
    expect($parked->json('status'))->toBe('conflict')->and($parked->json('conflict_code'))->toBe('period_locked');

    $done = $this->postJson('/api/pos/offline/sales/'.$parked->json('id').'/resolve', ['action' => 'accept'])->assertOk();
    $date = Transaction::query()->findOrFail($done->json('transaction_id'))->transaction_date->toDateString();
    expect($done->json('status'))->toBe('synced')->and($date)->toBe(now()->toDateString());
});

test('offline sales are company scoped: the body company is ignored, another company product is parked, and review needs permission', function () {
    $scope = poScope();
    $other = trpScope('8');
    $foreign = prdProduct($other, 'Foreign Item');
    Sanctum::actingAs(fcoStaff($scope, PO_POS));

    $sale = poSale($scope, ['company_id' => $other['company_id'], 'branch_id' => $other['branch_id']]);
    $sale['lines'][0]['variation_id'] = $foreign['variation_id'];
    $sale['lines'][0]['product_id'] = $foreign['product_id'];

    $parked = $this->postJson('/api/pos/offline/sync', $sale)->assertOk();
    expect($parked->json('conflict_code'))->toBe('product_missing')
        ->and((int) DB::table('pos_offline_sales')->value('company_id'))->toBe((int) $scope['company_id']);

    // a supervisor of the other company cannot see or resolve it
    Sanctum::actingAs(fcoStaff($other, PO_SUPERVISOR));
    $this->getJson('/api/pos/offline/sales')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson('/api/pos/offline/sales/'.$parked->json('id').'/resolve', ['action' => 'discard'])->assertNotFound();

    // the cashier may sync but not resolve
    Sanctum::actingAs(fcoStaff($scope, PO_POS));
    $this->postJson('/api/pos/offline/sales/'.$parked->json('id').'/resolve', ['action' => 'discard'])->assertForbidden();

    // and without the POS permission there is no catalog or sync
    Sanctum::actingAs(fcoStaff($scope, ['/posoffline']));
    $this->getJson('/api/pos/offline/catalog')->assertForbidden();
    $this->postJson('/api/pos/offline/sync', poSale($scope))->assertForbidden();
});
