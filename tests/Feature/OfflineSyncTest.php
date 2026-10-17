<?php

use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Offline mode on the server: every kind of entry is replayed once through the real controllers as the user who made it, a repeat posts
 * nothing, a refusal is kept as a conflict with its reason and never lost, and one company never sees another's entries.
 */
const OFFLINE_PERMS = ['/offline', '/sell/add', '/sell/payment/add', '/purchase/add', '/purchase/payment/add', '/expense/add', '/customer/add', '/supplier/add', '/stockadjustment/add'];

function offlineWorld(): array
{
    $scope = seedSellScope();
    $cash = insertPurchaseChartAccount($scope, '111-00001', 'Cash in Hand', 'dr', false);
    insertPurchaseAccountMapping($scope, 'Cash', 'cash', $cash);
    $expense = insertPurchaseChartAccount($scope, '601-00001', 'Office Expense', 'dr', true);

    return $scope + ['cash_id' => $cash, 'expense_id' => $expense, 'user' => fcoStaff($scope, OFFLINE_PERMS, true)];
}

function offlineSetting(int $companyId, array $values): void
{
    $setting = CompanySetting::query()->firstOrCreate(['company_id' => $companyId], CompanySetting::defaultSettingsAttributes('Test'));
    $setting->forceFill($values)->save();
}

function offlineSale(array $scope, array $extra = []): array
{
    return array_replace_recursive([
        'client_uuid' => 'uuid-'.uniqid(), 'kind' => 'sale', 'device_id' => 'D1', 'provisional_ref' => 'OFF-D1-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'payload' => [
            'contact_id' => $scope['contact_id'], 'date' => '2026-10-17',
            'lines' => [['product_id' => $scope['product_id'], 'variation_id' => $scope['variation_id'], 'unit_id' => $scope['unit_id'], 'quantity' => 2, 'unit_price' => 120]],
        ],
    ], $extra);
}

test('a cash sale entry posts the sale and its payment once, and a repeat posts nothing', function () {
    $scope = offlineWorld();
    Sanctum::actingAs($scope['user']);
    $entry = offlineSale($scope, ['payload' => ['payment' => ['method' => 'cash', 'payment_account' => $scope['cash_id'], 'amount' => 240]]]);

    $first = $this->postJson('/api/offline/sync', $entry)->assertOk()->assertJsonPath('status', 'synced')->assertJsonPath('repeat', false);
    expect($first->json('real_ref'))->not->toBeNull()->and($first->json('provisional_ref'))->toBe($entry['provisional_ref']);

    $sale = Transaction::query()->findOrFail($first->json('result_id'));
    expect($sale->type)->toBe('sell')->and($sale->status)->toBe('final')->and((float) $sale->final_amount)->toBe(240.0)->and($sale->payment_status)->toBe('paid');

    $this->postJson('/api/offline/sync', $entry)->assertOk()->assertJsonPath('repeat', true)->assertJsonPath('real_ref', $first->json('real_ref'));
    expect(Transaction::query()->sells()->count())->toBe(1)->and(DB::table('payments')->count())->toBe(1)->and(DB::table('offline_sync_entries')->count())->toBe(1);
});

test('the figures of an offline sale are the same as the same sale saved online', function () {
    $scope = offlineWorld();
    $online = $scope['user'];
    Sanctum::actingAs($online);

    $this->postJson('/api/sells', validSellPayload($scope, ['additional_note' => 'online', 'shipping_charges' => 0, 'final_amount' => 240, 'transaction_date' => '2026-10-17']))->assertSuccessful();
    $this->postJson('/api/offline/sync', offlineSale($scope))->assertOk()->assertJsonPath('status', 'synced');

    $sales = Transaction::query()->sells()->orderBy('id')->get();
    expect($sales)->toHaveCount(2)->and((float) $sales[0]->final_amount)->toBe((float) $sales[1]->final_amount);

    $figures = fn (Transaction $sale): object => DB::table('t_account_details as d')->join('t_accounts as t', 't.id', '=', 'd.t_account_id')
        ->where('t.company_id', $scope['company_id'])->where('t.transaction_id', $sale->id)->selectRaw('round(sum(d.debit), 2) as debit, round(sum(d.credit), 2) as credit, count(*) as lines')->first();
    $left = $figures($sales[0]);
    $right = $figures($sales[1]);

    expect((float) $right->debit)->toBe((float) $left->debit)->and((float) $right->credit)->toBe((float) $left->credit)->and((int) $right->lines)->toBe((int) $left->lines);
    expect((float) DB::table('sell_lines')->where('transaction_id', $sales[1]->id)->sum('quantity'))->toBe((float) DB::table('sell_lines')->where('transaction_id', $sales[0]->id)->sum('quantity'));
});

