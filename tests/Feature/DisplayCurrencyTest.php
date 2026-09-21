<?php

use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\Currency;
use App\Models\CurrencyRate;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DisplayCurrency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Display-level currency: a company's exchange rates and the converted total an invoice shows. Everything stays
 * in the base currency; a rate only adds a second figure.
 *
 * @return array<string, mixed>
 */
function dcuScope(string $base = 'PKR'): array
{
    $scope = seedSellScope();
    $baseCurrency = Currency::query()->where('code', $base)->first() ?? Currency::query()->create(['currency_name' => 'Base '.$base, 'code' => $base, 'symbol' => 'Rs', 'is_active' => true]);
    (CompanySetting::query()->where('company_id', $scope['company_id'])->first() ?? CompanySetting::createCompanySettings((int) $scope['company_id']))
        ->forceFill(['currency_id' => $baseCurrency->id])->save();

    return array_merge($scope, ['base_id' => $baseCurrency->id]);
}

function dcuCurrency(string $code, string $symbol = '$', bool $active = true): Currency
{
    return Currency::query()->where('code', $code)->first() ?? Currency::query()->create(['currency_name' => $code.' money', 'code' => $code, 'symbol' => $symbol, 'is_active' => $active]);
}

/**
 * A finished sale of 1000 to the scope's customer, whose currency is set.
 *
 * @param  array<string, mixed>  $scope
 */
function dcuSale(array $scope, ?int $customerCurrencyId): Transaction
{
    Contact::query()->whereKey($scope['customer_id'])->update(['currency_id' => $customerCurrencyId]);

    return createSellRecord($scope, ['final_amount' => 1000]);
}

function dcuUser(int $companyId, array $paths): User
{
    $role = Role::query()->create(['name' => 'rates'.uniqid(), 'company_id' => $companyId, 'is_active' => true]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path, ltrim(str_replace('/', '', $path), '/').uniqid());
    }

    return createStaffUserForRole($role, ['company_id' => $companyId]);
}

// --- the converted total -------------------------------------------------------------------------

test('an invoice shows its total in the customer\'s currency when the company has a rate for it', function () {
    $scope = dcuScope();
    $usd = dcuCurrency('USD');
    CurrencyRate::query()->create(['company_id' => $scope['company_id'], 'currency_id' => $usd->id, 'rate' => 0.0036]);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $sale = dcuSale($scope, $usd->id);

    $show = $this->getJson('/api/sells/'.$sale->id)->assertSuccessful();

    expect($show->json('display_currency.code'))->toBe('USD')
        ->and($show->json('display_currency.base_code'))->toBe('PKR')
        ->and($show->json('display_currency.rate'))->toBe(0.0036)
        ->and($show->json('display_currency.total'))->toBe(3.6)
        ->and($show->json('display_currency.symbol'))->toBe('$')
        ->and((float) $sale->fresh()->final_amount)->toBe(1000.0);
});

