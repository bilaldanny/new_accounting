<?php

use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function createUnit(string $name, array $attributes = []): Unit
{
    return Unit::query()->create(array_merge([
        'name' => $name,
        'short_name' => strtoupper(substr($name, 0, 3)),
        'type' => 'large',
        'active' => true,
        'auto_adjustment' => false,
    ], $attributes));
}

function seedUnitScope(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'UNTC001',
        'name' => 'Unit Test Company',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'company_id' => $companyId,
    ];
}

function validUnitPayload(array $scope, array $overrides = []): array
{
    return array_merge([
        'name' => 'Kilogram',
        'short_name' => 'KG',
        'type' => 'large',
        'company_id' => $scope['company_id'],
        'active' => true,
        'auto_adjustment' => false,
    ], $overrides);
}

test('units api creates a unit with required fields', function () {
    $scope = seedUnitScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/units', validUnitPayload($scope))
        ->assertSuccessful();

    $this->assertDatabaseHas('units', [
        'name' => 'Kilogram',
        'short_name' => 'KG',
        'type' => 'large',
        'company_id' => $scope['company_id'],
    ]);
});

test('units api rejects duplicate names within the same company', function () {
    $scope = seedUnitScope();
    createUnit('kilogram', ['company_id' => $scope['company_id']]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/units', validUnitPayload($scope, [
        'name' => 'Kilo Gram',
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('units check-name api reports when a name already exists', function () {
    $scope = seedUnitScope();
    createUnit('kilogram', ['company_id' => $scope['company_id']]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/units/check-name', [
        'name' => 'Kilogram',
        'company_id' => $scope['company_id'],
    ])->assertSuccessful()
        ->assertJson(['name_taken' => true]);
});

test('units fetch api returns active units for a company', function () {
    $scope = seedUnitScope();
    $activeUnit = createUnit('kilogram', [
        'company_id' => $scope['company_id'],
        'active' => true,
    ]);
    createUnit('gram', [
        'company_id' => $scope['company_id'],
        'active' => false,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/fetchunits?company_id='.$scope['company_id']);

    $response->assertSuccessful();
    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.id'))->toBe($activeUnit->id)
        ->and($response->json('0.text'))->toBe('kilogram');
});

test('units index returns company and parent names', function () {
    $scope = seedUnitScope();
    $parent = createUnit('kilogram', ['company_id' => $scope['company_id']]);
    createUnit('gram', [
        'company_id' => $scope['company_id'],
        'parent_id' => $parent->id,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/units');

    $response->assertSuccessful();
    expect($response->json('data.data.0.company_name'))->toBe('Unit Test Company')
        ->and($response->json('data.data.0.parent_name'))->toBeNull()
        ->and($response->json('data.data.1.parent_name'))->toBe('kilogram');
});

test('units api rejects setting a unit as its own parent', function () {
    $scope = seedUnitScope();
    $unit = createUnit('kilogram', ['company_id' => $scope['company_id']]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->putJson('/api/units/'.$unit->id, validUnitPayload($scope, [
        'parent_id' => $unit->id,
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['parent_id']);
});