test('a purchase, a receipt, a payment, an expense, a contact and a pending stock adjustment are each posted once', function () {
    $scope = offlineWorld();
    Sanctum::actingAs($scope['user']);
    $send = fn (array $entry) => $this->postJson('/api/offline/sync', $entry + ['client_uuid' => 'uuid-'.uniqid(), 'device_id' => 'D1', 'provisional_ref' => 'OFF-D1-'.random_int(100000, 999999)]);

    // a purchase with the cash paid on the spot
    $purchase = $send(['kind' => 'purchase', 'payload' => [
        'contact_id' => $scope['contact_id'], 'date' => '2026-10-17', 'lines' => [['product_id' => $scope['product_id'], 'variation_id' => $scope['variation_id'], 'unit_id' => $scope['unit_id'], 'quantity' => 3, 'unit_cost' => 100]],
        'payment' => ['method' => 'cash', 'payment_account' => $scope['cash_id'], 'amount' => 100],
    ]])->assertOk()->assertJsonPath('status', 'synced');
    $bill = Transaction::query()->findOrFail($purchase->json('result_id'));
    expect($bill->type)->toBe('purchaseorder')->and((float) $bill->final_amount)->toBe(300.0)->and($bill->payment_status)->toBe('partial');

    // a receipt for a sale made online, and a payment for the bill above
    $sale = createSellRecord($scope, ['invoice_no' => 'INV-OFF-1', 'final_amount' => 120]);
    $receipt = $send(['kind' => 'payment', 'payload' => ['direction' => 'receipt', 'transaction_id' => $sale->id, 'amount' => 120, 'method' => 'cash', 'payment_account' => $scope['cash_id'], 'date' => '2026-10-17']])
        ->assertOk()->assertJsonPath('status', 'synced');
    expect($sale->fresh()->payment_status)->toBe('paid')->and($receipt->json('real_ref'))->toBe('INV-OFF-1');
    $send(['kind' => 'payment', 'payload' => ['direction' => 'payment', 'transaction_id' => $bill->id, 'amount' => 200, 'method' => 'cash', 'payment_account' => $scope['cash_id'], 'date' => '2026-10-17']])
        ->assertOk()->assertJsonPath('status', 'synced');
    expect($bill->fresh()->payment_status)->toBe('paid');

    // an expense voucher
    $expense = $send(['kind' => 'expense', 'payload' => ['date' => '2026-10-17', 'expense_account_id' => $scope['expense_id'], 'payment_account_id' => $scope['cash_id'], 'amount' => 75, 'description' => 'Tea and sugar']])
        ->assertOk()->assertJsonPath('status', 'synced');
    expect($expense->json('real_ref'))->toStartWith('EXP');

    // a new customer and a new supplier
    $customer = $send(['kind' => 'contact', 'payload' => ['type' => 'customer', 'business_name' => 'Offline Customer', 'first_name' => 'Ali', 'mobile' => '0300111', 'address' => 'Street 1', 'ntn_number' => '123456']])
        ->assertOk()->assertJsonPath('status', 'synced');
    $send(['kind' => 'contact', 'payload' => ['type' => 'supplier', 'business_name' => 'Offline Supplier', 'first_name' => 'Bashir', 'mobile' => '0300222', 'address' => 'Street 2', 'ntn_number' => '654321']])
        ->assertOk()->assertJsonPath('status', 'synced');
    expect(Contact::query()->findOrFail($customer->json('result_id'))->business_name)->toBe('Offline Customer')
        ->and(Contact::query()->where('business_name', 'Offline Supplier')->value('user_type'))->toBe('supplier');

    // a stock adjustment, always pending
    $adjustment = $send(['kind' => 'stock_adjustment', 'payload' => ['date' => '2026-10-17', 'note' => 'Count', 'lines' => [['product_id' => $scope['product_id'], 'variation_id' => $scope['variation_id'], 'unit_id' => $scope['unit_id'], 'quantity' => 4]]]])
        ->assertOk()->assertJsonPath('status', 'synced');
    expect(Transaction::query()->findOrFail($adjustment->json('result_id'))->status)->toBe('pending');
});

test('a company-level user with no branch of their own gets the company data and posts into the first branch', function () {
    $scope = offlineWorld();
    $user = $scope['user'];
    DB::table('users')->where('id', $user->id)->update(['branch_id' => null]);
    Sanctum::actingAs($user->fresh());

    $reference = $this->getJson('/api/offline/reference')->assertOk()->json('data');
    expect($reference['contacts'])->not->toBeEmpty()->and($reference['payment_accounts'])->not->toBeEmpty()->and($reference['branch_id'])->toBe((int) $scope['branch_id']);

    $entry = offlineSale($scope, ['payload' => ['payment' => ['method' => 'cash', 'payment_account' => $scope['cash_id'], 'amount' => 240]]]);
    $result = $this->postJson('/api/offline/sync', $entry)->assertOk()->assertJsonPath('status', 'synced');
    $sale = Transaction::query()->findOrFail($result->json('result_id'));

    expect((int) $sale->branch_id)->toBe((int) $scope['branch_id'])->and((int) DB::table('offline_sync_entries')->value('branch_id'))->toBe((int) $scope['branch_id']);
});
