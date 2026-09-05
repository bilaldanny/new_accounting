<?php

use App\Models\City;
use App\Models\Country;
use App\Models\Currency;
use App\Models\State;
use App\Models\Timezone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function fakeCountryStateCityApi(): void
{
    Http::preventStrayRequests();

    Http::fake([
        'https://api.countrystatecity.in/v1/timezone/IN' => Http::response([
            'iana' => 'Asia/Kolkata',
            'abbreviation' => 'IST',
            'offset_utc' => '+05:30',
        ]),
        'https://api.countrystatecity.in/v1/timezone/US' => Http::response([
            'iana' => 'America/New_York',
            'abbreviation' => 'EDT',
            'offset_utc' => '-05:00',
        ]),
        'https://api.countrystatecity.in/v1/timezone/*' => Http::response([
            'iana' => 'UTC',
        ]),
        'https://api.countrystatecity.in/v1/countries/*/states/*/cities' => Http::response([
            [
                'name' => 'Lahore',
                'latitude' => '31.52040',
                'longitude' => '74.35870',
            ],
            [
                'name' => 'Faisalabad',
                'latitude' => '31.45040',
                'longitude' => '73.13500',
            ],
        ]),
        'https://api.countrystatecity.in/v1/countries/*/states' => Http::response([
            [
                'name' => 'Punjab',
                'iso2' => 'PB',
                'type' => 'province',
            ],
            [
                'name' => 'Sindh',
                'iso2' => 'SD',
                'type' => 'province',
            ],
        ]),
        'https://api.countrystatecity.in/v1/countries' => Http::response([
            [
                'name' => 'Islamic Republic of Pakistan',
                'iso2' => 'pk',
                'iso3' => 'pak',
                'phonecode' => '92',
                'capital' => 'Islamabad',
                'nationality' => 'Pakistani',
                'currency' => 'PKR',
            ],
            [
                'name' => 'India',
                'iso2' => 'IN',
                'iso3' => 'IND',
                'phonecode' => '91',
                'capital' => 'New Delhi',
            ],
        ]),
    ]);
}

function actingAsSuperadmin(): User
{
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    return $superadmin;
}

test('guests cannot fetch location data from the api', function (string $endpoint) {
    $this->postJson($endpoint)
        ->assertUnauthorized();
})->with([
    '/api/countries/fetch-from-api',
    '/api/states/fetch-from-api',
    '/api/cities/fetch-from-api',
    '/api/currencies/fetch-from-api',
    '/api/timezones/fetch-from-api',
]);

test('fetching countries creates new records and updates existing ones without changing flag', function () {
    actingAsSuperadmin();
    fakeCountryStateCityApi();

    $existing = Country::factory()->inactive()->create([
        'name' => 'Pakistan',
        'iso2' => 'PK',
        'iso3' => 'PAK',
        'capital' => 'Karachi',
    ]);

    $this->postJson('/api/countries/fetch-from-api')
        ->assertSuccessful()
        ->assertJsonPath('created', 1)
        ->assertJsonPath('updated', 1)
        ->assertJsonPath('message', 'Fetched countries from API. Created 1, updated 1.');

    $existing->refresh();

    expect($existing->name)->toBe('Islamic Republic of Pakistan')
        ->and($existing->capital)->toBe('Islamabad')
        ->and($existing->flag)->toBeFalse()
        ->and(Country::query()->where('iso2', 'IN')->exists())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.countrystatecity.in/v1/countries'
        && $request->hasHeader('X-CSCAPI-KEY', 'test-csc-key'));
});

test('fetching countries restores a matching soft deleted country', function () {
    actingAsSuperadmin();
    fakeCountryStateCityApi();

    $deleted = Country::factory()->create([
        'name' => 'Pakistan',
        'iso2' => 'PK',
    ]);
    $deleted->delete();

    $this->postJson('/api/countries/fetch-from-api')
        ->assertSuccessful()
        ->assertJsonPath('updated', 1);

    expect($deleted->fresh()->trashed())->toBeFalse()
        ->and($deleted->fresh()->name)->toBe('Islamic Republic of Pakistan');
});

