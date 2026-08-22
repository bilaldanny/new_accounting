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

function seedProductImportScope(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'PRDI001',
        'name' => 'Product Import Company',
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

function createProductForImport(array $scope, array $attributes = []): Product
{
    $product = Product::query()->create(array_merge([
        'company_id' => $scope['company_id'],
        'unit_id' => $scope['unit_id'],
        'brand_id' => $scope['brand_id'],
        'category_id' => $scope['category_id'],
        'itemtype_id' => $scope['itemtype_id'],
        'name' => 'Existing Product',
        'sku' => 'AS-00009',
        'type' => 'single',
        'active' => true,
    ], $attributes));

    ProductDetail::query()->create([
        'product_id' => $product->id,
        'name' => $product->name.' dummy',
        'sku' => $product->sku.'-1',
        'variation_name' => 'dummy',
        'default_purchase_price' => 40,
        'dpp_unit_price' => 40,
        'largequantity' => 0,
        'smallquantity' => 0,
        'profit_percent' => 10,
        'default_sell_price' => 44,
    ]);

    return $product;
}

test('products import creates records when id is empty', function () {
    $scope = seedProductImportScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/products/import', [
        'rows' => [
            [
                'id' => '',
                'company_id' => $scope['company_id'],
                'name' => 'Imported Product',
                'type' => 'single',
                'unit' => 'Kilogram',
                'brand' => 'Farm Fresh',
                'category' => 'Grocery',
                'item_type' => 'Finished Goods',
                'purchase_price' => 80,
                'margin' => 25,
                'sell_price' => 100,
                'active' => 1,
            ],
        ],
    ])->assertSuccessful()
        ->assertJsonPath('message', 'Successfully imported 1 new and updated 0 product records.');

    $product = Product::query()->where('name', 'Imported Product')->first();

    expect($product)->not->toBeNull()
        ->and($product->company_id)->toBe($scope['company_id'])
        ->and($product->unit_id)->toBe($scope['unit_id'])
        ->and($product->type)->toBe('single')
        ->and($product->active)->toBeTrue();

    $this->assertDatabaseHas('product_details', [
        'product_id' => $product->id,
        'variation_name' => 'dummy',
        'default_purchase_price' => 80,
        'default_sell_price' => 100,
    ]);
});

test('products import updates records when id is provided', function () {
    $scope = seedProductImportScope();
    $product = createProductForImport($scope);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/products/import', [
        'rows' => [
            [
                'id' => $product->id,
                'name' => 'Updated Product Name',
                'unit' => 'Kilogram',
                'brand' => 'Farm Fresh',
                'category' => 'Grocery',
                'item_type' => 'Finished Goods',
                'active' => 0,
            ],
        ],
    ])->assertSuccessful()
        ->assertJsonPath('message', 'Successfully imported 0 new and updated 1 product records.');

    $product->refresh();

    expect($product->name)->toBe('Updated Product Name')
        ->and($product->active)->toBeFalse();
});
