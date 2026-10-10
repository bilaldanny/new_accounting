<?php

use App\Models\Payment;
use App\Models\TAccount;
use App\Models\TAccountDetail;
use App\Models\Tax;
use App\Models\TaxExemption;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Reports\ProfitLossReport;
use App\Services\Reports\TaxReport;
use App\Services\Reports\TrialBalanceReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Sell and purchase returns give back the tax of the lines they return, in proportion, on the same tax, and post it back on the
 * output / input tax account. Untaxed and legacy (header-tax only) documents are returned exactly as they always were.
 */
beforeEach(function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
});

function rtTax(int $companyId, string $name, float $rate, array $extra = []): int
{
    return Tax::query()->create(array_merge(['company_id' => $companyId, 'name' => $name, 'percentage' => $rate, 'type' => 0, 'status' => true], $extra))->id;
}

/**
 * A sale made through the API (2 x 120 = 240, shipping 40), then marked issued so that it can be returned.
 *
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $overrides
 */
function rtIssuedSell(array $scope, ?int $taxId, array $overrides = []): Transaction
{
    $payload = validSellPayload($scope, $overrides);

    if ($taxId !== null) {
        $payload['selllines'][0]['tax_id'] = $taxId;
    }

    test()->postJson('/api/sells', $payload)->assertSuccessful();
    $sell = Transaction::query()->sells()->latest('id')->firstOrFail();
    $sell->update(['status' => 'issue']);
    $sell->selllines()->update(['quantity_issue' => DB::raw('quantity')]);

    return $sell->fresh(['selllines']);
}

/**
 * A purchase made through the API (2 x 100 = 200, shipping 50), then marked received so that it can be returned.
 *
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $overrides
 */
function rtReceivedPurchase(array $scope, ?int $taxId, array $overrides = []): Transaction
{
    $payload = validPurchasePayload($scope, $overrides);

    if ($taxId !== null) {
        $payload['purchaselines'][0]['tax_id'] = $taxId;
    }

    test()->postJson('/api/purchases', $payload)->assertSuccessful();
    $purchase = Transaction::query()->purchases()->latest('id')->firstOrFail();
    $purchase->update(['status' => 'received']);
    $purchase->purchaselines()->update(['quantity_received' => DB::raw('quantity')]);

    return $purchase->fresh(['purchaselines']);
}

function rtReturnSell(array $scope, Transaction $sell, float $quantity): Transaction
{
    test()->postJson('/api/sell-returns', validSellReturnPayload($scope, $sell, [
        'selllines' => [['id' => $sell->selllines->first()->id, 'quantity_returned' => $quantity]],
    ]))->assertSuccessful();

    return Transaction::query()->sellReturns()->where('parent_id', $sell->id)->firstOrFail();
}

function rtReturnPurchase(array $scope, Transaction $purchase, float $quantity): Transaction
{
    test()->postJson('/api/purchase-returns', validPurchaseReturnPayload($scope, $purchase, [
        'purchaselines' => [['id' => $purchase->purchaselines->first()->id, 'quantity_returned' => $quantity]],
    ]))->assertSuccessful();

    return Transaction::query()->purchaseReturns()->where('parent_id', $purchase->id)->firstOrFail();
}

/**
 * @return array<string, array{debit: float, credit: float}>
 */
function rtLegs(Transaction $return): array
{
    $journal = TAccount::query()->where('transaction_id', $return->id)->firstOrFail();

    return TAccountDetail::query()->where('t_account_id', $journal->id)->get()
        ->mapWithKeys(fn ($row) => [(string) $row->account_code => ['debit' => (float) $row->debit, 'credit' => (float) $row->credit]])->all();
}

// ------------------------------------------------------------------ untaxed and legacy documents

test('an untaxed sell return is the quantity times the rate, with no tax, and now reaches the ledger', function () {
    $scope = seedSellScope();
    $sell = rtIssuedSell($scope, null);

    $return = rtReturnSell($scope, $sell, 1);

    expect((float) $return->final_amount)->toBe(120.0)
        ->and((float) $return->tax_amount)->toBe(0.0)
        ->and(rtLegs($return))->toBe([
            $scope['local_sales_code'] => ['debit' => 120.0, 'credit' => 0.0],
            $scope['customer_code'] => ['debit' => 0.0, 'credit' => 120.0],
        ]);
});