test('fetching states upserts records for the selected country', function () {
    actingAsSuperadmin();
    fakeCountryStateCityApi();

    $country = Country::factory()->create([
        'name' => 'Pakistan',
        'iso2' => 'PK',
    ]);

    $existing = State::factory()->inactive()->create([
        'name' => 'Punjab Province',
        'country_id' => $country->id,
        'iso2' => 'PB',
    ]);

    $this->postJson('/api/states/fetch-from-api', [
        'country_id' => $country->id,
    ])
        ->assertSuccessful()
        ->assertJsonPath('created', 1)
        ->assertJsonPath('updated', 1)
        ->assertJsonPath('countries', 1);

    $existing->refresh();

    expect($existing->name)->toBe('Punjab')
        ->and($existing->flag)->toBeFalse()
        ->and(State::query()->where('country_id', $country->id)->where('iso2', 'SD')->exists())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.countrystatecity.in/v1/countries/PK/states'
        && $request->hasHeader('X-CSCAPI-KEY', 'test-csc-key'));
});

test('fetching cities upserts records for the selected country', function () {
    actingAsSuperadmin();
    fakeCountryStateCityApi();

    $country = Country::factory()->create([
        'name' => 'Pakistan',
        'iso2' => 'PK',
    ]);

    $state = State::factory()->create([
        'name' => 'Punjab',
        'country_id' => $country->id,
        'iso2' => 'PB',
    ]);

    $existing = City::factory()->inactive()->create([
        'name' => 'Lahore',
        'state_id' => $state->id,
        'country_id' => $country->id,
        'latitude' => 0,
        'longitude' => 0,
    ]);

    $this->postJson('/api/cities/fetch-from-api', [
        'country_id' => $country->id,
    ])
        ->assertSuccessful()
        ->assertJsonPath('created', 1)
        ->assertJsonPath('updated', 1)
        ->assertJsonPath('state_iso2', 'PB')
        ->assertJsonPath('iso2', 'PK')
        ->assertJsonPath('remaining', 0)
        ->assertJsonPath('done', true);

    $existing->refresh();

    expect((string) $existing->latitude)->toStartWith('31.52040')
        ->and($existing->flag)->toBeFalse()
        ->and(City::query()->where('state_id', $state->id)->where('name', 'Faisalabad')->exists())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.countrystatecity.in/v1/countries/PK/states/PB/cities'
        && $request->hasHeader('X-CSCAPI-KEY', 'test-csc-key'));
});

test('fetching states walks each country automatically', function () {
    actingAsSuperadmin();
    fakeCountryStateCityApi();

    $pakistan = Country::factory()->create([
        'name' => 'Pakistan',
        'iso2' => 'PK',
    ]);

    $india = Country::factory()->create([
        'name' => 'India',
        'iso2' => 'IN',
    ]);

    $this->postJson('/api/states/fetch-from-api')
        ->assertSuccessful()
        ->assertJsonPath('created', 2)
        ->assertJsonPath('updated', 0)
        ->assertJsonPath('iso2', 'IN')
        ->assertJsonPath('remaining', 1)
        ->assertJsonPath('done', false);

    expect(State::query()->where('country_id', $india->id)->count())->toBe(2)
        ->and(State::query()->where('country_id', $pakistan->id)->count())->toBe(0);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.countrystatecity.in/v1/countries/IN/states');
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://api.countrystatecity.in/v1/countries/PK/states');

    $this->postJson('/api/states/fetch-from-api')
        ->assertSuccessful()
        ->assertJsonPath('created', 2)
        ->assertJsonPath('iso2', 'PK')
        ->assertJsonPath('remaining', 0)
        ->assertJsonPath('done', true);

    expect(State::query()->where('country_id', $pakistan->id)->count())->toBe(2);

    $this->postJson('/api/states/fetch-from-api')
        ->assertSuccessful()
        ->assertJsonPath('iso2', 'IN')
        ->assertJsonPath('remaining', 1)
        ->assertJsonPath('done', false);
});

test('fetching cities walks each state one at a time', function () {
    actingAsSuperadmin();
    fakeCountryStateCityApi();

    $india = Country::factory()->create([
        'name' => 'India',
        'iso2' => 'IN',
    ]);

    $maharashtra = State::factory()->create([
        'name' => 'Maharashtra',
        'country_id' => $india->id,
        'iso2' => 'MH',
    ]);

    $punjab = State::factory()->create([
        'name' => 'Punjab',
        'country_id' => $india->id,
        'iso2' => 'PB',
    ]);

    $this->postJson('/api/cities/fetch-from-api')
        ->assertSuccessful()
        ->assertJsonPath('created', 2)
        ->assertJsonPath('state_iso2', 'MH')
        ->assertJsonPath('iso2', 'IN')
        ->assertJsonPath('remaining', 1)
        ->assertJsonPath('done', false);

    expect(City::query()->where('state_id', $maharashtra->id)->count())->toBe(2)
        ->and(City::query()->where('state_id', $punjab->id)->count())->toBe(0);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.countrystatecity.in/v1/countries/IN/states/MH/cities');
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://api.countrystatecity.in/v1/countries/IN/states/PB/cities');

    $this->postJson('/api/cities/fetch-from-api')
        ->assertSuccessful()
        ->assertJsonPath('created', 2)
        ->assertJsonPath('state_iso2', 'PB')
        ->assertJsonPath('remaining', 0)
        ->assertJsonPath('done', true);

    expect(City::query()->where('state_id', $punjab->id)->count())->toBe(2);

    $this->postJson('/api/cities/fetch-from-api')
        ->assertSuccessful()
        ->assertJsonPath('state_iso2', 'MH')
        ->assertJsonPath('remaining', 1)
        ->assertJsonPath('done', false);
});

