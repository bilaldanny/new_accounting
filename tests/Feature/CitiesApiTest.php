<?php

use App\Models\City;
use App\Models\Country;
use App\Models\State;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('guests cannot access cities api', function () {
    $this->getJson('/api/cities')
        ->assertUnauthorized();
});

test('superadmin can list create update and delete cities', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $country = Country::factory()->create([
        'name' => 'Pakistan',
        'iso2' => 'PK',
    ]);
    $state = State::factory()->create([
        'name' => 'Punjab',
        'country_id' => $country->id,
        'country_code' => 'PK',
        'iso2' => 'PB',
    ]);

    $this->getJson('/api/cities')
        ->assertSuccessful()
        ->assertJsonPath('data.total', 0)
        ->assertJsonPath('trash_count', 0);

    $this->postJson('/api/cities', [
        'name' => 'Lahore',
        'country_id' => $country->id,
        'state_id' => $state->id,
        'flag' => true,
    ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Saved');

    $city = City::query()->firstOrFail();

    expect($city->country_id)->toBe($country->id)
        ->and($city->country_code)->toBe('PK')
        ->and($city->state_code)->toBe('PB');

    $this->getJson('/api/cities/'.$city->id)
        ->assertSuccessful()
        ->assertJsonPath('name', 'Lahore')
        ->assertJsonPath('state_id', $state->id);

    $this->putJson('/api/cities/'.$city->id, [
        'name' => 'Lahore City',
        'country_id' => $country->id,
        'state_id' => $state->id,
        'flag' => true,
    ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Saved');

    $this->assertDatabaseHas('cities', [
        'id' => $city->id,
        'name' => 'Lahore City',
        'state_id' => $state->id,
    ]);

    $this->getJson('/api/cities')
        ->assertSuccessful()
        ->assertJsonPath('data.data.0.country_name', 'Pakistan')
        ->assertJsonPath('data.data.0.state_name', 'Punjab');

    $this->deleteJson('/api/cities/'.$city->id)
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Deleted');

    $this->assertSoftDeleted('cities', [
        'id' => $city->id,
    ]);
});

test('city name must be unique within a state', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $country = Country::factory()->create(['iso2' => 'PK']);
    $punjab = State::factory()->create([
        'name' => 'Punjab',
        'country_id' => $country->id,
        'country_code' => 'PK',
    ]);
    $sindh = State::factory()->create([
        'name' => 'Sindh',
        'country_id' => $country->id,
        'country_code' => 'PK',
    ]);

    City::factory()->create([
        'name' => 'Lahore',
        'state_id' => $punjab->id,
        'country_id' => $country->id,
        'country_code' => 'PK',
        'state_code' => 'PB',
    ]);

    $this->postJson('/api/cities', [
        'name' => 'lahore',
        'country_id' => $country->id,
        'state_id' => $punjab->id,
        'flag' => true,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    $this->postJson('/api/cities', [
        'name' => 'Lahore',
        'country_id' => $country->id,
        'state_id' => $sindh->id,
        'flag' => true,
    ])->assertSuccessful();

    $this->postJson('/api/cities/check-name', [
        'name' => 'Lahore',
        'state_id' => $punjab->id,
    ])
        ->assertSuccessful()
        ->assertJsonPath('name_taken', true);
});

test('city store requires a state that belongs to the selected country', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $pakistan = Country::factory()->create(['iso2' => 'PK']);
    $india = Country::factory()->create(['iso2' => 'IN']);
    $punjab = State::factory()->create([
        'name' => 'Punjab',
        'country_id' => $pakistan->id,
        'country_code' => 'PK',
    ]);

    $this->postJson('/api/cities', [
        'name' => 'Lahore',
        'country_id' => $india->id,
        'state_id' => $punjab->id,
        'flag' => true,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['state_id']);
});

test('fetch cities returns only active cities for the selected state', function () {
    $country = Country::factory()->create(['iso2' => 'PK']);
    $state = State::factory()->create([
        'country_id' => $country->id,
        'country_code' => 'PK',
    ]);

    $active = City::factory()->create([
        'name' => 'Lahore',
        'state_id' => $state->id,
        'country_id' => $country->id,
        'country_code' => 'PK',
        'state_code' => 'PB',
        'flag' => true,
    ]);

    City::factory()->inactive()->create([
        'name' => 'Hidden City',
        'state_id' => $state->id,
        'country_id' => $country->id,
        'country_code' => 'PK',
        'state_code' => 'PB',
    ]);

    $this->postJson('/api/fetchcities', [
        'state_id' => $state->id,
    ])
        ->assertSuccessful()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $active->id)
        ->assertJsonPath('0.text', 'Lahore');
});
