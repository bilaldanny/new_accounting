<?php

use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function seedUnitImportScope(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'UNIM001',
        'name' => 'Unit Import Company',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'company_id' => $companyId,
    ];
}

function createUnitForImport(array $scope, array $attributes = []): Unit
{
    return Unit::query()->create(array_merge([
        'company_id' => $scope['company_id'],
        'name' => 'Existing Unit',
        'short_name' => 'EU',
        'type' => 'large',
        'active' => true,
        'auto_adjustment' => false,
    ], $attributes));
}

test('units import creates records when id is empty', function () {
    $scope = seedUnitImportScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/units/import', [
        'rows' => [
            [
                'id' => '',
                'company_id' => $scope['company_id'],
                'name' => 'Imported Unit',
                'short_name' => 'IU',
                'type' => 'large',
                'parent' => '',
                'active' => 1,
                'auto_adjustment' => 0,
            ],
        ],
    ])->assertSuccessful()
        ->assertJsonPath('message', 'Successfully imported 1 new and updated 0 unit records.');

    $unit = Unit::query()->where('name', 'Imported Unit')->first();

    expect($unit)->not->toBeNull()
        ->and($unit->short_name)->toBe('IU')
        ->and($unit->company_id)->toBe($scope['company_id'])
        ->and($unit->active)->toBeTrue()
        ->and($unit->auto_adjustment)->toBeFalse();
});

test('units import updates records when id is provided', function () {
    $scope = seedUnitImportScope();
    $unit = createUnitForImport($scope);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/units/import', [
        'rows' => [
            [
                'id' => $unit->id,
                'name' => 'Updated Unit Name',
                'short_name' => 'UUN',
                'type' => 'small',
                'parent' => '',
                'active' => 1,
                'auto_adjustment' => 1,
            ],
        ],
    ])->assertSuccessful()
        ->assertJsonPath('message', 'Successfully imported 0 new and updated 1 unit records.');

    $unit->refresh();

    expect($unit->name)->toBe('Updated Unit Name')
        ->and($unit->short_name)->toBe('UUN')
        ->and($unit->type)->toBe('small')
        ->and($unit->auto_adjustment)->toBeTrue();
});

test('units import resolves parent by name', function () {
    $scope = seedUnitImportScope();
    $parent = createUnitForImport($scope, [
        'name' => 'Kilogram',
        'short_name' => 'KG',
    ]);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/units/import', [
        'rows' => [
            [
                'id' => '',
                'company_id' => $scope['company_id'],
                'name' => 'Gram',
                'short_name' => 'G',
                'type' => 'small',
                'parent' => 'Kilogram',
                'active' => 1,
                'auto_adjustment' => 0,
            ],
        ],
    ])->assertSuccessful();

    $child = Unit::query()->where('name', 'Gram')->first();

    expect($child)->not->toBeNull()
        ->and($child->parent_id)->toBe($parent->id);
});

test('units import rejects unknown ids', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/units/import', [
        'rows' => [
            [
                'id' => 999999,
                'name' => 'Missing Unit',
                'short_name' => 'MU',
                'type' => 'large',
                'parent' => '',
                'active' => 1,
                'auto_adjustment' => 0,
            ],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['rows']);
});

test('guests cannot import units', function () {
    $this->postJson('/api/units/import', [
        'rows' => [],
    ])->assertUnauthorized();
});
