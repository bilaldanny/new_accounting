<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\ItemType;
use App\Models\Product;
use App\Models\ProductDetail;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function seedProductScope(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'PRDC001',
        'name' => 'Product Test Company',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $unit = Unit::query()->create([
        'company_id' => $companyId,
        'name' => 'Kilogram',
        'short_name' => 'KG',
        'type' => 'large',
        'active' => true,
        'auto_adjustment' => false,
    ]);

    $brand = Brand::query()->create([
        'company_id' => $companyId,
        'name' => 'Farm Fresh',
        'active' => true,
    ]);

    $category = Category::query()->create([
        'company_id' => $companyId,
        'name' => 'Grocery',
        'active' => true,
    ]);

    $itemType = ItemType::query()->create([
        'company_id' => $companyId,
        'name' => 'Finished Goods',
        'active' => true,
    ]);

    return [
        'company_id' => $companyId,
        'unit_id' => $unit->id,
        'brand_id' => $brand->id,
        'category_id' => $category->id,
        'itemtype_id' => $itemType->id,
    ];
}

function validProductPayload(array $scope, array $overrides = []): array
{
    return array_merge([
        'name' => 'Premium Basmati Rice',
        'company_id' => $scope['company_id'],
        'unit_id' => $scope['unit_id'],
        'brand_id' => $scope['brand_id'],
        'category_id' => $scope['category_id'],
        'itemtype_id' => $scope['itemtype_id'],
        'type' => 'single',
        'alert_qty' => 10,
        'active' => true,
        'productdetail' => [
            [
                'variation_name' => 'dummy',
                'default_purchase_price' => 100,
                'profit_percent' => 20,
                'default_sell_price' => 120,
                'largequantity' => 10,
                'smallquantity' => 20,
            ],
        ],
    ], $overrides);
}

function createProductRecord(array $scope, array $attributes = []): Product
{
    $product = Product::query()->create(array_merge([
        'company_id' => $scope['company_id'],
        'unit_id' => $scope['unit_id'],
        'brand_id' => $scope['brand_id'],
        'category_id' => $scope['category_id'],
        'itemtype_id' => $scope['itemtype_id'],
        'name' => 'Existing Product',
        'sku' => 'AS-00001',
        'type' => 'single',
        'active' => true,
    ], $attributes));

    ProductDetail::query()->create([
        'product_id' => $product->id,
        'name' => $product->name.' dummy',
        'sku' => $product->sku.'-1',
        'variation_name' => 'dummy',
        'default_purchase_price' => 50,
        'dpp_unit_price' => 50,
        'largequantity' => 0,
        'smallquantity' => 0,
        'profit_percent' => 10,
        'default_sell_price' => 55,
    ]);

    return $product;
}

test('products api creates a product with required fields and pricing row', function () {
    $scope = seedProductScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/products', validProductPayload($scope))
        ->assertSuccessful();

    $product = Product::query()->where('name', 'Premium Basmati Rice')->first();

    expect($product)->not->toBeNull()
        ->and($product->company_id)->toBe($scope['company_id'])
        ->and($product->type)->toBe('single')
        ->and($product->sku)->not->toBeEmpty();

    $this->assertDatabaseHas('product_details', [
        'product_id' => $product->id,
        'variation_name' => 'dummy',
        'default_purchase_price' => 100,
        'default_sell_price' => 120,
    ]);
});

test('products api rejects duplicate names within the same company', function () {
    $scope = seedProductScope();
    createProductRecord($scope, ['name' => 'premium basmati rice']);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/products', validProductPayload($scope, [
        'name' => 'Premium  Basmati Rice',
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('products check-name api reports when a name already exists', function () {
    $scope = seedProductScope();
    createProductRecord($scope, ['name' => 'premium basmati rice']);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/products/check-name', [
        'name' => 'Premium Basmati Rice',
        'company_id' => $scope['company_id'],
    ])->assertSuccessful()
        ->assertJson(['name_taken' => true]);
});

test('products index returns related names and category label', function () {
    $scope = seedProductScope();
    createProductRecord($scope, ['name' => 'Premium Basmati Rice']);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/products');

    $response->assertSuccessful();
    expect($response->json('data.data.0.company_name'))->toBe('Product Test Company')
        ->and($response->json('data.data.0.category_label'))->toBe('Grocery')
        ->and($response->json('data.data.0.brand_name'))->toBe('Farm Fresh')
        ->and($response->json('data.data.0.itemtype_name'))->toBe('Finished Goods');
});

test('products api requires classification and pricing rows', function () {
    $scope = seedProductScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/products', validProductPayload($scope, [
        'unit_id' => '',
        'brand_id' => '',
        'category_id' => '',
        'itemtype_id' => '',
        'productdetail' => [],
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['unit_id', 'brand_id', 'category_id', 'itemtype_id', 'productdetail']);
});

test('products api generates sku when omitted', function () {
    $scope = seedProductScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/products', validProductPayload($scope, [
        'sku' => '',
    ]))->assertSuccessful();

    $product = Product::query()->where('name', 'Premium Basmati Rice')->first();

    expect($product?->sku)->toMatch('/^[A-Z]+-\d{5}$/');
});