test('an untaxed purchase return is the quantity times the rate, with no tax, and now reaches the ledger', function () {
    $scope = seedPurchaseScope();
    $purchase = rtReceivedPurchase($scope, null);

    $return = rtReturnPurchase($scope, $purchase, 1);

    expect((float) $return->final_amount)->toBe(100.0)
        ->and((float) $return->tax_amount)->toBe(0.0)
        ->and(rtLegs($return))->toBe([
            $scope['supplier_code'] => ['debit' => 100.0, 'credit' => 0.0],
            $scope['local_purchase_code'] => ['debit' => 0.0, 'credit' => 100.0],
        ]);
});

test('a legacy document, taxed only in its header, is returned for its goods only, as before, with no tax leg', function () {
    $scope = seedSellScope();
    $sell = rtIssuedSell($scope, null, ['final_amount' => 308, 'tax_amount' => 28]);

    expect((float) $sell->tax_amount)->toBe(28.0)->and($sell->selllines->first()->tax_id)->toBeNull();

    $return = rtReturnSell($scope, $sell, 1);

    expect((float) $return->final_amount)->toBe(120.0)
        ->and((float) $return->tax_amount)->toBe(0.0)
        ->and(array_keys(rtLegs($return)))->not->toContain($scope['output_tax_code']);
});

test('a return of a document that was never journalled posts nothing, so its customer is still read from the documents', function () {
    $scope = seedSellScope();
    $sell = rtIssuedSell($scope, null);
    TAccount::query()->where('transaction_id', $sell->id)->delete();

    $return = rtReturnSell($scope, $sell, 1);

    expect((float) $return->final_amount)->toBe(120.0)
        ->and(TAccount::query()->where('transaction_id', $return->id)->exists())->toBeFalse();
});

test('the trial balance and the profit and loss move by exactly the return, the books staying balanced', function () {
    $scope = seedSellScope();
    $sell = rtIssuedSell($scope, null);
    $filters = ['start_date' => '2026-01-01', 'end_date' => '2026-12-31'];
    $trial = fn (): array => app(TrialBalanceReport::class)->rows((int) $scope['company_id'], null, $filters)
        ->mapWithKeys(fn (array $row): array => [(string) $row['code'] => [(float) $row['closing_debit'], (float) $row['closing_credit']]])->all();
    $profit = fn (): float => (float) app(ProfitLossReport::class)->build((int) $scope['company_id'], null, $filters)['summary']['net_profit'];

    $before = ['trial' => $trial(), 'profit' => $profit()];
    $return = rtReturnSell($scope, $sell, 1);
    $after = ['trial' => $trial(), 'profit' => $profit()];

    // The sale posted 280 to the customer (240 goods + 40 shipping) and 280 to sales; a return of one unit takes 120 off both
    // (this fixture's sales account sits in class 4, so the profit moves by the same 120).
    expect($before['trial'][$scope['customer_code']])->toBe([280.0, 0.0])
        ->and($after['trial'][$scope['customer_code']])->toBe([160.0, 0.0])
        ->and($before['profit'] - $after['profit'])->toBe(120.0)
        ->and(collect($after['trial'])->sum(0))->toBe(collect($after['trial'])->sum(1));

    // Deleting the return puts both reports back exactly.
    $this->deleteJson("/api/sell-returns/{$return->id}")->assertSuccessful();
    expect($trial())->toBe($before['trial'])->and($profit())->toBe($before['profit']);
});

// ------------------------------------------------------------------ restoring a deleted return

