<?php

use App\Models\Currency;
use App\Services\MultiCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Task C, item 7: optional multi-currency vouchers (default off): currency, rate and base amount on a voucher line, dated rates,
 * and the realised gain or loss only when a foreign line is settled.
 */
const FC_PERMS = ['/foreigncurrency', '/foreigncurrency/settings', '/foreigncurrency/rates', '/foreigncurrency/settle', '/journalentry', '/journalentry/add'];

function fcSetup(array $scope): array
{
    $usd = Currency::query()->create(['currency_name' => 'US Dollar', 'code' => 'USD', 'symbol' => '$', 'is_active' => true]);
    $eur = Currency::query()->create(['currency_name' => 'Euro', 'code' => 'EUR', 'symbol' => 'E', 'is_active' => true]);
    DB::table('company_settings')->where('company_id', $scope['company_id'])->update(['currency_id' => $usd->id]);

    return ['usd' => $usd->id, 'eur' => $eur->id];
}

function fcInvoiceVoucher(array $scope, int $currency, array $extra = []): array
{
    return [
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'voucher_type' => 'JV', 'voucher_date' => '2026-10-01', 'comments' => 'Foreign sale',
        'taccountdetails' => [
            ['account_id' => $scope['cash_id'], 'description' => 'Receivable', 'debit' => 1000, 'credit' => 0, 'contact_id' => $scope['customer_id'], 'currency_id' => $currency, 'foreign_amount' => 900, 'exchange_rate' => 0.9] + $extra,
            ['account_id' => $scope['revenue_id'], 'description' => 'Revenue', 'debit' => 0, 'credit' => 1000],
        ],
    ];
}

test('with the switch off a voucher line ignores its currency fields and the ledger is exactly as before', function () {
    $scope = fcoScope();
    $currencies = fcSetup($scope);
    Sanctum::actingAs(fcoStaff($scope, FC_PERMS));

    expect(DB::table('company_settings')->where('company_id', $scope['company_id'])->value('multi_currency_vouchers'))->toEqual(0);

    $this->postJson('/api/journal-entries', fcInvoiceVoucher($scope, $currencies['eur']))->assertSuccessful();

    $line = DB::table('t_account_details')->where('coa_id', $scope['cash_id'])->first();
    expect($line->currency_id)->toBeNull()->and($line->foreign_amount)->toBeNull()->and((float) $line->debit)->toBe(1000.0)
        ->and(fcoTrialBalance($scope['company_id']))->toBe(['debit' => 1000.0, 'credit' => 1000.0]);
});

test('with it on a line keeps its currency, rate and foreign amount beside the base amount, and a wrong base is refused', function () {
    $scope = fcoScope();
    $currencies = fcSetup($scope);
    Sanctum::actingAs(fcoStaff($scope, FC_PERMS));
    $this->putJson('/api/foreign-currency/settings', ['enabled' => true, 'fx_gain_loss_account_id' => $scope['fx_id']])->assertOk();

    $this->postJson('/api/journal-entries', fcInvoiceVoucher($scope, $currencies['eur']))->assertSuccessful();

    $line = DB::table('t_account_details')->where('coa_id', $scope['cash_id'])->first();
    expect((int) $line->currency_id)->toBe($currencies['eur'])->and((float) $line->foreign_amount)->toBe(900.0)->and((float) $line->exchange_rate)->toBe(0.9)
        ->and((float) $line->debit)->toBe(1000.0);

    // 900 at 0.9 is 1000, not 1100
    $wrong = fcInvoiceVoucher($scope, $currencies['eur']);
    $wrong['taccountdetails'][0]['debit'] = 1100;
    $wrong['taccountdetails'][1]['credit'] = 1100;
    $this->postJson('/api/journal-entries', $wrong)->assertUnprocessable();

    // the base currency is not a foreign currency
    $this->postJson('/api/journal-entries', fcInvoiceVoucher($scope, $currencies['usd']))->assertUnprocessable();
});

