<?php

use App\Models\Brand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function seedBrandImportScope(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'BRDM001',
        'name' => 'Brand Import Company',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'company_id' => $companyId,
    ];
}

function createBrandForImport(array $scope, array $attributes = []): Brand
{
    return Brand::query()->create(array_merge([
        'company_id' => $scope['company_id'],
        'name' => 'Existing Brand',
        'active' => true,
    ], $attributes));
}

test('brands import creates records when id is empty', function () {
    $scope = seedBrandImportScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/brands/import', [
        'rows' => [
            [
                'id' => '',
                'company_id' => $scope['company_id'],
                'name' => 'Imported Brand',
                'active' => 1,
            ],
        ],
    ])->assertSuccessful()
        ->assertJsonPath('message', 'Successfully imported 1 new and updated 0 brand records.');

    $brand = Brand::query()->where('name', 'Imported Brand')->first();

    expect($brand)->not->toBeNull()
        ->and($brand->company_id)->toBe($scope['company_id'])
        ->and($brand->active)->toBeTrue();
});

test('brands import updates records when id is provided', function () {
    $scope = seedBrandImportScope();
    $brand = createBrandForImport($scope);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/brands/import', [
        'rows' => [
            [
                'id' => $brand->id,
                'name' => 'Updated Brand Name',
                'active' => 0,
            ],
        ],
    ])->assertSuccessful()
        ->assertJsonPath('message', 'Successfully imported 0 new and updated 1 brand records.');

    $brand->refresh();

    expect($brand->name)->toBe('Updated Brand Name')
        ->and($brand->active)->toBeFalse();
});

test('brands import rejects unknown ids', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/brands/import', [
        'rows' => [
            [
                'id' => 999999,
                'name' => 'Missing Brand',
                'active' => 1,
            ],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['rows']);
});

test('guests cannot import brands', function () {
    $this->postJson('/api/brands/import', [
        'rows' => [],
    ])->assertUnauthorized();
});