test('a deleted sell return comes back with its quantities and its voucher, and cannot come back twice over', function () {
    $scope = seedSellScope();
    $tax = rtTax($scope['company_id'], 'GST 10', 10);
    $sell = rtIssuedSell($scope, $tax);
    $return = rtReturnSell($scope, $sell, 2);

    $this->deleteJson("/api/sell-returns/{$return->id}")->assertSuccessful();
    expect((float) $sell->selllines()->first()->quantity_returned)->toBe(0.0)
        ->and(TAccount::query()->where('transaction_id', $return->id)->exists())->toBeFalse();

    $this->postJson('/api/sell-returns/restore_records', [$return->id])->assertSuccessful();

    expect((float) $sell->selllines()->first()->quantity_returned)->toBe(2.0)
        ->and(Transaction::query()->whereKey($return->id)->exists())->toBeTrue()
        ->and(rtLegs($return)[$scope['output_tax_code']])->toBe(['debit' => 24.0, 'credit' => 0.0]);

    // A second return of the same invoice blocks restoring an older deleted one.
    $this->deleteJson("/api/sell-returns/{$return->id}")->assertSuccessful();
    $this->postJson('/api/sell-returns', validSellReturnPayload($scope, $sell->fresh(), [
        'selllines' => [['id' => $sell->selllines->first()->id, 'quantity_returned' => 1]],
    ]))->assertSuccessful();
    $this->postJson('/api/sell-returns/restore_records', [$return->id])->assertStatus(422);
    expect(Transaction::onlyTrashed()->whereKey($return->id)->exists())->toBeTrue()
        ->and((float) $sell->selllines()->first()->quantity_returned)->toBe(1.0);
});

test('a deleted purchase return comes back with its quantities', function () {
    $scope = seedPurchaseScope();
    $purchase = rtReceivedPurchase($scope, null);
    $return = rtReturnPurchase($scope, $purchase, 2);

    $this->deleteJson("/api/purchase-returns/{$return->id}")->assertSuccessful();
    expect((float) $purchase->purchaselines()->first()->quantity_returned)->toBe(0.0);

    $this->postJson('/api/purchase-returns/restore_records', [$return->id])->assertSuccessful();

    expect((float) $purchase->purchaselines()->first()->quantity_returned)->toBe(2.0)
        ->and(rtLegs($return))->toBe([
            $scope['supplier_code'] => ['debit' => 200.0, 'credit' => 0.0],
            $scope['local_purchase_code'] => ['debit' => 0.0, 'credit' => 200.0],
        ]);
});

test('a return deleted before its quantities were kept is refused with a clear message, not restored empty', function () {
    $scope = seedSellScope();
    $sell = rtIssuedSell($scope, null);
    $return = rtReturnSell($scope, $sell, 1);
    $this->deleteJson("/api/sell-returns/{$return->id}")->assertSuccessful();
    DB::table('transactions')->where('id', $return->id)->update(['returned_lines' => null]);

    $this->postJson('/api/sell-returns/restore_records', [$return->id])->assertStatus(422)->assertSee('create a new return instead');
    expect(Transaction::onlyTrashed()->whereKey($return->id)->exists())->toBeTrue();
});

// ------------------------------------------------------------------ sell returns

test('a full sell return gives back the whole tax, on the same tax, and reverses it on the output tax account', function () {
    $scope = seedSellScope();
    $tax = rtTax($scope['company_id'], 'GST 10', 10);
    $sell = rtIssuedSell($scope, $tax);

    $return = rtReturnSell($scope, $sell, 2);

    // 240 of goods and 24 of tax were sold; shipping is not part of a return.
    expect((float) $return->total_before_tax)->toBe(240.0)
        ->and((float) $return->tax_amount)->toBe(24.0)
        ->and((float) $return->final_amount)->toBe(264.0)
        ->and($return->tax_inclusive)->toBeFalse()
        ->and(rtLegs($return))->toBe([
            $scope['local_sales_code'] => ['debit' => 240.0, 'credit' => 0.0],
            $scope['customer_code'] => ['debit' => 0.0, 'credit' => 264.0],
            $scope['output_tax_code'] => ['debit' => 24.0, 'credit' => 0.0],
        ]);
});

