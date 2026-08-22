<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\ItemType;
use App\Models\Product;
use App\Models\ProductDetail;
use App\Models\Unit;
use App\Models\User;
use App\Models\Variation;
use App\Support\VariantCombiner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * @return array{
 *     company_id: int,
 *     unit_id: int,
 *     brand_id: int,
 *     category_id: int,
 *     subcategory_id: int,
 *     itemtype_id: int,
 *     ram: Variation,
 *     storage: Variation,
 *     color: Variation
 * }
 */
function seedMobileVariantScope(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'VARP001',
        'name' => 'Variant Product Company',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $unit = Unit::query()->create([
        'company_id' => $companyId,
        'name' => 'Piece',
        'short_name' => 'PC',
        'type' => 'large',
        'active' => true,
        'auto_adjustment' => false,
    ]);

    $brand = Brand::query()->create([
        'company_id' => $companyId,
        'name' => 'Samsung',
        'active' => true,
    ]);

    $category = Category::query()->create([
        'company_id' => $companyId,
        'name' => 'Electronics',
        'active' => true,
    ]);

    $subcategory = Category::query()->create([
        'company_id' => $companyId,
        'parent_id' => $category->id,
        'name' => 'Mobile & Tablets',
        'active' => true,
    ]);

    $itemType = ItemType::query()->create([
        'company_id' => $companyId,
        'name' => 'Stock Item',
        'active' => true,
    ]);

    $ram = Variation::query()->create([
        'company_id' => $companyId,
        'category_id' => $category->id,
        'subcategory_id' => $subcategory->id,
        'itemtype_id' => $itemType->id,
        'name' => 'RAM',
        'values' => [
            ['name' => '4 GB', 'active' => true],
            ['name' => '6 GB', 'active' => true],
            ['name' => '8 GB', 'active' => true],
        ],
        'priority' => 0,
        'active' => true,
    ]);

    $storage = Variation::query()->create([
        'company_id' => $companyId,
        'category_id' => $category->id,
        'subcategory_id' => $subcategory->id,
        'itemtype_id' => $itemType->id,
        'name' => 'Storage',
        'values' => [
            ['name' => '128 GB', 'active' => true],
            ['name' => '256 GB', 'active' => true],
        ],
        'priority' => 0,
        'active' => true,
    ]);

    $color = Variation::query()->create([
        'company_id' => $companyId,
        'category_id' => $category->id,
        'subcategory_id' => $subcategory->id,
        'itemtype_id' => $itemType->id,
        'name' => 'Color',
        'values' => [
            ['name' => 'Black', 'active' => true],
            ['name' => 'Blue', 'active' => true],
            ['name' => 'Gold', 'active' => false],
        ],
        'priority' => 0,
        'active' => true,
    ]);

    return [
        'company_id' => $companyId,
        'unit_id' => $unit->id,
        'brand_id' => $brand->id,
        'category_id' => $category->id,
        'subcategory_id' => $subcategory->id,
        'itemtype_id' => $itemType->id,
        'ram' => $ram,
        'storage' => $storage,
        'color' => $color,
    ];
}

/**
 * @param  array<string, mixed>  $scope
 * @return list<array<string, mixed>>
 */
function mobileVariantSelections(array $scope): array
{
    return [
        ['variation_id' => $scope['ram']->id, 'values' => ['4 GB', '6 GB', '8 GB']],
        ['variation_id' => $scope['storage']->id, 'values' => ['128 GB', '256 GB']],
        ['variation_id' => $scope['color']->id, 'values' => ['Black', 'Blue']],
    ];
}

test('fetch variations returns every active variation in the same scope', function () {
    $scope = seedMobileVariantScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/fetchvariations?'.http_build_query([
        'company_id' => $scope['company_id'],
        'category_id' => $scope['category_id'],
        'subcategory_id' => $scope['subcategory_id'],
        'itemtype_id' => $scope['itemtype_id'],
    ]));

    $response->assertSuccessful();
    expect($response->json())->toHaveCount(3)
        ->and(collect($response->json())->pluck('name')->all())->toBe(['RAM', 'Storage', 'Color']);
});

