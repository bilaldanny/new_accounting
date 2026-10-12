<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\ItemType;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Wave 1b: a product import of a company user is recorded in the import history and can be undone while its products are untouched.
 */
function w1bProductScope(): array
{
    $scope = fcoScope();
    Unit::query()->create(['company_id' => $scope['company_id'], 'name' => 'Kilogram', 'short_name' => 'KG', 'type' => 'large', 'active' => true, 'auto_adjustment' => false]);
    Brand::query()->create(['company_id' => $scope['company_id'], 'name' => 'Farm Fresh', 'active' => true]);
    Category::query()->create(['company_id' => $scope['company_id'], 'name' => 'Grocery', 'active' => true]);
    ItemType::query()->create(['company_id' => $scope['company_id'], 'name' => 'Finished Goods', 'active' => true]);

    return $scope;
}

function w1bProductRow(string $name): array
{
    return ['id' => '', 'name' => $name, 'type' => 'single', 'unit' => 'Kilogram', 'brand' => 'Farm Fresh', 'category' => 'Grocery', 'item_type' => 'Finished Goods', 'purchase_price' => 80, 'margin' => 25, 'sell_price' => 100, 'active' => 1];
}

test('a product import is recorded, shown in the import history and undone while its products are unused', function () {
    $scope = w1bProductScope();
    Sanctum::actingAs(fcoStaff($scope, ['/product', '/product/import', '/businesssettings/import'], false));

    $this->postJson('/api/products/import', ['rows' => [w1bProductRow('Import Product A'), w1bProductRow('Import Product B')], 'file_name' => 'products.csv'])->assertSuccessful();
    $run = DB::table('import_runs')->first();
    $ids = json_decode($run->created_ids, true);

    expect($run->entity)->toBe('products')->and($ids)->toHaveCount(2)->and($run->file_name)->toBe('products.csv')
        ->and(Product::query()->whereIn('id', $ids)->count())->toBe(2)
        ->and(collect($this->getJson('/api/data-center/history')->json('data'))->pluck('entity')->all())->toBe(['products']);

    // one of the products gets a sale line: it stays, the other goes
    $sale = createSellRecord($scope, ['invoice_no' => 'IMP-P-1']);
    DB::table('sell_lines')->where('transaction_id', $sale->id)->update(['product_id' => $ids[0]]);

    $result = $this->postJson("/api/data-center/history/{$run->id}/undo")->assertOk()->json('data');

    expect($result['undone'])->toBe(1)->and($result['kept'][0]['id'])->toBe($ids[0])->and($result['kept'][0]['reason'])->toContain('sale')
        ->and(Product::query()->whereKey($ids[0])->exists())->toBeTrue()->and(Product::query()->whereKey($ids[1])->exists())->toBeFalse()
        ->and(DB::table('import_runs')->value('status'))->toBe('partly_undone');

    // an update of an existing product records nothing
    $before = DB::table('import_runs')->count();
    $this->postJson('/api/products/import', ['rows' => [['id' => $ids[0]] + w1bProductRow('Renamed A')]])->assertSuccessful();
    expect(DB::table('import_runs')->count())->toBe($before);
});

test('another company cannot undo a product import', function () {
    $scope = w1bProductScope();
    $other = fcoOtherCompany();
    Sanctum::actingAs(fcoStaff($scope, ['/product', '/product/import', '/businesssettings/import'], false));
    $this->postJson('/api/products/import', ['rows' => [w1bProductRow('Mine Only')]])->assertSuccessful();
    $run = DB::table('import_runs')->value('id');

    Sanctum::actingAs(fcoStaff($other, ['/businesssettings/import'], false));
    $this->postJson("/api/data-center/history/{$run}/undo")->assertNotFound();
    expect(Product::query()->where('name', 'Mine Only')->exists())->toBeTrue()->and(Role::query()->count())->toBeGreaterThan(0);
});