test('a partial sell return gives back the tax in proportion to the quantity, and editing it rewrites the voucher', function () {
    $scope = seedSellScope();
    $tax = rtTax($scope['company_id'], 'GST 10', 10);
    $sell = rtIssuedSell($scope, $tax);

    $return = rtReturnSell($scope, $sell, 1);

    expect((float) $return->tax_amount)->toBe(12.0)->and((float) $return->final_amount)->toBe(132.0)
        ->and(rtLegs($return)[$scope['output_tax_code']])->toBe(['debit' => 12.0, 'credit' => 0.0]);

    $this->putJson("/api/sell-returns/{$return->id}", ['selllines' => [['id' => $sell->selllines->first()->id, 'quantity_returned' => 2]]])->assertSuccessful();

    $return->refresh();
    expect((float) $return->tax_amount)->toBe(24.0)
        ->and(TAccount::query()->where('transaction_id', $return->id)->count())->toBe(1)
        ->and(rtLegs($return)[$scope['output_tax_code']])->toBe(['debit' => 24.0, 'credit' => 0.0]);

    $this->deleteJson("/api/sell-returns/{$return->id}")->assertSuccessful();
    expect(TAccount::query()->where('transaction_id', $return->id)->exists())->toBeFalse();
});

test('with inclusive prices the return is the goods value and the tax is carved out of it', function () {
    $scope = seedSellScope();
    $tax = rtTax($scope['company_id'], 'GST 10', 10);
    $sell = rtIssuedSell($scope, $tax, ['tax_inclusive' => true, 'tax_amount' => 21.82]);

    expect((float) $sell->tax_amount)->toBe(21.82);

    $partial = rtReturnSell($scope, $sell, 1);

    expect($partial->tax_inclusive)->toBeTrue()
        ->and((float) $partial->tax_amount)->toBe(10.91)
        ->and((float) $partial->total_before_tax)->toBe(109.09)
        ->and((float) $partial->final_amount)->toBe(120.0)
        ->and(rtLegs($partial)[$scope['customer_code']])->toBe(['debit' => 0.0, 'credit' => 120.0]);

    $this->putJson("/api/sell-returns/{$partial->id}", ['selllines' => [['id' => $sell->selllines->first()->id, 'quantity_returned' => 2]]])->assertSuccessful();
    expect((float) $partial->refresh()->tax_amount)->toBe(21.82)->and((float) $partial->final_amount)->toBe(240.0);
});

test('a compound tax is reversed as a whole, in proportion', function () {
    $scope = seedSellScope();
    $sales = rtTax($scope['company_id'], 'Sales tax', 5);
    $levy = rtTax($scope['company_id'], 'Levy', 10);
    $stacked = rtTax($scope['company_id'], 'Stacked', 15.5, ['type' => 1, 'compound' => true, 'sub_tax' => [$sales, $levy]]);
    $sell = rtIssuedSell($scope, $stacked);

    expect((float) $sell->tax_amount)->toBe(37.2);

    $return = rtReturnSell($scope, $sell, 1);

    expect((float) $return->tax_amount)->toBe(18.6)->and((float) $return->final_amount)->toBe(138.6);
});

test('a line that was exempt stays exempt on the return', function () {
    $scope = seedSellScope();
    $tax = rtTax($scope['company_id'], 'GST 10', 10);
    TaxExemption::query()->create([
        'company_id' => $scope['company_id'], 'name' => 'Exempt customer', 'scope' => TaxExemption::SCOPE_CUSTOMER,
        'contact_id' => $scope['contact_id'], 'tax_id' => $tax, 'is_active' => true,
    ]);
    $sell = rtIssuedSell($scope, $tax);

    expect((float) $sell->tax_amount)->toBe(0.0)->and($sell->selllines->first()->tax_exemption_id)->not->toBeNull();

    $return = rtReturnSell($scope, $sell, 1);

    expect((float) $return->tax_amount)->toBe(0.0)
        ->and((float) $return->final_amount)->toBe(120.0)
        ->and(array_keys(rtLegs($return)))->not->toContain($scope['output_tax_code']);
});