test('fetching cities with no states returns an empty result', function () {
    actingAsSuperadmin();
    Http::preventStrayRequests();

    $this->postJson('/api/cities/fetch-from-api')
        ->assertSuccessful()
        ->assertJsonPath('created', 0)
        ->assertJsonPath('done', true)
        ->assertJsonPath('state', null);

    Http::assertNothingSent();
});

test('fetching currencies creates unique codes and updates existing ones without changing status', function () {
    actingAsSuperadmin();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.countrystatecity.in/v1/currency' => Http::response([
            [
                'country' => 'US',
                'currency' => [
                    'code' => 'USD',
                    'name' => 'United States dollar',
                    'symbol' => '$',
                ],
            ],
            [
                'country' => 'AD',
                'currency' => [
                    'code' => 'EUR',
                    'name' => 'Euro',
                    'symbol' => '€',
                ],
            ],
            [
                'country' => 'FR',
                'currency' => [
                    'code' => 'EUR',
                    'name' => 'Euro',
                    'symbol' => '€',
                ],
            ],
            [
                'country' => 'PK',
                'currency' => [
                    'code' => 'pkr',
                    'name' => 'Pakistani rupee',
                    'symbol' => '₨',
                ],
            ],
        ]),
    ]);

    $existing = Currency::query()->create([
        'currency_name' => 'Rupee',
        'code' => 'PKR',
        'symbol' => 'Rs',
        'is_active' => false,
    ]);

    $this->postJson('/api/currencies/fetch-from-api')
        ->assertSuccessful()
        ->assertJsonPath('created', 2)
        ->assertJsonPath('updated', 1)
        ->assertJsonPath('message', 'Fetched currencies from API. Created 2, updated 1.');

    $existing->refresh();

    expect($existing->currency_name)->toBe('Pakistani rupee')
        ->and($existing->symbol)->toBe('₨')
        ->and($existing->is_active)->toBeFalse()
        ->and(Currency::query()->where('code', 'USD')->exists())->toBeTrue()
        ->and(Currency::query()->where('code', 'EUR')->count())->toBe(1);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.countrystatecity.in/v1/currency'
        && $request->hasHeader('X-CSCAPI-KEY', 'test-csc-key'));
});

test('fetching currencies restores a matching soft deleted currency', function () {
    actingAsSuperadmin();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.countrystatecity.in/v1/currency' => Http::response([
            [
                'country' => 'US',
                'currency' => [
                    'code' => 'USD',
                    'name' => 'United States dollar',
                    'symbol' => '$',
                ],
            ],
        ]),
    ]);

    $deleted = Currency::query()->create([
        'currency_name' => 'Dollar',
        'code' => 'USD',
        'symbol' => 'USD',
        'is_active' => true,
    ]);
    $deleted->delete();

    $this->postJson('/api/currencies/fetch-from-api')
        ->assertSuccessful()
        ->assertJsonPath('updated', 1);

    expect($deleted->fresh()->trashed())->toBeFalse()
        ->and($deleted->fresh()->currency_name)->toBe('United States dollar');
});

