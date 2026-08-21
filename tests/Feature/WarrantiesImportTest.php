<?php

use App\Models\User;
use App\Models\Warranty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function seedWarrantyImportScope(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'WRNM001',
        'name' => 'Warranty Import Company',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'company_id' => $companyId,
    ];
}

function createWarrantyForImport(array $scope, array $attributes = []): Warranty
{
    return Warranty::query()->create(array_merge([
        'company_id' => $scope['company_id'],
        'name' => 'Existing Warranty',
        'duration' => '12',
        'type' => 'month',
        'active' => true,
    ], $attributes));
}

test('warranties import creates records when id is empty', function () {
    $scope = seedWarrantyImportScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/warranties/import', [
        'rows' => [
            [
                'id' => '',
                'company_id' => $scope['company_id'],
                'name' => 'Imported Warranty',
                'duration' => 6,
                'type' => 'month',
                'active' => 1,
            ],
        ],
    ])->assertSuccessful()
        ->assertJsonPath('message', 'Successfully imported 1 new and updated 0 warranty records.');

    $warranty = Warranty::query()->where('name', 'Imported Warranty')->first();

    expect($warranty)->not->toBeNull()
        ->and($warranty->company_id)->toBe($scope['company_id'])
        ->and($warranty->duration)->toBe('6')
        ->and($warranty->type)->toBe('month')
        ->and($warranty->active)->toBeTrue();
});

test('warranties import updates records when id is provided', function () {
    $scope = seedWarrantyImportScope();
    $warranty = createWarrantyForImport($scope);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/warranties/import', [
        'rows' => [
            [
                'id' => $warranty->id,
                'name' => 'Updated Warranty Name',
                'duration' => 2,
                'type' => 'year',
                'active' => 0,
            ],
        ],
    ])->assertSuccessful()
        ->assertJsonPath('message', 'Successfully imported 0 new and updated 1 warranty records.');

    $warranty->refresh();

    expect($warranty->name)->toBe('Updated Warranty Name')
        ->and($warranty->duration)->toBe('2')
        ->and($warranty->type)->toBe('year')
        ->and($warranty->active)->toBeFalse();
});

test('warranties import rejects unknown ids', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/warranties/import', [
        'rows' => [
            [
                'id' => 999999,
                'name' => 'Missing Warranty',
                'duration' => 1,
                'type' => 'day',
                'active' => 1,
            ],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['rows']);
});

test('guests cannot import warranties', function () {
    $this->postJson('/api/warranties/import', [
        'rows' => [],
    ])->assertUnauthorized();
});
