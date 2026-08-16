<?php

use App\Models\Timezone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('guests cannot access timezones api', function () {
    $this->getJson('/api/timezones')
        ->assertUnauthorized();
});

test('superadmin can list create update and delete timezones', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->getJson('/api/timezones')
        ->assertSuccessful()
        ->assertJsonPath('data.total', 0)
        ->assertJsonPath('trash_count', 0);

    $this->postJson('/api/timezones', [
        'name' => 'Asia/Karachi',
    ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Saved');

    $timezone = Timezone::query()->firstOrFail();

    $this->getJson('/api/timezones/'.$timezone->id)
        ->assertSuccessful()
        ->assertJsonPath('name', 'Asia/Karachi');

    $this->putJson('/api/timezones/'.$timezone->id, [
        'name' => 'Asia/Dubai',
    ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Saved');

    $this->assertDatabaseHas('timezones', [
        'id' => $timezone->id,
        'name' => 'Asia/Dubai',
    ]);

    $this->deleteJson('/api/timezones/'.$timezone->id)
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Deleted');

    $this->assertSoftDeleted('timezones', [
        'id' => $timezone->id,
    ]);
});

test('timezone name must be unique', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    Timezone::query()->create([
        'name' => 'UTC',
    ]);

    $this->postJson('/api/timezones', [
        'name' => 'UTC',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    $this->postJson('/api/timezones/check-name', [
        'name' => 'UTC',
    ])
        ->assertSuccessful()
        ->assertJsonPath('name_taken', true);
});

test('timezone store validates required name', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/timezones', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('fetch timezones returns all timezones for dropdowns', function () {
    Timezone::query()->create(['name' => 'Asia/Karachi']);
    Timezone::query()->create(['name' => 'UTC']);

    $this->getJson('/api/fetchtimezones')
        ->assertSuccessful()
        ->assertJsonCount(2)
        ->assertJsonPath('0.text', 'Asia/Karachi');
});