test('fetching timezones walks each country automatically', function () {
    actingAsSuperadmin();
    fakeCountryStateCityApi();

    $unitedStates = Country::factory()->create([
        'name' => 'United States',
        'iso2' => 'US',
    ]);

    $india = Country::factory()->create([
        'name' => 'India',
        'iso2' => 'IN',
    ]);

    $this->postJson('/api/timezones/fetch-from-api')
        ->assertSuccessful()
        ->assertJsonPath('created', 1)
        ->assertJsonPath('updated', 0)
        ->assertJsonPath('iso2', 'IN')
        ->assertJsonPath('remaining', 1)
        ->assertJsonPath('done', false);

    expect(Timezone::query()->where('name', 'Asia/Kolkata')->exists())->toBeTrue()
        ->and(Timezone::query()->where('name', 'America/New_York')->exists())->toBeFalse();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.countrystatecity.in/v1/timezone/IN');
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://api.countrystatecity.in/v1/timezone/US');

    $this->postJson('/api/timezones/fetch-from-api')
        ->assertSuccessful()
        ->assertJsonPath('created', 1)
        ->assertJsonPath('iso2', 'US')
        ->assertJsonPath('remaining', 0)
        ->assertJsonPath('done', true);

    expect(Timezone::query()->where('name', 'America/New_York')->exists())->toBeTrue();

    $this->postJson('/api/timezones/fetch-from-api')
        ->assertSuccessful()
        ->assertJsonPath('iso2', 'IN')
        ->assertJsonPath('remaining', 1)
        ->assertJsonPath('done', false);

    expect($unitedStates->iso2)->toBe('US')
        ->and($india->iso2)->toBe('IN');
});

test('fetching timezones for one country does not move the saved progress', function () {
    actingAsSuperadmin();
    fakeCountryStateCityApi();

    $unitedStates = Country::factory()->create([
        'name' => 'United States',
        'iso2' => 'US',
    ]);

    Country::factory()->create([
        'name' => 'India',
        'iso2' => 'IN',
    ]);

    $this->postJson('/api/timezones/fetch-from-api', [
        'country_id' => $unitedStates->id,
    ])
        ->assertSuccessful()
        ->assertJsonPath('iso2', 'US')
        ->assertJsonPath('created', 1)
        ->assertJsonPath('done', true);

    $this->postJson('/api/timezones/fetch-from-api')
        ->assertSuccessful()
        ->assertJsonPath('iso2', 'IN')
        ->assertJsonPath('remaining', 1)
        ->assertJsonPath('done', false);
});

test('fetching timezones restores a matching soft-deleted record', function () {
    actingAsSuperadmin();
    fakeCountryStateCityApi();

    Country::factory()->create([
        'name' => 'India',
        'iso2' => 'IN',
    ]);

    $deleted = Timezone::query()->create([
        'name' => 'Asia/Kolkata',
    ]);
    $deleted->delete();

    $this->postJson('/api/timezones/fetch-from-api')
        ->assertSuccessful()
        ->assertJsonPath('updated', 1);

    expect($deleted->fresh()->trashed())->toBeFalse()
        ->and($deleted->fresh()->name)->toBe('Asia/Kolkata');
});

test('fetching timezones continues after a country with no timezone data', function () {
    actingAsSuperadmin();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.countrystatecity.in/v1/timezone/IN' => Http::response(['error' => 'Not found'], 404),
        'https://api.countrystatecity.in/v1/timezone/US' => Http::response([
            'iana' => 'America/New_York',
        ]),
    ]);

    Country::factory()->create([
        'name' => 'India',
        'iso2' => 'IN',
    ]);

    Country::factory()->create([
        'name' => 'United States',
        'iso2' => 'US',
    ]);

    $this->postJson('/api/timezones/fetch-from-api')
        ->assertSuccessful()
        ->assertJsonPath('iso2', 'IN')
        ->assertJsonPath('created', 0)
        ->assertJsonPath('remaining', 1)
        ->assertJsonPath('done', false);

    $this->postJson('/api/timezones/fetch-from-api')
        ->assertSuccessful()
        ->assertJsonPath('iso2', 'US')
        ->assertJsonPath('created', 1);
});

test('fetching from the api fails when the key is missing', function () {
    actingAsSuperadmin();
    config(['services.countrystatecity.key' => '']);
    Http::preventStrayRequests();

    $this->postJson('/api/countries/fetch-from-api')
        ->assertUnprocessable()
        ->assertJsonPath('errormessage', 'Set CSCAPI_KEY in the environment before fetching location data.');

    Http::assertNothingSent();
});

test('fetching from the api fails when the remote key is rejected', function () {
    actingAsSuperadmin();
    Http::preventStrayRequests();
    Http::fake([
        'https://api.countrystatecity.in/v1/*' => Http::response(['error' => 'Unauthorized'], 401),
    ]);

    $this->postJson('/api/countries/fetch-from-api')
        ->assertUnprocessable()
        ->assertJsonPath('errormessage', 'Country State City API rejected the request. Check CSCAPI_KEY.');
});
