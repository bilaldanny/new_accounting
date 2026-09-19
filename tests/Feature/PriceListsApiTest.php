<?php

use App\Models\Brand;
use App\Models\PriceList;
use App\Models\PriceListDetail;
use App\Models\Product;
use App\Models\ProductDetail;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * @return array{company_id: int, branch_id: int, brand_id: int, product_id: int, variation_id: int, unit_id: int}
 */
function seedPriceListScope(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'PRL001',
        'name' => 'Price List Test Company',
        'address' => '1 Catalog Street',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $branchId = DB::table('branches')->insertGetId([
        'code' => 'PLB001',
        'company_id' => $companyId,
        'name' => 'Price List Branch',
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
        'name' => 'Global Foods',
        'active' => true,
    ]);

    $product = Product::query()->create([
        'company_id' => $companyId,
        'unit_id' => $unit->id,
        'brand_id' => $brand->id,
        'name' => 'Canned Tomatoes',
        'sku' => 'CT-00001',
        'type' => 'single',
        'active' => true,
    ]);

    $detail = ProductDetail::query()->create([
        'product_id' => $product->id,
        'name' => $product->name.' dummy',
        'sku' => $product->sku.'-1',
        'variation_name' => 'dummy',
        'default_purchase_price' => 80,
        'dpp_unit_price' => 80,
        'largequantity' => 10,
        'smallquantity' => 20,
        'profit_percent' => 25,
        'default_sell_price' => 100,
    ]);

    return [
        'company_id' => $companyId,
        'branch_id' => $branchId,
        'brand_id' => $brand->id,
        'unit_id' => $unit->id,
        'product_id' => $product->id,
        'variation_id' => $detail->id,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validPriceListPayload(array $scope, array $overrides = []): array
{
    return array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'brand_id' => $scope['brand_id'],
        'date' => '2026-09-18',
        'discount' => 5,
        'status' => 'pending',
        'pricelistdetails' => [
            [
                'product_id' => $scope['product_id'],
                'variation_id' => $scope['variation_id'],
                'unit_id' => $scope['unit_id'],
                'purchase_price' => 80,
                'sell_price' => 100,
                'profit_margin' => 25,
                'discount' => 0,
            ],
        ],
    ], $overrides);
}

test('price lists api creates a price list with line items', function () {
    $scope = seedPriceListScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/pricelists', validPriceListPayload($scope))
        ->assertSuccessful();

    $priceList = PriceList::query()->where('company_id', $scope['company_id'])->first();

    expect($priceList)->not->toBeNull()
        ->and($priceList->branch_id)->toBe($scope['branch_id'])
        ->and($priceList->brand_id)->toBe($scope['brand_id'])
        ->and((float) $priceList->discount)->toBe(5.0)
        ->and($priceList->status)->toBe('pending');

    expect(PriceListDetail::query()->where('list_id', $priceList->id)->count())->toBe(1);

    $line = PriceListDetail::query()->where('list_id', $priceList->id)->first();

    expect((float) $line->purchase_price)->toBe(80.0)
        ->and((float) $line->sell_price)->toBe(100.0)
        ->and((float) $line->profit_margin)->toBe(25.0);
});

test('price lists api rejects a price list without a brand or line items', function () {
    $scope = seedPriceListScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/pricelists', validPriceListPayload($scope, [
        'brand_id' => null,
        'pricelistdetails' => [],
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['brand_id', 'pricelistdetails']);
});

test('price lists search products endpoint returns products for the brand with default prices', function () {
    $scope = seedPriceListScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->getJson('/api/pricelists/search-products?company_id='.$scope['company_id'].'&branch_id='.$scope['branch_id'].'&brand_id='.$scope['brand_id'].'&search=Canned');

    $response->assertSuccessful();
    expect($response->json('0.product_id'))->toBe($scope['product_id'])
        ->and((float) $response->json('0.default_purchase_price'))->toBe(80.0)
        ->and((float) $response->json('0.default_sell_price'))->toBe(100.0);
});

test('price lists index lists price lists with brand and branch names', function () {
    $scope = seedPriceListScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/pricelists', validPriceListPayload($scope))->assertSuccessful();

    $response = $this->getJson('/api/pricelists');

    $response->assertSuccessful();
    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.company_name'))->toBe('Price List Test Company')
        ->and($response->json('data.data.0.branch_name'))->toBe('Price List Branch')
        ->and($response->json('data.data.0.brand_name'))->toBe('Global Foods')
        ->and($response->json('data.data.0.line_count'))->toBe(1);
});

test('price lists api updates lines, approves, and can soft delete a price list', function () {
    $scope = seedPriceListScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/pricelists', validPriceListPayload($scope))->assertSuccessful();

    $priceList = PriceList::query()->firstOrFail();

    $this->putJson('/api/pricelists/'.$priceList->id, validPriceListPayload($scope, [
        'status' => 'approved',
        'pricelistdetails' => [
            [
                'product_id' => $scope['product_id'],
                'variation_id' => $scope['variation_id'],
                'unit_id' => $scope['unit_id'],
                'purchase_price' => 85,
                'sell_price' => 110,
                'profit_margin' => 22,
                'discount' => 0,
            ],
        ],
    ]))->assertSuccessful();

    $priceList->refresh();

    expect($priceList->status)->toBe('approved')
        ->and($priceList->approved_by)->not->toBeNull();

    $line = PriceListDetail::query()->where('list_id', $priceList->id)->first();
    expect((float) $line->sell_price)->toBe(110.0);

    $this->postJson('/api/pricelists/bulk_delete', [$priceList->id])->assertSuccessful();

    expect(PriceList::query()->find($priceList->id))->toBeNull()
        ->and(PriceList::onlyTrashed()->find($priceList->id))->not->toBeNull();
});

test('price lists trash lists soft deleted price lists and restore brings them back', function () {
    $scope = seedPriceListScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/pricelists', validPriceListPayload($scope))->assertSuccessful();
    $priceList = PriceList::query()->firstOrFail();

    $this->postJson('/api/pricelists/bulk_delete', [$priceList->id])->assertSuccessful();

    $this->getJson('/api/pricelists/trash')
        ->assertSuccessful()
        ->assertJsonPath('data.data.0.brand_name', 'Global Foods');

    $this->postJson('/api/pricelists/restore_records', [$priceList->id])->assertSuccessful();

    expect(PriceList::query()->find($priceList->id))->not->toBeNull();
});

test('price lists api permanently deletes a price list from trash', function () {
    $scope = seedPriceListScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/pricelists', validPriceListPayload($scope))->assertSuccessful();
    $priceList = PriceList::query()->firstOrFail();

    $this->postJson('/api/pricelists/bulk_delete', [$priceList->id])->assertSuccessful();
    $this->postJson('/api/pricelists/bulk_delete_per', [$priceList->id])->assertSuccessful();

    expect(PriceList::onlyTrashed()->find($priceList->id))->toBeNull();
});

test('price lists store is forbidden without menu permission', function () {
    $scope = seedPriceListScope();

    $role = Role::query()->create([
        'name' => 'companyadmin',
        'company_id' => $scope['company_id'],
        'is_active' => true,
    ]);

    $user = createStaffUserForRole($role, [
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
    ]);

    Sanctum::actingAs($user);

    $this->postJson('/api/pricelists', validPriceListPayload($scope))
        ->assertForbidden();

    expect(PriceList::query()->count())->toBe(0);
});