test('the converted total is rounded to cents', function () {
    $scope = dcuScope();
    $eur = dcuCurrency('EUR', '€');
    CurrencyRate::query()->create(['company_id' => $scope['company_id'], 'currency_id' => $eur->id, 'rate' => 0.003333]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    expect($this->getJson('/api/sells/'.dcuSale($scope, $eur->id)->id)->json('display_currency.total'))->toBe(3.33);
});

test('nothing is shown without a customer currency, in the base currency, without a base or without a rate', function (string $case) {
    $scope = dcuScope();
    $usd = dcuCurrency('USD');
    Sanctum::actingAs(User::query()->findOrFail(1));

    match ($case) {
        'no customer currency' => $sale = dcuSale($scope, null),
        'the base currency itself' => $sale = dcuSale($scope, $scope['base_id']),
        'no rate entered' => $sale = dcuSale($scope, $usd->id),
        'a zero rate' => (function () use ($scope, $usd, &$sale): void {
            CurrencyRate::query()->create(['company_id' => $scope['company_id'], 'currency_id' => $usd->id, 'rate' => 0]);
            $sale = dcuSale($scope, $usd->id);
        })(),
        'no base currency' => (function () use ($scope, $usd, &$sale): void {
            CurrencyRate::query()->create(['company_id' => $scope['company_id'], 'currency_id' => $usd->id, 'rate' => 2]);
            CompanySetting::query()->where('company_id', $scope['company_id'])->update(['currency_id' => null]);
            $sale = dcuSale($scope, $usd->id);
        })(),
    };

    expect($this->getJson('/api/sells/'.$sale->id)->assertSuccessful()->json('display_currency'))->toBeNull();
})->with(['no customer currency', 'the base currency itself', 'no rate entered', 'a zero rate', 'no base currency']);

test('another company\'s rate is never used', function () {
    $scope = dcuScope();
    $usd = dcuCurrency('USD');
    $other = DB::table('companies')->insertGetId(['code' => 'DCU02', 'name' => 'Other Co', 'address' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    CurrencyRate::query()->create(['company_id' => $other, 'currency_id' => $usd->id, 'rate' => 5]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    expect($this->getJson('/api/sells/'.dcuSale($scope, $usd->id)->id)->json('display_currency'))->toBeNull()
        ->and(app(DisplayCurrency::class)->baseCurrencyId($other))->toBeNull();
});

// --- the rates -----------------------------------------------------------------------------------

test('the rates page lists the active currencies except the base, with the rates set', function () {
    $scope = dcuScope();
    $usd = dcuCurrency('USD');
    dcuCurrency('OLD', 'x', false);
    CurrencyRate::query()->create(['company_id' => $scope['company_id'], 'currency_id' => $usd->id, 'rate' => 0.0036]);
    Sanctum::actingAs(dcuUser((int) $scope['company_id'], ['/currencyrate']));

    $data = $this->getJson('/api/currency-rates')->assertSuccessful();
    $byCode = collect($data->json('currencies'))->keyBy('code');

    expect($data->json('base.code'))->toBe('PKR')
        ->and($byCode->has('PKR'))->toBeFalse()
        ->and($byCode->has('OLD'))->toBeFalse()
        ->and($byCode['USD']['rate'])->toBe(0.0036);
});

test('rates are saved, changed and removed, and rates not sent are left alone', function () {
    $scope = dcuScope();
    $usd = dcuCurrency('USD');
    $eur = dcuCurrency('EUR', '€');
    Sanctum::actingAs(dcuUser((int) $scope['company_id'], ['/currencyrate', '/currencyrate/update']));

    $this->putJson('/api/currency-rates', ['rates' => [['currency_id' => $usd->id, 'rate' => 0.0036], ['currency_id' => $eur->id, 'rate' => 0.0033]]])->assertSuccessful();
    expect(CurrencyRate::query()->count())->toBe(2);

    $this->putJson('/api/currency-rates', ['rates' => [['currency_id' => $usd->id, 'rate' => 0.004]]])->assertSuccessful();
    expect((float) CurrencyRate::query()->where('currency_id', $usd->id)->value('rate'))->toBe(0.004)
        ->and((float) CurrencyRate::query()->where('currency_id', $eur->id)->value('rate'))->toBe(0.0033);

    $this->putJson('/api/currency-rates', ['rates' => [['currency_id' => $eur->id, 'rate' => null]]])->assertSuccessful();
    expect(CurrencyRate::query()->count())->toBe(1);
});

test('bad rates are refused and nothing is saved', function (array $body) {
    $scope = dcuScope();
    $usd = dcuCurrency('USD');
    Sanctum::actingAs(dcuUser((int) $scope['company_id'], ['/currencyrate', '/currencyrate/update']));

    $body = json_decode(str_replace('"USD"', (string) $usd->id, json_encode($body)), true);

    $this->putJson('/api/currency-rates', $body)->assertUnprocessable();

    expect(CurrencyRate::query()->count())->toBe(0);
})->with([
    'zero' => [['rates' => [['currency_id' => '"USD"', 'rate' => 0]]]],
    'negative' => [['rates' => [['currency_id' => '"USD"', 'rate' => -1]]]],
    'text' => [['rates' => [['currency_id' => '"USD"', 'rate' => 'abc']]]],
    'too many decimals' => [['rates' => [['currency_id' => '"USD"', 'rate' => 0.1234567]]]],
    'huge' => [['rates' => [['currency_id' => '"USD"', 'rate' => 99999999999]]]],
    'an array rate' => [['rates' => [['currency_id' => '"USD"', 'rate' => [1]]]]],
    'no rates' => [['rates' => []]],
    'an unknown currency' => [['rates' => [['currency_id' => 999999, 'rate' => 1]]]],
    'the same currency twice' => [['rates' => [['currency_id' => '"USD"', 'rate' => 1], ['currency_id' => '"USD"', 'rate' => 2]]]],
]);

test('the base currency cannot be given a rate', function () {
    $scope = dcuScope();
    Sanctum::actingAs(dcuUser((int) $scope['company_id'], ['/currencyrate', '/currencyrate/update']));

    $this->putJson('/api/currency-rates', ['rates' => [['currency_id' => $scope['base_id'], 'rate' => 2]]])->assertUnprocessable();

    expect(CurrencyRate::query()->count())->toBe(0);
});

test('reading needs the page permission, saving the update permission, and a company user only touches their own company', function () {
    $scope = dcuScope();
    $usd = dcuCurrency('USD');
    $company = (int) $scope['company_id'];
    $other = DB::table('companies')->insertGetId(['code' => 'DCU03', 'name' => 'Third Co', 'address' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    Sanctum::actingAs(dcuUser($company, []));
    $this->getJson('/api/currency-rates')->assertForbidden();

    Sanctum::actingAs(dcuUser($company, ['/currencyrate']));
    $this->getJson('/api/currency-rates')->assertSuccessful();
    $this->putJson('/api/currency-rates', ['rates' => [['currency_id' => $usd->id, 'rate' => 2]]])->assertForbidden();

    // asking for another company's rates or saving into it changes the user's own company only
    Sanctum::actingAs(dcuUser($company, ['/currencyrate', '/currencyrate/update']));
    $this->putJson('/api/currency-rates', ['company_id' => $other, 'rates' => [['currency_id' => $usd->id, 'rate' => 2]]])->assertSuccessful();

    expect(CurrencyRate::query()->where('company_id', $company)->count())->toBe(1)
        ->and(CurrencyRate::query()->where('company_id', $other)->count())->toBe(0);

    $this->getJson('/api/currency-rates?company_id='.$other)->assertSuccessful()->assertJsonPath('company_id', $company);
});

test('a superadmin names the company, and gets an error without one', function () {
    $scope = dcuScope();
    $usd = dcuCurrency('USD');
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->getJson('/api/currency-rates')->assertUnprocessable();
    $this->putJson('/api/currency-rates', ['rates' => [['currency_id' => $usd->id, 'rate' => 2]]])->assertUnprocessable();
    $this->putJson('/api/currency-rates', ['company_id' => $scope['company_id'], 'rates' => [['currency_id' => $usd->id, 'rate' => 2]]])->assertSuccessful();

    expect(CurrencyRate::query()->where('company_id', $scope['company_id'])->count())->toBe(1);
});

test('guests get a 401 and the page needs its permission', function () {
    $this->getJson('/api/currency-rates')->assertUnauthorized();
    $this->putJson('/api/currency-rates', [])->assertUnauthorized();

    $company = (int) dcuScope()['company_id'];
    $this->actingAs(dcuUser($company, []))->get(route('currencyrate'))->assertForbidden();
    $this->actingAs(dcuUser($company, ['/currencyrate']))->get(route('currencyrate'))->assertSuccessful()->assertInertia(fn ($page) => $page->component('currencyrate/index'));
});

// --- menu migrations -----------------------------------------------------------------------------

test('the menu and grant migrations add the page and its update row to the Settings group and to companyadmin', function () {
    $ids = DB::table('menus')->where('route_path', 'like', '/currencyrate%')->pluck('id');
    DB::table('permissions')->whereIn('menu_id', $ids)->delete();
    DB::table('menus')->whereIn('id', $ids)->delete();
    $groupId = (int) DB::table('menus')->where('route_path', '/software/setting')->value('parent_id');
    $admin = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true])->id;

    $menu = require database_path('migrations/2026_09_23_140100_add_currencyrate_menu.php');
    $grant = require database_path('migrations/2026_09_23_140200_grant_companyadmin_currencyrate_menu.php');
    $menu->up();
    $menu->up();
    $grant->up();
    $grant->up();

    $page = DB::table('menus')->where('route_path', '/currencyrate')->first();
    $granted = DB::table('permissions')->join('menus', 'menus.id', '=', 'permissions.menu_id')->where('permissions.role_id', $admin)->pluck('menus.route_path')->sort()->values()->all();

    expect((int) $page->parent_id)->toBe($groupId)
        ->and(DB::table('menus')->where('route_path', 'like', '/currencyrate%')->count())->toBe(2)
        ->and((int) DB::table('menus')->where('route_path', '/currencyrate/update')->value('is_hidden'))->toBe(1)
        ->and($granted)->toBe(['/currencyrate', '/currencyrate/update']);

    $grant->down();
    $menu->down();
    expect(DB::table('menus')->where('route_path', 'like', '/currencyrate%')->count())->toBe(0);
});
