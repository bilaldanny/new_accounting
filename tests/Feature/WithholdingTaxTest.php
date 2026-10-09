<?php

use App\Models\Company;
use App\Models\Payment;
use App\Models\Tax;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
});

function whTax(int $companyId, string $name, float $rate, array $extra = []): int
{
    return Tax::query()->create(array_merge(['company_id' => $companyId, 'name' => $name, 'percentage' => $rate, 'type' => 0, 'status' => true, 'kind' => 'withholding'], $extra))->id;
}

/**
 * @param  array<string, mixed>  $scope
 * @return array<string, mixed>
 */
function whSellScope(): array
{
    $scope = seedSellScope();
    $scope['wht_receivable_coa_id'] = insertPurchaseChartAccount($scope, '102-00050', 'WHT Receivable', 'dr', false);
    insertPurchaseAccountMapping($scope, 'Withholding Tax Receivable', 'withholdingreceivable', $scope['wht_receivable_coa_id']);

    return $scope;
}

function whLatest(string $type): Transaction
{
    return Transaction::query()->where('type', $type)->latest('id')->firstOrFail();
}

/**
 * @return array<string, array{debit: float, credit: float}>
 */
function whLegs(int $tAccountId): array
{
    return DB::table('t_account_details')->where('t_account_id', $tAccountId)->get()
        ->mapWithKeys(fn ($row) => [(string) $row->coa_id => ['debit' => (float) $row->debit, 'credit' => (float) $row->credit]])->all();
}

test('a sale with withholding tax books the withheld part as an asset, not cash, and the invoice shows it as settled', function () {
    $scope = whSellScope();
    $gst = Tax::query()->create(['company_id' => $scope['company_id'], 'name' => 'GST 10', 'percentage' => 10, 'type' => 0, 'status' => true])->id;
    $wht = whTax($scope['company_id'], 'WHT 4', 4);
    $payload = validSellPayload($scope, ['withholding_tax_id' => $wht]);
    $payload['selllines'][0]['tax_id'] = $gst;

    $this->postJson('/api/sells', $payload)->assertSuccessful();

    // 240 + 40 shipping = 280 before tax; GST 24; the customer owes 304 and withholds 4% of 280.
    $sell = whLatest('sell')->refresh();
    expect((float) $sell->final_amount)->toBe(304.0)
        ->and($sell->withholding_tax_id)->toBe($wht)
        ->and((float) $sell->withholding_amount)->toBe(11.2)
        ->and($sell->payment_status)->toBe('partial');

    $payment = Payment::query()->where('transaction_id', $sell->id)->firstOrFail();
    expect($payment->is_withholding)->toBeTrue()
        ->and((float) $payment->amount)->toBe(11.2)
        ->and($payment->payment_account)->toBe($scope['wht_receivable_coa_id'])
        ->and(whLegs((int) $payment->t_account_id))->toBe([
            (string) $scope['wht_receivable_coa_id'] => ['debit' => 11.2, 'credit' => 0.0],
            (string) $scope['customer_coa_id'] => ['debit' => 0.0, 'credit' => 11.2],
        ]);
    expect(Payment::remainingAmountForTransaction($sell))->toBe(292.8);
});

test('a withholding tax can be taken on the whole invoice instead of the value before sales tax', function () {
    $scope = whSellScope();
    $gst = Tax::query()->create(['company_id' => $scope['company_id'], 'name' => 'GST 10', 'percentage' => 10, 'type' => 0, 'status' => true])->id;
    $wht = whTax($scope['company_id'], 'WHT gross', 5, ['applies_on' => 'gross']);
    $payload = validSellPayload($scope, ['withholding_tax_id' => $wht]);
    $payload['selllines'][0]['tax_id'] = $gst;

    $this->postJson('/api/sells', $payload)->assertSuccessful();

    expect((float) whLatest('sell')->withholding_amount)->toBe(15.2);
});

test('editing a sale redoes its withholding settlement, and clearing the tax removes it', function () {
    $scope = whSellScope();
    $wht = whTax($scope['company_id'], 'WHT 4', 4);

    $this->postJson('/api/sells', validSellPayload($scope, ['withholding_tax_id' => $wht]))->assertSuccessful();
    $id = whLatest('sell')->id;
    expect(Payment::query()->where('transaction_id', $id)->count())->toBe(1);

    // A client that does not send the field keeps the tax; its amount follows the new total (4% of 380).
    $this->putJson("/api/sells/{$id}", validSellPayload($scope, ['final_amount' => 380]))->assertSuccessful();
    $payments = Payment::query()->where('transaction_id', $id)->get();
    expect($payments)->toHaveCount(1)->and((float) $payments->first()->amount)->toBe(15.2)->and((float) whLatest('sell')->withholding_amount)->toBe(15.2);

    $this->putJson("/api/sells/{$id}", validSellPayload($scope, ['withholding_tax_id' => '']))->assertSuccessful();
    expect(Payment::query()->where('transaction_id', $id)->count())->toBe(0)
        ->and(whLatest('sell')->payment_status)->toBe('due')
        ->and(whLatest('sell')->withholding_tax_id)->toBeNull();
});