test('a gain or loss is posted only when a foreign line is settled, to the exchange account, and the party nets to nil', function () {
    $scope = fcoScope();
    $currencies = fcSetup($scope);
    Sanctum::actingAs(fcoStaff($scope, FC_PERMS));
    $this->putJson('/api/foreign-currency/settings', ['enabled' => true, 'fx_gain_loss_account_id' => $scope['fx_id']])->assertOk();
    $this->postJson('/api/journal-entries', fcInvoiceVoucher($scope, $currencies['eur'], ['account_id' => $scope['cash_id']]))->assertSuccessful();

    // nothing was gained or lost at entry
    expect(fcoBalance($scope['fx_id']))->toBe(0.0);
    $open = $this->getJson('/api/foreign-currency')->assertOk()->json('open_lines');
    expect($open)->toHaveCount(1)->and($open[0]['open_foreign'])->toEqual(900);
    $detail = $open[0]['id'];

    // 400 of the 900 settled at 0.8: carried at 444.44, now worth 500.00: a gain of 55.56
    $first = $this->postJson('/api/foreign-currency/settle', ['detail_id' => $detail, 'foreign_amount' => 400, 'settlement_rate' => 0.8, 'settled_on' => '2026-10-10'])->assertCreated();
    expect($first->json('data.fx_amount'))->toEqual(55.56)->and(fcoBalance($scope['fx_id']))->toBe(-55.56)
        ->and(fcoBalance($scope['cash_id']))->toBe(1055.56);

    // the other 500 at 1.0: carried at 555.56, now 500.00: a loss of 55.56
    $this->postJson('/api/foreign-currency/settle', ['detail_id' => $detail, 'foreign_amount' => 501, 'settlement_rate' => 1, 'settled_on' => '2026-10-11'])->assertUnprocessable();
    $second = $this->postJson('/api/foreign-currency/settle', ['detail_id' => $detail, 'foreign_amount' => 500, 'settlement_rate' => 1, 'settled_on' => '2026-10-11'])->assertCreated();
    expect($second->json('data.fx_amount'))->toEqual(-55.56)->and(fcoBalance($scope['fx_id']))->toBe(0.0)
        ->and($this->getJson('/api/foreign-currency')->json('open_lines'))->toHaveCount(0)
        ->and(fcoTrialBalance($scope['company_id'])['debit'])->toBe(fcoTrialBalance($scope['company_id'])['credit']);
});

test('settlement respects the period lock, the company and the exchange account being set', function () {
    $scope = fcoScope();
    $other = fcoOtherCompany();
    $currencies = fcSetup($scope);
    Sanctum::actingAs(fcoStaff($scope, FC_PERMS));
    $this->putJson('/api/foreign-currency/settings', ['enabled' => true])->assertOk();
    $this->postJson('/api/journal-entries', fcInvoiceVoucher($scope, $currencies['eur']))->assertSuccessful();
    $detail = $this->getJson('/api/foreign-currency')->json('open_lines.0.id');

    $this->postJson('/api/foreign-currency/settle', ['detail_id' => $detail, 'foreign_amount' => 100, 'settlement_rate' => 0.8, 'settled_on' => '2026-10-10'])->assertUnprocessable();

    $this->putJson('/api/foreign-currency/settings', ['enabled' => true, 'fx_gain_loss_account_id' => $scope['fx_id']])->assertOk();
    fcoLockThrough($scope['company_id'], '2026-10-31');
    $this->postJson('/api/foreign-currency/settle', ['detail_id' => $detail, 'foreign_amount' => 100, 'settlement_rate' => 0.8, 'settled_on' => '2026-10-10'])->assertStatus(422);
    expect(DB::table('fx_settlements')->count())->toBe(0);

    // another company cannot reach the line
    Sanctum::actingAs(fcoStaff($other, FC_PERMS));
    $this->postJson('/api/foreign-currency/settle', ['detail_id' => $detail, 'foreign_amount' => 100, 'settlement_rate' => 0.8, 'settled_on' => '2026-11-10'])->assertUnprocessable();
    $this->getJson('/api/foreign-currency')->assertOk()->assertJsonCount(0, 'open_lines');
});

test('dated rates take the latest rate on or before the day and fall back to the current rate', function () {
    $scope = fcoScope();
    $currencies = fcSetup($scope);
    Sanctum::actingAs(fcoStaff($scope, FC_PERMS));
    DB::table('currency_rates')->insert(['company_id' => $scope['company_id'], 'currency_id' => $currencies['eur'], 'rate' => 0.95, 'created_at' => now(), 'updated_at' => now()]);
    $this->postJson('/api/foreign-currency/rates', ['currency_id' => $currencies['eur'], 'effective_on' => '2026-10-01', 'rate' => 0.9])->assertCreated();
    $this->postJson('/api/foreign-currency/rates', ['currency_id' => $currencies['eur'], 'effective_on' => '2026-10-15', 'rate' => 0.8])->assertCreated();
    $this->postJson('/api/foreign-currency/rates', ['currency_id' => $currencies['usd'], 'effective_on' => '2026-10-15', 'rate' => 1])->assertUnprocessable();

    $rate = fn (string $day): ?float => MultiCurrency::rateOn($scope['company_id'], $currencies['eur'], $day);
    expect($rate('2026-09-01'))->toBe(0.95)->and($rate('2026-10-05'))->toBe(0.9)->and($rate('2026-10-20'))->toBe(0.8);
});
