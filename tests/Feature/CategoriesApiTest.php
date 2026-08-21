<?php

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function createCategory(string $name, array $attributes = []): Category
{
    return Category::query()->create(array_merge([
        'name' => $name,
        'active' => true,
    ], $attributes));
}

function seedCategoryScope(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'CATC001',
        'name' => 'Category Test Company',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'company_id' => $companyId,
    ];
}

function validCategoryPayload(array $scope, array $overrides = []): array
{
    return array_merge([
        'name' => 'Electronics',
        'company_id' => $scope['company_id'],
        'active' => true,
    ], $overrides);
}

test('categories api creates a category with required fields', function () {
    $scope = seedCategoryScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/categories', validCategoryPayload($scope))
        ->assertSuccessful();

    $this->assertDatabaseHas('categories', [
        'name' => 'Electronics',
        'company_id' => $scope['company_id'],
    ]);
});

test('categories api rejects duplicate names within the same company', function () {
    $scope = seedCategoryScope();
    createCategory('electronics', ['company_id' => $scope['company_id']]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/categories', validCategoryPayload($scope, [
        'name' => 'Electronics',
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('categories check-name api reports when a name already exists', function () {
    $scope = seedCategoryScope();
    createCategory('electronics', ['company_id' => $scope['company_id']]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/categories/check-name', [
        'name' => 'Electronics',
        'company_id' => $scope['company_id'],
    ])->assertSuccessful()
        ->assertJson(['name_taken' => true]);
});

test('categories fetch api returns active top-level categories for a company', function () {
    $scope = seedCategoryScope();
    $activeCategory = createCategory('electronics', [
        'company_id' => $scope['company_id'],
        'active' => true,
    ]);
    createCategory('inactive category', [
        'company_id' => $scope['company_id'],
        'active' => false,
    ]);
    createCategory('subcategory', [
        'company_id' => $scope['company_id'],
        'parent_id' => $activeCategory->id,
        'active' => true,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/fetchcategories?company_id='.$scope['company_id']);

    $response->assertSuccessful();
    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.id'))->toBe($activeCategory->id)
        ->and($response->json('0.text'))->toBe('electronics');
});

test('categories index returns company and parent names', function () {
    $scope = seedCategoryScope();
    $parent = createCategory('electronics', ['company_id' => $scope['company_id']]);
    createCategory('phones', [
        'company_id' => $scope['company_id'],
        'parent_id' => $parent->id,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/categories');

    $response->assertSuccessful();
    expect($response->json('data.data.0.company_name'))->toBe('Category Test Company')
        ->and($response->json('data.data.0.parent_name'))->toBeNull()
        ->and($response->json('data.data.1.parent_name'))->toBe('electronics');
});

test('categories api rejects setting a category as its own parent', function () {
    $scope = seedCategoryScope();
    $category = createCategory('electronics', ['company_id' => $scope['company_id']]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->putJson('/api/categories/'.$category->id, validCategoryPayload($scope, [
        'parent_id' => $category->id,
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['parent_id']);
});
