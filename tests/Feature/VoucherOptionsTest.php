<?php

use App\Models\Currency;
use App\Models\CurrencyRate;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The optional project and foreign currency pickers of the sale, purchase, expense and journal forms: company scoped, empty
 * lists (the forms look as before) until a company has projects or switches multi-currency vouchers on.
 */
test('the forms are offered projects of their own company only, and no currency while multi-currency is off', function () {
    $scope = fcoScope();
    $other = fcoOtherCompany();
    Project::query()->create(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'code' => 'P-1', 'name' => 'Mine', 'status' => 'active']);
    Project::query()->create(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'code' => 'P-OLD', 'name' => 'Closed', 'status' => 'closed']);
    Project::query()->create(['company_id' => $other['company_id'], 'branch_id' => $other['branch_id'], 'code' => 'P-X', 'name' => 'Theirs', 'status' => 'active']);
    Sanctum::actingAs(fcoStaff($scope, ['/journalentry']));

    $data = $this->getJson('/api/voucher-options')->assertOk()->json();

    expect(collect($data['projects'])->pluck('text')->all())->toBe(['P-1 Mine'])->and($data['multi_currency'])->toBeFalse()->and($data['currencies'])->toBe([]);

    // a company_id in the query does not reach another company
    expect(collect($this->getJson('/api/voucher-options?company_id='.$other['company_id'])->json('projects'))->pluck('text')->all())->toBe(['P-1 Mine']);
});

test('with multi-currency on the foreign currencies with a rate are offered, the base currency and an unrated one are not', function () {
    $scope = fcoScope();
    $usd = Currency::query()->create(['currency_name' => 'US Dollar', 'code' => 'USD', 'symbol' => '$', 'is_active' => true]);
    $eur = Currency::query()->create(['currency_name' => 'Euro', 'code' => 'EUR', 'symbol' => 'E', 'is_active' => true]);
    Currency::query()->create(['currency_name' => 'Yen', 'code' => 'JPY', 'symbol' => 'Y', 'is_active' => true]);
    DB::table('company_settings')->where('company_id', $scope['company_id'])->update(['currency_id' => $usd->id, 'multi_currency_vouchers' => 1]);
    CurrencyRate::query()->create(['company_id' => $scope['company_id'], 'currency_id' => $eur->id, 'rate' => 0.9]);
    Sanctum::actingAs(fcoStaff($scope, ['/journalentry']));

    $data = $this->getJson('/api/voucher-options')->assertOk()->json();

    expect($data['multi_currency'])->toBeTrue()->and(collect($data['currencies'])->pluck('text')->all())->toBe(['EUR'])->and($data['currencies'][0]['rate'])->toEqual(0.9);
});
