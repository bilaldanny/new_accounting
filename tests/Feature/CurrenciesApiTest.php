<?php

use App\Models\Currency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('guests cannot access currencies api', function () {
    $this->getJson('/api/currencies')
        ->assertUnauthorized();
});

test('superadmin can list create update and delete currencies', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->getJson('/api/currencies')
        ->assertSuccessful()
        ->assertJsonPath('data.total', 0)
        ->assertJsonPath('trash_count', 0);

    $this->postJson('/api/currencies', [
        'currency_name' => 'US Dollar',
        'code' => 'USD',
        'symbol' => '$',
        'is_active' => true,
    ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Saved');

    $currency = Currency::query()->firstOrFail();

    expect($currency->code)->toBe('USD');

    $this->getJson('/api/currencies/'.$currency->id)
        ->assertSuccessful()
        ->assertJsonPath('currency_name', 'US Dollar')
        ->assertJsonPath('code', 'USD')
        ->assertJsonPath('symbol', '$');

    $this->putJson('/api/currencies/'.$currency->id, [
        'currency_name' => 'United States Dollar',
        'code' => 'USD',
        'symbol' => 'US$',
        'is_active' => true,
    ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Saved');

    $this->assertDatabaseHas('currencies', [
        'id' => $currency->id,
        'currency_name' => 'United States Dollar',
        'symbol' => 'US$',
    ]);

    $this->postJson('/api/currencies/statusupdate', [
        'ids' => [$currency->id],
        'status' => false,
    ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Saved');

    $this->assertDatabaseHas('currencies', [
        'id' => $currency->id,
        'is_active' => 0,
    ]);

    $this->deleteJson('/api/currencies/'.$currency->id)
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Deleted');

    $this->assertSoftDeleted('currencies', [
        'id' => $currency->id,
    ]);
});

test('currency code must be exactly three characters', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/currencies', [
        'currency_name' => 'Euro',
        'code' => 'EU',
        'symbol' => '€',
        'is_active' => true,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});

test('currency code must be unique', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    Currency::query()->create([
        'currency_name' => 'Pakistani Rupee',
        'code' => 'PKR',
        'symbol' => 'Rs',
        'is_active' => true,
    ]);

    $this->postJson('/api/currencies', [
        'currency_name' => 'Duplicate Rupee',
        'code' => 'PKR',
        'symbol' => 'Rs',
        'is_active' => true,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);

    $this->postJson('/api/currencies/check-code', [
        'code' => 'PKR',
    ])
        ->assertSuccessful()
        ->assertJsonPath('code_taken', true);
});

test('fetch currencies returns only active currencies for dropdowns', function () {
    Currency::query()->create([
        'currency_name' => 'US Dollar',
        'code' => 'USD',
        'symbol' => '$',
        'is_active' => true,
    ]);

    Currency::query()->create([
        'currency_name' => 'Inactive Pound',
        'code' => 'GBP',
        'symbol' => '£',
        'is_active' => false,
    ]);

    $this->getJson('/api/fetchcurrencies')
        ->assertSuccessful()
        ->assertJsonCount(1)
        ->assertJsonPath('0.code', 'USD');
});