test('generate variants api creates twelve ram storage color combinations', function () {
    $scope = seedMobileVariantScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->postJson('/api/products/generate-variants', [
        'company_id' => $scope['company_id'],
        'category_id' => $scope['category_id'],
        'subcategory_id' => $scope['subcategory_id'],
        'itemtype_id' => $scope['itemtype_id'],
        'product_sku' => 'SAM-A15',
        'selections' => mobileVariantSelections($scope),
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('count', 12)
        ->assertJsonPath('combinations.0.variation_name', '4 GB / 128 GB / Black')
        ->assertJsonPath('combinations.0.sku', 'SAM-A15-1')
        ->assertJsonPath('combinations.11.variation_name', '8 GB / 256 GB / Blue')
        ->assertJsonPath('combinations.11.sku', 'SAM-A15-12');

    expect($response->json('combinations'))->toHaveCount(12)
        ->and(collect($response->json('combinations'))->pluck('variation_name')->unique())->toHaveCount(12);
});

test('generate variants api rejects inactive values', function () {
    $scope = seedMobileVariantScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/products/generate-variants', [
        'company_id' => $scope['company_id'],
        'category_id' => $scope['category_id'],
        'subcategory_id' => $scope['subcategory_id'],
        'itemtype_id' => $scope['itemtype_id'],
        'selections' => [
            ['variation_id' => $scope['color']->id, 'values' => ['Black', 'Gold']],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['selections']);
});

test('generate variants api rejects another companys variation ids', function () {
    $scope = seedMobileVariantScope();
    $otherCompanyId = DB::table('companies')->insertGetId([
        'code' => 'VARP002',
        'name' => 'Other Variant Company',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/products/generate-variants', [
        'company_id' => $otherCompanyId,
        'category_id' => $scope['category_id'],
        'subcategory_id' => $scope['subcategory_id'],
        'itemtype_id' => $scope['itemtype_id'],
        'selections' => mobileVariantSelections($scope),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['selections']);
});

test('generate variants api rejects combination counts above the maximum', function () {
    $scope = seedMobileVariantScope();
    $values = array_map(fn (int $index): string => 'V'.$index, range(1, 25));

    $scope['ram']->update([
        'values' => array_map(fn (string $name): array => ['name' => $name, 'active' => true], $values),
    ]);
    $scope['storage']->update([
        'values' => array_map(fn (string $name): array => ['name' => $name, 'active' => true], $values),
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/products/generate-variants', [
        'company_id' => $scope['company_id'],
        'category_id' => $scope['category_id'],
        'subcategory_id' => $scope['subcategory_id'],
        'itemtype_id' => $scope['itemtype_id'],
        'selections' => [
            ['variation_id' => $scope['ram']->id, 'values' => $values],
            ['variation_id' => $scope['storage']->id, 'values' => $values],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['selections']);
});

test('products api creates a variable product with twelve generated variants', function () {
    $scope = seedMobileVariantScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $generated = VariantCombiner::generateForScope(
        $scope['company_id'],
        $scope['category_id'],
        $scope['subcategory_id'],
        $scope['itemtype_id'],
        mobileVariantSelections($scope),
        'SAM-A15',
    );

    $details = array_map(fn (array $combination, int $index): array => [
        'variation_name' => $combination['variation_name'],
        'sku' => $combination['sku'],
        'default_purchase_price' => 45000 + ($index * 100),
        'profit_percent' => 20,
        'default_sell_price' => 0,
        'largequantity' => 0,
        'smallquantity' => 0,
    ], $generated['combinations'], array_keys($generated['combinations']));

    $this->postJson('/api/products', [
        'name' => 'Samsung Galaxy A15 5G',
        'company_id' => $scope['company_id'],
        'unit_id' => $scope['unit_id'],
        'brand_id' => $scope['brand_id'],
        'category_id' => $scope['category_id'],
        'subcategory_id' => $scope['subcategory_id'],
        'itemtype_id' => $scope['itemtype_id'],
        'type' => 'variable',
        'sku' => 'SAM-A15',
        'active' => true,
        'productdetail' => $details,
    ])->assertSuccessful();

    $product = Product::query()->where('name', 'Samsung Galaxy A15 5G')->first();

    expect($product)->not->toBeNull()
        ->and($product->type)->toBe('variable')
        ->and($product->sku)->toBe('SAM-A15')
        ->and($product->productdetail)->toHaveCount(12);

    $first = $product->productdetail->firstWhere('variation_name', '4 GB / 128 GB / Black');

    expect($first)->not->toBeNull()
        ->and($first->sku)->toBe('SAM-A15-1')
        ->and((float) $first->default_purchase_price)->toBe(45000.0)
        ->and((float) $first->profit_percent)->toBe(20.0)
        ->and((float) $first->default_sell_price)->toBe(54000.0);
});

test('products api rejects duplicate variant combinations', function () {
    $scope = seedMobileVariantScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/products', [
        'name' => 'Duplicate Combo Phone',
        'company_id' => $scope['company_id'],
        'unit_id' => $scope['unit_id'],
        'brand_id' => $scope['brand_id'],
        'category_id' => $scope['category_id'],
        'subcategory_id' => $scope['subcategory_id'],
        'itemtype_id' => $scope['itemtype_id'],
        'type' => 'variable',
        'active' => true,
        'productdetail' => [
            [
                'variation_name' => '4 GB / 128 GB / Black',
                'default_purchase_price' => 45000,
                'profit_percent' => 20,
            ],
            [
                'variation_name' => '4 GB / 128 GB / Black',
                'default_purchase_price' => 46000,
                'profit_percent' => 20,
            ],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['productdetail']);
});

test('products api preserves existing variant skus and prices on edit', function () {
    $scope = seedMobileVariantScope();
    $product = Product::query()->create([
        'company_id' => $scope['company_id'],
        'unit_id' => $scope['unit_id'],
        'brand_id' => $scope['brand_id'],
        'category_id' => $scope['category_id'],
        'subcategory_id' => $scope['subcategory_id'],
        'itemtype_id' => $scope['itemtype_id'],
        'name' => 'Galaxy A15',
        'sku' => 'SAM-A15',
        'type' => 'variable',
        'active' => true,
    ]);

    $existing = ProductDetail::query()->create([
        'product_id' => $product->id,
        'name' => 'Galaxy A15 4 GB / 128 GB / Black',
        'sku' => 'SAM-A15-4-128-BLK',
        'variation_name' => '4 GB / 128 GB / Black',
        'default_purchase_price' => 45000,
        'dpp_unit_price' => 45000,
        'largequantity' => 2,
        'smallquantity' => 3,
        'profit_percent' => 20,
        'default_sell_price' => 54000,
        'variation_image' => 'phones/black.png',
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->putJson('/api/products/'.$product->id, [
        'name' => 'Galaxy A15',
        'company_id' => $scope['company_id'],
        'unit_id' => $scope['unit_id'],
        'brand_id' => $scope['brand_id'],
        'category_id' => $scope['category_id'],
        'subcategory_id' => $scope['subcategory_id'],
        'itemtype_id' => $scope['itemtype_id'],
        'type' => 'variable',
        'sku' => 'SAM-A15',
        'active' => true,
        'productdetail' => [
            [
                'id' => $existing->id,
                'variation_name' => '4 GB / 128 GB / Black',
                'sku' => 'SAM-A15-4-128-BLK',
                'default_purchase_price' => 45000,
                'profit_percent' => 20,
                'default_sell_price' => 54000,
                'largequantity' => 2,
                'smallquantity' => 3,
                'variation_image' => 'phones/black.png',
            ],
            [
                'variation_name' => '4 GB / 128 GB / Blue',
                'sku' => 'SAM-A15-4-128-BLU',
                'default_purchase_price' => 45500,
                'profit_percent' => 20,
                'default_sell_price' => 54600,
            ],
        ],
    ])->assertSuccessful();

    $product->refresh()->load('productdetail');

    expect($product->productdetail)->toHaveCount(2);

    $kept = $product->productdetail->firstWhere('id', $existing->id);

    expect($kept)->not->toBeNull()
        ->and($kept->sku)->toBe('SAM-A15-4-128-BLK')
        ->and((float) $kept->default_purchase_price)->toBe(45000.0)
        ->and($kept->variation_image)->toBe('phones/black.png');
});

test('single product creation is unchanged', function () {
    $scope = seedMobileVariantScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/products', [
        'name' => 'Standard Adapter',
        'company_id' => $scope['company_id'],
        'unit_id' => $scope['unit_id'],
        'brand_id' => $scope['brand_id'],
        'category_id' => $scope['category_id'],
        'itemtype_id' => $scope['itemtype_id'],
        'type' => 'single',
        'active' => true,
        'productdetail' => [
            [
                'variation_name' => 'dummy',
                'default_purchase_price' => 100,
                'profit_percent' => 10,
                'default_sell_price' => 110,
            ],
        ],
    ])->assertSuccessful();

    $product = Product::query()->where('name', 'Standard Adapter')->first();

    expect($product?->type)->toBe('single')
        ->and($product?->productdetail)->toHaveCount(1)
        ->and($product?->productdetail->first()?->variation_name)->toBe('dummy');
});
