<?php

use App\Models\City;
use App\Models\Country;
use App\Models\State;
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
        ->assertJsonPath('updated', 1);

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

test('fetching cities requires a country', function () {
    actingAsSuperadmin();
    Http::preventStrayRequests();

    $this->postJson('/api/cities/fetch-from-api')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['country_id']);

    Http::assertNothingSent();
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