test('a sale with withholding tax is refused until the receivable account is mapped, and nothing is saved', function () {
    $scope = seedSellScope();
    $wht = whTax($scope['company_id'], 'WHT 4', 4);
    $before = Transaction::query()->count();

    $this->postJson('/api/sells', validSellPayload($scope, ['withholding_tax_id' => $wht]))->assertUnprocessable()->assertJsonValidationErrors(['withholding_tax_id']);

    expect(Transaction::query()->count())->toBe($before)->and(Payment::query()->count())->toBe(0);
});

test('the refusal tells the user which account to map and where, for a sale and a draft sale', function () {
    $scope = seedSellScope();
    $wht = whTax($scope['company_id'], 'WHT 4', 4);

    $sale = $this->postJson('/api/sells', validSellPayload($scope, ['withholding_tax_id' => $wht]))->assertUnprocessable();
    expect($sale->json('errors.withholding_tax_id.0'))->toContain('Withholding Tax Receivable')->toContain('Settings > Company Settings > Link Accounts')->toContain('not set up for this branch');

    // A draft books nothing yet, but would fail the moment it is posted, so it is refused as well.
    $draft = $this->postJson('/api/sells', validSellPayload($scope, ['withholding_tax_id' => $wht, 'status' => 'draft']))->assertUnprocessable();
    expect($draft->json('errors.withholding_tax_id.0'))->toContain('Withholding Tax Receivable');

    expect(Transaction::query()->count())->toBe(0);
});

test('a purchase with withholding tax gets the same clear refusal naming the payable account', function () {
    $scope = seedPurchaseScope();
    $wht = whTax($scope['company_id'], 'WHT 4 P', 4);

    $purchase = $this->postJson('/api/purchases', validPurchasePayload($scope, ['withholding_tax_id' => $wht]))->assertUnprocessable();
    expect($purchase->json('errors.withholding_tax_id.0'))->toContain('Withholding Tax Payable')->toContain('this purchase')->toContain('Link Accounts');
    expect(Transaction::query()->count())->toBe(0);
});

test('a document without withholding tax is not affected by the missing account', function () {
    $scope = seedSellScope();

    $this->postJson('/api/sells', validSellPayload($scope))->assertSuccessful();
});

/**
 * @param  array<string, mixed>  $scope
 */
