<?php

use App\Models\Country;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('guests cannot access countries api', function () {
    $this->getJson('/api/countries')
        ->assertUnauthorized();
});

test('superadmin can list create update and delete countries', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->getJson('/api/countries')
        ->assertSuccessful()
        ->assertJsonPath('data.total', 0)
        ->assertJsonPath('trash_count', 0);

    $this->postJson('/api/countries', [
        'name' => 'Pakistan',
        'iso2' => 'pk',
        'iso3' => 'pak',
        'phonecode' => '+92',
        'capital' => 'Islamabad',
        'nationality' => 'Pakistani',
        'flag' => true,
    ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Saved');

    $country = Country::query()->firstOrFail();

    expect($country->iso2)->toBe('PK')
        ->and($country->iso3)->toBe('PAK');

    $this->getJson('/api/countries/'.$country->id)
        ->assertSuccessful()
        ->assertJsonPath('name', 'Pakistan')
        ->assertJsonPath('iso2', 'PK');

    $this->putJson('/api/countries/'.$country->id, [
        'name' => 'Islamic Republic of Pakistan',
        'iso2' => 'PK',
        'iso3' => 'PAK',
        'phonecode' => '+92',
        'flag' => true,
    ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Saved');

    $this->assertDatabaseHas('countries', [
        'id' => $country->id,
        'name' => 'Islamic Republic of Pakistan',
    ]);

    $this->postJson('/api/countries/statusupdate', [
        'ids' => [$country->id],
        'status' => false,
    ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Saved');

    $this->assertDatabaseHas('countries', [
        'id' => $country->id,
        'flag' => 0,
    ]);

    $this->deleteJson('/api/countries/'.$country->id)
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Deleted');

    $this->assertSoftDeleted('countries', [
        'id' => $country->id,
    ]);
});

test('country name and iso2 must be unique', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    Country::factory()->create([
        'name' => 'Pakistan',
        'iso2' => 'PK',
    ]);

    $this->postJson('/api/countries', [
        'name' => 'pakistan',
        'iso2' => 'IN',
        'flag' => true,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    $this->postJson('/api/countries', [
        'name' => 'India',
        'iso2' => 'PK',
        'flag' => true,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['iso2']);

    $this->postJson('/api/countries/check-name', [
        'name' => 'Pakistan',
    ])
        ->assertSuccessful()
        ->assertJsonPath('name_taken', true);

    $this->postJson('/api/countries/check-iso2', [
        'iso2' => 'PK',
    ])
        ->assertSuccessful()
        ->assertJsonPath('iso2_taken', true);
});

test('fetch countries returns only active countries for dropdowns', function () {
    Country::factory()->create([
        'name' => 'Pakistan',
        'iso2' => 'PK',
        'flag' => true,
    ]);

    Country::factory()->inactive()->create([
        'name' => 'Hidden Land',
        'iso2' => 'HL',
    ]);

    $this->postJson('/api/fetchcountries')
        ->assertSuccessful()
        ->assertJsonCount(1)
        ->assertJsonPath('0.iso2', 'PK')
        ->assertJsonPath('0.text', 'Pakistan');
});
