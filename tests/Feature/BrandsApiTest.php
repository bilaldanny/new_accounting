<?php

use App\Models\Brand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function createBrand(string $name, array $attributes = []): Brand
{
    return Brand::query()->create(array_merge([
        'name' => $name,
        'active' => true,
    ], $attributes));
}

function seedBrandScope(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'BRND001',
        'name' => 'Brand Test Company',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'company_id' => $companyId,
    ];
}

function validBrandPayload(array $scope, array $overrides = []): array
{
    return array_merge([
        'name' => 'Samsung',
        'company_id' => $scope['company_id'],
        'active' => true,
    ], $overrides);
}

test('brands api creates a brand with required fields', function () {
    $scope = seedBrandScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/brands', validBrandPayload($scope))
        ->assertSuccessful();

    $this->assertDatabaseHas('brands', [
        'name' => 'Samsung',
        'company_id' => $scope['company_id'],
    ]);
});

test('brands api rejects duplicate names within the same company', function () {
    $scope = seedBrandScope();
    createBrand('samsung', ['company_id' => $scope['company_id']]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/brands', validBrandPayload($scope, [
        'name' => 'Sam Sung',
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('brands check-name api reports when a name already exists', function () {
    $scope = seedBrandScope();
    createBrand('samsung', ['company_id' => $scope['company_id']]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/brands/check-name', [
        'name' => 'Samsung',
        'company_id' => $scope['company_id'],
    ])->assertSuccessful()
        ->assertJson(['name_taken' => true]);
});

test('brands fetch api returns active brands for a company', function () {
    $scope = seedBrandScope();
    $activeBrand = createBrand('samsung', [
        'company_id' => $scope['company_id'],
        'active' => true,
    ]);
    createBrand('apple', [
        'company_id' => $scope['company_id'],
        'active' => false,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/fetchbrands?company_id='.$scope['company_id']);

    $response->assertSuccessful();
    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.id'))->toBe($activeBrand->id)
        ->and($response->json('0.text'))->toBe('samsung');
});

test('brands index returns company name', function () {
    $scope = seedBrandScope();
    createBrand('samsung', ['company_id' => $scope['company_id']]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/brands');

    $response->assertSuccessful();
    expect($response->json('data.data.0.company_name'))->toBe('Brand Test Company')
        ->and($response->json('data.data.0.name'))->toBe('samsung');
});

test('brands api soft deletes a brand', function () {
    $scope = seedBrandScope();
    $brand = createBrand('samsung', ['company_id' => $scope['company_id']]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/brands/bulk_delete', [$brand->id])
        ->assertSuccessful();

    $this->assertSoftDeleted('brands', ['id' => $brand->id]);
});