function whEmptyMappings(array $scope): void
{
    foreach ([['Withholding Tax Receivable', 'withholdingreceivable'], ['Withholding Tax Payable', 'withholdingpayable']] as [$name, $key]) {
        DB::table('chart_of_account_mappings')->insert(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'name' => $name, 'key' => $key, 'value' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
}

function whRunMappingMigration(string $direction = 'up'): void
{
    (require database_path('migrations/2026_10_27_100200_map_withholding_tax_accounts.php'))->{$direction}();
}

test('the mapping migration creates and maps both accounts under the right groups, and then a withholding sale saves', function () {
    $scope = seedSellScope();
    whEmptyMappings($scope);
    $assets = DB::table('chart_of_accounts')->insertGetId(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'code' => '200-00000', 'name' => 'Assets', 'acc_type' => 'c', 'acc_nature' => 'dr', 'pl' => 0, 'bs' => 1, 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $currentAssets = DB::table('chart_of_accounts')->insertGetId(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'parent_id' => $assets, 'code' => '210-00000', 'name' => 'Current Assets', 'acc_type' => 'c', 'acc_nature' => 'dr', 'pl' => 0, 'bs' => 1, 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $liabilities = DB::table('chart_of_accounts')->insertGetId(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'code' => '300-00000', 'name' => 'Liabilities', 'acc_type' => 'c', 'acc_nature' => 'cr', 'pl' => 0, 'bs' => 1, 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    whRunMappingMigration();

    $receivable = DB::table('chart_of_accounts')->where('name', 'Withholding Tax Receivable')->first();
    $payable = DB::table('chart_of_accounts')->where('name', 'Withholding Tax Payable')->first();
    $mapped = fn (string $key): ?string => DB::table('chart_of_account_mappings')->where('key', $key)->value('value');

    expect((int) $receivable->parent_id)->toBe($currentAssets)->and($receivable->acc_nature)->toBe('dr')->and($receivable->acc_type)->toBe('t')
        ->and((int) $payable->parent_id)->toBe($liabilities)->and($payable->acc_nature)->toBe('cr')
        ->and($mapped('withholdingreceivable'))->toBe((string) $receivable->id)
        ->and($mapped('withholdingpayable'))->toBe((string) $payable->id);

    $wht = whTax($scope['company_id'], 'WHT 4', 4);
    $this->postJson('/api/sells', validSellPayload($scope, ['withholding_tax_id' => $wht]))->assertSuccessful();
    expect(Payment::query()->where('is_withholding', true)->count())->toBe(1);

    // Running it again changes nothing.
    $accounts = DB::table('chart_of_accounts')->count();
    whRunMappingMigration();
    expect(DB::table('chart_of_accounts')->count())->toBe($accounts);
});

test('the mapping migration reuses an account of that name and never replaces a mapping that is already set', function () {
    $scope = seedSellScope();
    whEmptyMappings($scope);
    $existing = insertPurchaseChartAccount($scope, '102-00077', 'Withholding Tax Receivable', 'dr', false);
    $own = insertPurchaseChartAccount($scope, '311-00077', 'My Own Payable', 'cr', false);
    DB::table('chart_of_account_mappings')->where('key', 'withholdingpayable')->update(['value' => (string) $own]);

    whRunMappingMigration();

    expect(DB::table('chart_of_account_mappings')->where('key', 'withholdingreceivable')->value('value'))->toBe((string) $existing)
        ->and(DB::table('chart_of_account_mappings')->where('key', 'withholdingpayable')->value('value'))->toBe((string) $own)
        ->and(DB::table('chart_of_accounts')->where('name', 'Withholding Tax Receivable')->count())->toBe(1);
});

test('a branch without the groups to hang the accounts on is left unmapped rather than guessed', function () {
    $scope = seedSellScope();
    whEmptyMappings($scope);

    whRunMappingMigration();

    expect(DB::table('chart_of_account_mappings')->whereIn('key', ['withholdingreceivable', 'withholdingpayable'])->whereNotNull('value')->count())->toBe(0)
        ->and(DB::table('chart_of_accounts')->where('name', 'like', 'Withholding Tax%')->exists())->toBeFalse();
});

test('only an active withholding tax of the company can be used', function () {
    $scope = whSellScope();
    $other = Company::query()->create(['code' => 'CO-WH-02', 'name' => 'Other', 'is_active' => true, 'max_users' => 5, 'max_branches' => 1]);
    $sales = Tax::query()->create(['company_id' => $scope['company_id'], 'name' => 'GST', 'percentage' => 10, 'type' => 0, 'status' => true])->id;

    foreach ([whTax($other->id, 'Foreign', 4), whTax($scope['company_id'], 'Off', 4, ['status' => false]), $sales, 999999] as $bad) {
        $this->postJson('/api/sells', validSellPayload($scope, ['withholding_tax_id' => $bad]))->assertUnprocessable()->assertJsonValidationErrors(['withholding_tax_id']);
    }
});

test('a purchase books its withholding as a liability once it is approved, and again when approved after an edit', function () {
    $scope = seedPurchaseScope();
    $scope['wht_payable_coa_id'] = insertPurchaseChartAccount($scope, '311-00050', 'WHT Payable', 'cr', false);
    insertPurchaseAccountMapping($scope, 'Withholding Tax Payable', 'withholdingpayable', $scope['wht_payable_coa_id']);
    $wht = whTax($scope['company_id'], 'WHT 4', 4);

    $this->postJson('/api/purchases', validPurchasePayload($scope, ['withholding_tax_id' => $wht]))->assertSuccessful();
    $purchase = whLatest('purchaseorder');
    expect((float) $purchase->withholding_amount)->toBe(10.0)
        ->and(Payment::query()->where('transaction_id', $purchase->id)->count())->toBe(0);

    Transaction::approvePurchase($purchase->id);

    $payment = Payment::query()->where('transaction_id', $purchase->id)->firstOrFail();
    expect($payment->is_withholding)->toBeTrue()
        ->and((float) $payment->amount)->toBe(10.0)
        ->and(whLegs((int) $payment->t_account_id)[(string) $scope['wht_payable_coa_id']])->toBe(['debit' => 0.0, 'credit' => 10.0])
        ->and(whLatest('purchaseorder')->refresh()->payment_status)->toBe('partial');

    // Editing sends the purchase back to pending, so the settlement goes until it is approved again.
    $this->putJson("/api/purchases/{$purchase->id}", validPurchasePayload($scope, ['final_amount' => 500]))->assertSuccessful();
    expect(Payment::query()->where('transaction_id', $purchase->id)->count())->toBe(0);

    Transaction::approvePurchase($purchase->id);
    $payments = Payment::query()->where('transaction_id', $purchase->id)->get();
    expect($payments)->toHaveCount(1)->and((float) $payments->first()->amount)->toBe(20.0);
});

test('documents without withholding tax are untouched', function () {
    $scope = whSellScope();

    $this->postJson('/api/sells', validSellPayload($scope))->assertSuccessful();

    $sell = whLatest('sell');
    expect($sell->withholding_tax_id)->toBeNull()->and((float) $sell->withholding_amount)->toBe(0.0)->and(Payment::query()->count())->toBe(0);
});
