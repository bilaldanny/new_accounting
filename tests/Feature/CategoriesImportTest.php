<?php

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function seedCategoryImportScope(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'CATM001',
        'name' => 'Category Import Company',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'company_id' => $companyId,
    ];
}

function createCategoryForImport(array $scope, array $attributes = []): Category
{
    return Category::query()->create(array_merge([
        'company_id' => $scope['company_id'],
        'name' => 'Existing Category',
        'active' => true,
    ], $attributes));
}

test('categories import creates records when id is empty', function () {
    $scope = seedCategoryImportScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/categories/import', [
        'rows' => [
            [
                'id' => '',
                'company_id' => $scope['company_id'],
                'name' => 'Imported Category',
                'parent' => '',
                'active' => 1,
            ],
        ],
    ])->assertSuccessful()
        ->assertJsonPath('message', 'Successfully imported 1 new and updated 0 category records.');

    $category = Category::query()->where('name', 'Imported Category')->first();

    expect($category)->not->toBeNull()
        ->and($category->company_id)->toBe($scope['company_id'])
        ->and($category->active)->toBeTrue();
});

test('categories import updates records when id is provided', function () {
    $scope = seedCategoryImportScope();
    $category = createCategoryForImport($scope);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/categories/import', [
        'rows' => [
            [
                'id' => $category->id,
                'name' => 'Updated Category Name',
                'parent' => '',
                'active' => 0,
            ],
        ],
    ])->assertSuccessful()
        ->assertJsonPath('message', 'Successfully imported 0 new and updated 1 category records.');

    $category->refresh();

    expect($category->name)->toBe('Updated Category Name')
        ->and($category->active)->toBeFalse();
});

test('categories import resolves parent by name', function () {
    $scope = seedCategoryImportScope();
    $parent = createCategoryForImport($scope, [
        'name' => 'Electronics',
    ]);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/categories/import', [
        'rows' => [
            [
                'id' => '',
                'company_id' => $scope['company_id'],
                'name' => 'Laptops',
                'parent' => 'Electronics',
                'active' => 1,
            ],
        ],
    ])->assertSuccessful();

    $child = Category::query()->where('name', 'Laptops')->first();

    expect($child)->not->toBeNull()
        ->and($child->parent_id)->toBe($parent->id);
});

test('categories import rejects unknown ids', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/categories/import', [
        'rows' => [
            [
                'id' => 999999,
                'name' => 'Missing Category',
                'parent' => '',
                'active' => 1,
            ],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['rows']);
});

test('guests cannot import categories', function () {
    $this->postJson('/api/categories/import', [
        'rows' => [],
    ])->assertUnauthorized();
});
