<?php

use App\Models\Category;
use App\Models\ItemType;
use App\Models\User;
use App\Models\Variation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function seedVariationScope(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'VARC001',
        'name' => 'Variation Test Company',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $categoryId = Category::query()->create([
        'company_id' => $companyId,
        'name' => 'Apparel',
        'active' => true,
    ])->id;

    $subcategoryId = Category::query()->create([
        'company_id' => $companyId,
        'parent_id' => $categoryId,
        'name' => 'Shirts',
        'active' => true,
    ])->id;

    $itemtypeId = ItemType::query()->create([
        'company_id' => $companyId,
        'name' => 'Finished Goods',
        'active' => true,
    ])->id;

    return [
        'company_id' => $companyId,
        'category_id' => $categoryId,
        'subcategory_id' => $subcategoryId,
        'itemtype_id' => $itemtypeId,
    ];
}

function validVariationPayload(array $scope, array $overrides = []): array
{
    return array_merge([
        'company_id' => $scope['company_id'],
        'category_id' => $scope['category_id'],
        'subcategory_id' => $scope['subcategory_id'],
        'itemtype_id' => $scope['itemtype_id'],
        'values' => [
            ['name' => 'Small', 'active' => true],
            ['name' => 'Medium', 'active' => true],
        ],
        'priority' => 0,
        'active' => true,
    ], $overrides);
}

test('variations api accepts string boolean values from multipart forms', function () {
    $scope = seedVariationScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/variations', validVariationPayload($scope, [
        'active' => 'true',
        'values' => [
            ['name' => 'Small', 'active' => 'true'],
            ['name' => 'Medium', 'active' => 'false'],
        ],
    ]))->assertSuccessful();

    expect(Variation::query()->first()?->values)->toBe([
        ['name' => 'Small', 'active' => true],
        ['name' => 'Medium', 'active' => false],
    ]);
});

test('variations api creates a variation with required fields', function () {
    $scope = seedVariationScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/variations', validVariationPayload($scope))
        ->assertSuccessful();

    $this->assertDatabaseHas('variations', [
        'company_id' => $scope['company_id'],
        'category_id' => $scope['category_id'],
        'subcategory_id' => $scope['subcategory_id'],
        'itemtype_id' => $scope['itemtype_id'],
    ]);
});

test('variations api rejects empty value lists', function () {
    $scope = seedVariationScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/variations', validVariationPayload($scope, [
        'values' => [
            ['name' => '', 'active' => true],
        ],
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['values']);
});

test('variations index returns category item type and values display', function () {
    $scope = seedVariationScope();
    Variation::query()->create([
        'company_id' => $scope['company_id'],
        'category_id' => $scope['category_id'],
        'subcategory_id' => $scope['subcategory_id'],
        'itemtype_id' => $scope['itemtype_id'],
        'values' => [
            ['name' => 'Red', 'active' => true],
            ['name' => 'Blue', 'active' => true],
        ],
        'priority' => 0,
        'active' => true,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/variations');

    $response->assertSuccessful();
    expect($response->json('data.data.0.category_name'))->toBe('Apparel / Shirts')
        ->and($response->json('data.data.0.itemtype_name'))->toBe('Finished Goods')
        ->and($response->json('data.data.0.values_display'))->toBe('Red, Blue');
});

test('fetch variations api returns active records for scope', function () {
    $scope = seedVariationScope();

    Variation::query()->create([
        'company_id' => $scope['company_id'],
        'category_id' => $scope['category_id'],
        'subcategory_id' => $scope['subcategory_id'],
        'itemtype_id' => $scope['itemtype_id'],
        'values' => [['name' => 'Large', 'active' => true]],
        'priority' => 0,
        'active' => true,
    ]);

    Variation::query()->create([
        'company_id' => $scope['company_id'],
        'category_id' => $scope['category_id'],
        'subcategory_id' => null,
        'itemtype_id' => $scope['itemtype_id'],
        'values' => [['name' => 'Inactive', 'active' => true]],
        'priority' => 0,
        'active' => false,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/fetchvariations?'.http_build_query([
        'company_id' => $scope['company_id'],
        'category_id' => $scope['category_id'],
        'subcategory_id' => $scope['subcategory_id'],
        'itemtype_id' => $scope['itemtype_id'],
    ]));

    $response->assertSuccessful();
    expect($response->json())->toHaveCount(1);
});

test('variations api updates an existing variation', function () {
    $scope = seedVariationScope();
    $variation = Variation::query()->create([
        'company_id' => $scope['company_id'],
        'category_id' => $scope['category_id'],
        'subcategory_id' => $scope['subcategory_id'],
        'itemtype_id' => $scope['itemtype_id'],
        'values' => [['name' => 'Small', 'active' => true]],
        'priority' => 0,
        'active' => true,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->putJson('/api/variations/'.$variation->id, validVariationPayload($scope, [
        'values' => [
            ['name' => 'XL', 'active' => true],
        ],
    ]))->assertSuccessful();

    expect(Variation::query()->find($variation->id)?->values)->toBe([
        ['name' => 'XL', 'active' => true],
    ]);
});