test('the tax report nets the original and its return, and the return shows its tax', function () {
    $scope = seedSellScope();
    $tax = rtTax($scope['company_id'], 'GST 10', 10);
    $sell = rtIssuedSell($scope, $tax);
    $return = rtReturnSell($scope, $sell, 1);

    $report = app(TaxReport::class);
    $rows = $report->rows((int) $scope['company_id'], null, []);
    $summary = $report->summary($rows);

    expect($summary['output_tax'])->toBe(12.0)
        ->and($summary['net_tax'])->toBe(12.0)
        ->and($rows->firstWhere('id', $return->id))->toMatchArray(['document' => 'Sell return', 'sign' => -1, 'tax_amount' => 12.0, 'total_before_tax' => 120.0, 'final_amount' => 132.0]);

    $this->getJson("/api/sell-returns/{$return->id}")->assertOk()->assertJsonPath('tax_amount', 12);
});

test('a return does not touch the withholding settlement of the sale', function () {
    $scope = seedSellScope();
    $receivable = insertPurchaseChartAccount($scope, '102-00050', 'WHT Receivable', 'dr', false);
    insertPurchaseAccountMapping($scope, 'Withholding Tax Receivable', 'withholdingreceivable', $receivable);
    $tax = rtTax($scope['company_id'], 'GST 10', 10);
    $wht = rtTax($scope['company_id'], 'WHT 4', 4, ['kind' => 'withholding']);
    $sell = rtIssuedSell($scope, $tax, ['withholding_tax_id' => $wht]);

    $before = Payment::query()->where('transaction_id', $sell->id)->where('is_withholding', true)->firstOrFail();

    $return = rtReturnSell($scope, $sell, 1);

    $after = Payment::query()->where('transaction_id', $sell->id)->where('is_withholding', true)->get();
    expect($after)->toHaveCount(1)
        ->and($after->first()->id)->toBe($before->id)
        ->and((float) $after->first()->amount)->toBe(11.2)
        ->and((float) $return->final_amount)->toBe(132.0);
});

// ------------------------------------------------------------------ purchase returns

test('a full purchase return gives back the whole input tax and reverses it on the input tax account', function () {
    $scope = seedPurchaseScope();
    $tax = rtTax($scope['company_id'], 'GST 10', 10);
    $purchase = rtReceivedPurchase($scope, $tax);

    expect((float) $purchase->tax_amount)->toBe(20.0);

    $return = rtReturnPurchase($scope, $purchase, 2);

    expect((float) $return->tax_amount)->toBe(20.0)
        ->and((float) $return->total_before_tax)->toBe(200.0)
        ->and((float) $return->final_amount)->toBe(220.0)
        ->and(rtLegs($return))->toBe([
            $scope['supplier_code'] => ['debit' => 220.0, 'credit' => 0.0],
            $scope['local_purchase_code'] => ['debit' => 0.0, 'credit' => 200.0],
            $scope['input_tax_code'] => ['debit' => 0.0, 'credit' => 20.0],
        ]);
});

test('a partial purchase return reverses the tax in proportion, and the tax report nets it', function () {
    $scope = seedPurchaseScope();
    $tax = rtTax($scope['company_id'], 'GST 10', 10);
    $purchase = rtReceivedPurchase($scope, $tax);

    $return = rtReturnPurchase($scope, $purchase, 1);

    $report = app(TaxReport::class);
    $summary = $report->summary($report->rows((int) $scope['company_id'], null, []));

    expect((float) $return->tax_amount)->toBe(10.0)
        ->and((float) $return->final_amount)->toBe(110.0)
        ->and($summary['input_tax'])->toBe(10.0);

    $this->deleteJson("/api/purchase-returns/{$return->id}")->assertSuccessful();
    expect(TAccount::query()->where('transaction_id', $return->id)->exists())->toBeFalse();
});

test('a purchase return with inclusive prices carves the tax out of the goods value', function () {
    $scope = seedPurchaseScope();
    $tax = rtTax($scope['company_id'], 'GST 10', 10);
    $purchase = rtReceivedPurchase($scope, $tax, ['tax_inclusive' => true]);

    $return = rtReturnPurchase($scope, $purchase, 1);

    // 200 holds 10% (181.82 + 18.18), so one of the two units, 100, holds 9.09.
    expect($return->tax_inclusive)->toBeTrue()
        ->and((float) $return->tax_amount)->toBe(9.09)
        ->and((float) $return->total_before_tax)->toBe(90.91)
        ->and((float) $return->final_amount)->toBe(100.0);
});
