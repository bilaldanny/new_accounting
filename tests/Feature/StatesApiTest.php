<?php

use App\Models\Country;
use App\Models\State;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('guests cannot access states api', function () {
    $this->getJson('/api/states')
        ->assertUnauthorized();
});

test('superadmin can list create update and delete states', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $country = Country::factory()->create([
        'name' => 'Pakistan',
        'iso2' => 'PK',
    ]);

    $this->getJson('/api/states')
        ->assertSuccessful()
        ->assertJsonPath('data.total', 0)
        ->assertJsonPath('trash_count', 0);

    $this->postJson('/api/states', [
        'name' => 'Punjab',
        'country_id' => $country->id,
        'iso2' => 'pb',
        'type' => 'province',
        'flag' => true,
    ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Saved');

    $state = State::query()->firstOrFail();

    expect($state->iso2)->toBe('PB')
        ->and($state->country_code)->toBe('PK');

    $this->getJson('/api/states/'.$state->id)
        ->assertSuccessful()
        ->assertJsonPath('name', 'Punjab')
        ->assertJsonPath('country_id', $country->id);

    $this->putJson('/api/states/'.$state->id, [
        'name' => 'Punjab Province',
        'country_id' => $country->id,
        'iso2' => 'PB',
        'flag' => true,
    ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Saved');

    $this->assertDatabaseHas('states', [
        'id' => $state->id,
        'name' => 'Punjab Province',
        'country_id' => $country->id,
    ]);

    $this->getJson('/api/states')
        ->assertSuccessful()
        ->assertJsonPath('data.data.0.country_name', 'Pakistan');

    $this->deleteJson('/api/states/'.$state->id)
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Deleted');

    $this->assertSoftDeleted('states', [
        'id' => $state->id,
    ]);
});

test('state name must be unique within a country', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $pakistan = Country::factory()->create(['name' => 'Pakistan', 'iso2' => 'PK']);
    $india = Country::factory()->create(['name' => 'India', 'iso2' => 'IN']);

    State::factory()->create([
        'name' => 'Punjab',
        'country_id' => $pakistan->id,
        'country_code' => 'PK',
    ]);

    $this->postJson('/api/states', [
        'name' => 'punjab',
        'country_id' => $pakistan->id,
        'flag' => true,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    $this->postJson('/api/states', [
        'name' => 'Punjab',
        'country_id' => $india->id,
        'flag' => true,
    ])->assertSuccessful();

    $this->postJson('/api/states/check-name', [
        'name' => 'Punjab',
        'country_id' => $pakistan->id,
    ])
        ->assertSuccessful()
        ->assertJsonPath('name_taken', true);
});

test('fetch states returns only active states for the selected country', function () {
    $country = Country::factory()->create(['iso2' => 'PK']);
    $otherCountry = Country::factory()->create(['iso2' => 'IN']);

    $active = State::factory()->create([
        'name' => 'Punjab',
        'country_id' => $country->id,
        'country_code' => 'PK',
        'flag' => true,
    ]);

    State::factory()->inactive()->create([
        'name' => 'Hidden',
        'country_id' => $country->id,
        'country_code' => 'PK',
    ]);

    State::factory()->create([
        'name' => 'Maharashtra',
        'country_id' => $otherCountry->id,
        'country_code' => 'IN',
    ]);

    $this->postJson('/api/fetchstates', [
        'country_id' => $country->id,
    ])
        ->assertSuccessful()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $active->id)
        ->assertJsonPath('0.text', 'Punjab');
});
