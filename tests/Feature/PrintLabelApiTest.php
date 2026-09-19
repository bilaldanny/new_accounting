<?php

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductDetail;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('print label search products returns matching products with default prices', function () {
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'PLB001',
        'name' => 'Print Label Test Company',
        'address' => '1 Barcode Street',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $branchId = DB::table('branches')->insertGetId([
        'code' => 'PLBR001',
        'company_id' => $companyId,
        'name' => 'Print Label Branch',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $unit = Unit::query()->create([
        'company_id' => $companyId,
        'name' => 'Box',
        'short_name' => 'BX',
        'type' => 'large',
        'active' => true,
        'auto_adjustment' => false,
    ]);

    $brand = Brand::query()->create([
        'company_id' => $companyId,
        'name' => 'Label Brand',
        'active' => true,
    ]);

    $product = Product::query()->create([
        'company_id' => $companyId,
        'unit_id' => $unit->id,
        'brand_id' => $brand->id,
        'name' => 'Sparkling Water',
        'sku' => 'SW-00001',
        'type' => 'single',
        'active' => true,
    ]);

    ProductDetail::query()->create([
        'product_id' => $product->id,
        'name' => $product->name.' dummy',
        'sku' => $product->sku.'-1',
        'variation_name' => 'dummy',
        'default_purchase_price' => 30,
        'dpp_unit_price' => 30,
        'largequantity' => 10,
        'smallquantity' => 20,
        'profit_percent' => 50,
        'default_sell_price' => 45,
    ]);

    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->getJson('/api/printlabels/search-products?company_id='.$companyId.'&branch_id='.$branchId.'&search=Sparkling');

    $response->assertSuccessful();
    expect($response->json('0.product_id'))->toBe($product->id)
        ->and($response->json('0.sku'))->toBe('SW-00001-1')
        ->and((float) $response->json('0.default_sell_price'))->toBe(45.0);

    $role = Role::query()->create([
        'name' => 'companyadmin',
        'company_id' => $companyId,
        'is_active' => true,
    ]);

    $user = createStaffUserForRole($role, [
        'company_id' => $companyId,
        'branch_id' => $branchId,
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/printlabels/search-products?company_id='.$companyId.'&branch_id='.$branchId.'&search=Sparkling')
        ->assertForbidden();
});
