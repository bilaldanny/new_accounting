<?php

use App\Models\Contact;
use App\Models\ItemType;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Reports\AverageCost;
use App\Services\StockMovements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Products & Catalog closeout: item behaviours, custom attributes, tags, barcodes, kits and BOM, warranty claims,
 * consignment, discount rules, opening stock, reorder fields, bulk update and export.
 *
 * @return array<string, mixed>
 */
function pccScope(): array
{
    return seedSellScope();
}

/**
 * @param  list<string>  $paths
 */
function pccStaff(array $scope, array $paths, ?int $branchId = null): User
{
    $role = Role::query()->create(['name' => 'pcc_'.uniqid(), 'company_id' => $scope['company_id'], 'is_active' => true]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path);
    }

    return createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $branchId ?? $scope['branch_id'], 'email_verified_at' => now()]);
}

/**
 * A second company with its own branch, staff and product, for the isolation tests.
 *
 * @return array<string, mixed>
 */
function pccOtherCompany(): array
{
    $companyId = DB::table('companies')->insertGetId(['code' => 'PCC2', 'name' => 'Other Co', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $branchId = DB::table('branches')->insertGetId(['code' => 'PCCB2', 'company_id' => $companyId, 'name' => 'Other Branch', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $scope = ['company_id' => $companyId, 'branch_id' => $branchId];

    return $scope + prdProduct($scope, 'Other Product');
}

function pccItemType(array $scope, ?string $behaviour, string $name = 'Behaviour Type'): int
{
    return (int) ItemType::query()->create(['company_id' => $scope['company_id'], 'name' => $name.uniqid(), 'active' => true, 'behaviour' => $behaviour])->id;
}

/**
 * @return array{product_id: int, variation_id: int, unit_id: int}
 */
function pccProduct(array $scope, string $name, ?string $behaviour = null, array $attributes = []): array
{
    $itemType = $behaviour === null ? [] : ['itemtype_id' => pccItemType($scope, $behaviour)];

    return prdProduct($scope, $name, $attributes + $itemType, ['default_purchase_price' => 10, 'default_sell_price' => 15]);
}

function pccReceive(array $scope, array $product, float $quantity, float $rate = 10): void
{
    $id = DB::table('transactions')->insertGetId([
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'contact_id' => $scope['supplier_id'] ?? $scope['contact_id'],
        'invoice_no' => 'PCC-PO-'.uniqid(), 'type' => 'purchaseorder', 'status' => 'received', 'payment_status' => 'due',
        'transaction_date' => '2026-09-10', 'final_amount' => $quantity * $rate, 'total_item' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    prdPurchaseLine($id, $product, $quantity, $rate);
}

function pccSell(array $scope, array $product, float $quantity, array $document = []): int
{
    $id = DB::table('transactions')->insertGetId(array_merge([
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'contact_id' => $scope['contact_id'],
        'invoice_no' => 'PCC-INV-'.uniqid(), 'type' => 'sell', 'status' => 'final', 'payment_status' => 'due',
        'transaction_date' => '2026-09-12', 'final_amount' => $quantity * 15, 'total_item' => 1, 'created_at' => now(), 'updated_at' => now(),
    ], $document));
    prdSellLine($id, $product, $quantity, 15);

    return $id;
}

function pccStock(array $scope, array $product, ?int $branchId = null): float
{
    return StockMovements::baseStock($product['product_id'], $product['variation_id'], $branchId ?? $scope['branch_id']);
}

// ---------------------------------------------------------------------------------------------------------------------
// 1. Item behaviours

test('an item type keeps behaviour null by default and accepts only the five behaviours', function () {
    $scope = pccScope();
    Sanctum::actingAs(pccStaff($scope, ['/itemtype/add', '/itemtype/:id/edit']));

    $this->postJson('/api/item-types', ['name' => 'Plain Type', 'company_id' => $scope['company_id']])->assertOk();
    expect(ItemType::query()->where('name', 'Plain Type')->value('behaviour'))->toBeNull();

    $this->postJson('/api/item-types', ['name' => 'Bad Type', 'behaviour' => 'magic', 'company_id' => $scope['company_id']])->assertStatus(422);

    $this->postJson('/api/item-types', ['name' => 'Service Type', 'behaviour' => 'service', 'company_id' => $scope['company_id']])->assertOk();
    $id = ItemType::query()->where('name', 'Service Type')->value('id');
    expect(ItemType::query()->whereKey($id)->value('behaviour'))->toBe('service');

    // An update that does not name the field leaves it alone.
    $this->putJson("/api/item-types/{$id}", ['name' => 'Service Type 2', 'company_id' => $scope['company_id']])->assertOk();
    expect(ItemType::query()->whereKey($id)->value('behaviour'))->toBe('service');
});

test('a service, non-inventory or digital item never moves stock and is never blocked by it', function (string $behaviour) {
    $scope = pccScope();
    $item = pccProduct($scope, 'Delivery Fee', $behaviour);
    $normal = pccProduct($scope, 'Plain Goods');

    pccReceive($scope, $item, 5);
    pccSell($scope, $item, 2);
    pccReceive($scope, $normal, 5);
    pccSell($scope, $normal, 2);

    expect(pccStock($scope, $item))->toBe(0.0)
        ->and(pccStock($scope, $normal))->toBe(3.0)
        ->and(StockMovements::productKeepsStock($item['product_id']))->toBeFalse()
        ->and(StockMovements::productKeepsStock($normal['product_id']))->toBeTrue();

    // The POS would refuse a sale of more than is in stock: a service has no stock to run out of.
    Sanctum::actingAs(pccStaff($scope, ['/sell/pos', '/sell/add']));
    $payload = validSellPayload($scope, ['is_pos' => true, 'selllines' => [[
        'product_id' => $item['product_id'], 'variation_id' => $item['variation_id'], 'unit_id' => $item['unit_id'],
        'quantity' => 50, 'packing_qty' => 1, 'unit_price' => 15, 'discount_percent' => 0, 'unit_price_after_discount' => 15, 'row_subtotal' => 750,
    ]], 'final_amount' => 790]);
    $this->postJson('/api/sells', $payload)->assertOk();
})->with(['service', 'non_inventory', 'digital']);

test('stock of a product with no behaviour is exactly what it always was', function () {
    $scope = pccScope();
    $product = pccProduct($scope, 'Legacy Goods');
    $inventory = pccProduct($scope, 'Marked Inventory', 'inventory');

    foreach ([$product, $inventory] as $item) {
        pccReceive($scope, $item, 20, 8);
        pccSell($scope, $item, 6);
    }

    expect(pccStock($scope, $product))->toBe(14.0)
        ->and(pccStock($scope, $inventory))->toBe(14.0)
        ->and(AverageCost::byVariation($scope['company_id'])->get($product['variation_id']))->toBe(8.0);
});

// ---------------------------------------------------------------------------------------------------------------------
// 2. Custom attributes and tags

test('custom attributes are defined per company and validated when a product is saved', function () {
    $scope = pccScope();
    $other = pccOtherCompany();
    Sanctum::actingAs(pccStaff($scope, ['/productattribute', '/productattribute/add', '/productattribute/:id/edit', '/productattribute/delete', '/product/:id/edit']));

    $this->postJson('/api/product-attributes', ['name' => 'Material', 'type' => 'select', 'options' => ['Steel', 'Wood'], 'is_required' => true])->assertCreated();
    $this->postJson('/api/product-attributes', ['name' => 'Weight kg', 'type' => 'number'])->assertCreated();
    $this->postJson('/api/product-attributes', ['name' => 'Material', 'type' => 'text'])->assertStatus(422);
    $this->postJson('/api/product-attributes', ['name' => 'Pick', 'type' => 'select'])->assertStatus(422);

    $keys = DB::table('product_attribute_definitions')->where('company_id', $scope['company_id'])->pluck('field_key')->all();
    expect($keys)->toContain('material')->toContain('weight_kg');

    $detail = [['variation_name' => 'dummy', 'default_purchase_price' => 1, 'default_sell_price' => 2]];
    $base = ['company_id' => $scope['company_id'], 'name' => 'Chair', 'unit_id' => $scope['unit_id'], 'brand_id' => DB::table('brands')->value('id'), 'category_id' => $scope['category_id'], 'itemtype_id' => $scope['itemtype_id'], 'type' => 'single', 'productdetail' => $detail];

    $this->putJson('/api/products/'.$scope['product_id'], $base + ['name' => 'Premium Basmati Rice', 'custom_fields' => ['material' => 'Plastic']])->assertStatus(422);
    $this->putJson('/api/products/'.$scope['product_id'], $base + ['name' => 'Premium Basmati Rice', 'custom_fields' => ['weight_kg' => 'heavy', 'material' => 'Steel']])->assertStatus(422);
    $this->putJson('/api/products/'.$scope['product_id'], $base + ['name' => 'Premium Basmati Rice', 'custom_fields' => ['colour' => 'red', 'material' => 'Steel']])->assertStatus(422);
    $this->putJson('/api/products/'.$scope['product_id'], $base + ['name' => 'Premium Basmati Rice', 'custom_fields' => ['material' => 'Steel', 'weight_kg' => '2.5']])->assertOk();

    $this->getJson('/api/products/'.$scope['product_id'])->assertOk()->assertJsonPath('custom_fields.material', 'Steel');

    // A save that does not send the field leaves the values alone.
    $this->putJson('/api/products/'.$scope['product_id'], $base + ['name' => 'Premium Basmati Rice'])->assertOk();
    $this->getJson('/api/products/'.$scope['product_id'])->assertJsonPath('custom_fields.material', 'Steel');

    // Isolation: another company's definitions are neither listed nor editable.
    $foreign = DB::table('product_attribute_definitions')->insertGetId(['company_id' => $other['company_id'], 'name' => 'X', 'field_key' => 'x', 'type' => 'text', 'created_at' => now(), 'updated_at' => now()]);
    $this->getJson('/api/product-attributes')->assertOk()->assertJsonCount(2, 'data');
    $this->putJson("/api/product-attributes/{$foreign}", ['name' => 'Hacked', 'type' => 'text'])->assertNotFound();
    $this->deleteJson("/api/product-attributes/{$foreign}")->assertNotFound();
});

test('attribute pages need their menu permission', function () {
    $scope = pccScope();
    Sanctum::actingAs(pccStaff($scope, ['/product']));

    $this->getJson('/api/product-attributes')->assertForbidden();
    $this->postJson('/api/product-attributes', ['name' => 'A', 'type' => 'text'])->assertForbidden();
});

test('tags are cleaned, kept on the product and changed in bulk', function () {
    $scope = pccScope();
    Sanctum::actingAs(pccStaff($scope, ['/product/:id/edit']));

    $detail = [['variation_name' => 'dummy', 'default_purchase_price' => 1, 'default_sell_price' => 2]];
    $base = ['company_id' => $scope['company_id'], 'name' => 'Premium Basmati Rice', 'unit_id' => $scope['unit_id'], 'brand_id' => DB::table('brands')->value('id'), 'category_id' => $scope['category_id'], 'itemtype_id' => $scope['itemtype_id'], 'type' => 'single', 'productdetail' => $detail];

    $this->putJson('/api/products/'.$scope['product_id'], $base + ['tags' => [' Seasonal ', 'seasonal', 'Bulk']])->assertOk();
    $this->getJson('/api/products/'.$scope['product_id'])->assertJsonPath('tags', ['Seasonal', 'Bulk']);

    $this->postJson('/api/products/bulk-update', ['ids' => [$scope['product_id']], 'fields' => ['tags_add' => ['Clearance'], 'tags_remove' => ['seasonal']]])->assertOk();
    $this->getJson('/api/products/'.$scope['product_id'])->assertJsonPath('tags', ['Bulk', 'Clearance']);

    $this->putJson('/api/products/'.$scope['product_id'], $base + ['tags' => array_map(fn (int $i): string => "t{$i}", range(1, 25))])->assertStatus(422);
});

// ---------------------------------------------------------------------------------------------------------------------
// 3. Barcodes

test('a product answers to several barcodes, unique inside the company, and the sale search finds it', function () {
    $scope = pccScope();
    $other = pccProduct($scope, 'Second Product');
    Sanctum::actingAs(pccStaff($scope, ['/product/:id/edit', '/sell/add']));

    $detail = [['variation_name' => 'dummy', 'default_purchase_price' => 1, 'default_sell_price' => 2]];
    $base = ['company_id' => $scope['company_id'], 'name' => 'Premium Basmati Rice', 'unit_id' => $scope['unit_id'], 'brand_id' => DB::table('brands')->value('id'), 'category_id' => $scope['category_id'], 'itemtype_id' => $scope['itemtype_id'], 'type' => 'single', 'productdetail' => $detail];

    $this->putJson('/api/products/'.$scope['product_id'], $base + ['barcodes' => ['8901234567890', ['barcode' => 'CARTON-12', 'label' => 'Carton']]])->assertOk();
    $this->getJson('/api/products/'.$scope['product_id'])->assertJsonCount(2, 'barcodes');

    $this->getJson('/api/products/lookup?code=8901234567890')->assertOk()->assertJsonPath('product_id', $scope['product_id']);
    $variationId = (int) DB::table('product_details')->where('product_id', $scope['product_id'])->value('id');
    $variationSku = (string) DB::table('product_details')->where('product_id', $scope['product_id'])->value('sku');
    $this->getJson('/api/products/lookup?code='.$variationSku)->assertOk()->assertJsonPath('variation_id', $variationId);
    $this->getJson('/api/products/lookup?code=NOPE')->assertNotFound();

    $found = collect($this->getJson('/api/sells/search-products?search=CARTON-12')->assertOk()->json());
    expect($found->pluck('product_id')->all())->toBe([$scope['product_id']]);

    // The same barcode cannot belong to a second product, nor can it equal another product's SKU.
    $second = ['company_id' => $scope['company_id'], 'name' => 'Second Product', 'unit_id' => $other['unit_id'], 'brand_id' => DB::table('brands')->value('id'), 'category_id' => $scope['category_id'], 'itemtype_id' => $scope['itemtype_id'], 'type' => 'single', 'productdetail' => $detail];
    $this->putJson('/api/products/'.$other['product_id'], $second + ['barcodes' => ['CARTON-12']])->assertStatus(422);
    $this->putJson('/api/products/'.$other['product_id'], $second + ['barcodes' => [$variationSku]])->assertStatus(422);

    // Removing a barcode from the list drops it.
    $this->putJson('/api/products/'.$scope['product_id'], $base + ['barcodes' => ['8901234567890']])->assertOk();
    $this->getJson('/api/products/lookup?code=CARTON-12')->assertNotFound();
});

test('a barcode of another company is not found', function () {
    $scope = pccScope();
    $other = pccOtherCompany();
    DB::table('product_barcodes')->insert(['company_id' => $other['company_id'], 'product_id' => $other['product_id'], 'barcode' => 'FOREIGN-1', 'created_at' => now(), 'updated_at' => now()]);
    Sanctum::actingAs(pccStaff($scope, ['/sell/add']));

    $this->getJson('/api/products/lookup?code=FOREIGN-1')->assertNotFound();
});

// ---------------------------------------------------------------------------------------------------------------------
// 4. Kits and BOM

test('a kit sale takes its components out of stock, not itself, and a return gives them back', function () {
    $scope = pccScope();
    $kit = pccProduct($scope, 'Gift Box', 'kit');
    $pen = pccProduct($scope, 'Pen');
    $pad = pccProduct($scope, 'Pad');
    pccReceive($scope, $pen, 100);
    pccReceive($scope, $pad, 50);
    Sanctum::actingAs(pccStaff($scope, ['/productbom', '/productbom/:id/edit']));

    $this->putJson("/api/products/{$kit['product_id']}/components", ['components' => [
        ['component_product_id' => $pen['product_id'], 'component_variation_id' => $pen['variation_id'], 'quantity' => 2],
        ['component_product_id' => $pad['product_id'], 'quantity' => 1],
    ]])->assertOk();

    $this->getJson("/api/products/{$kit['product_id']}/components")->assertOk()->assertJsonCount(2, 'components');

    $saleId = pccSell($scope, $kit, 5);

    expect(pccStock($scope, $pen))->toBe(90.0)
        ->and(pccStock($scope, $pad))->toBe(45.0)
        ->and(pccStock($scope, $kit))->toBe(0.0);

    DB::table('sell_lines')->where('transaction_id', $saleId)->update(['quantity_returned' => 2]);

    expect(pccStock($scope, $pen))->toBe(94.0)
        ->and(pccStock($scope, $pad))->toBe(47.0);

    // The POS checks the components: 30 boxes need 60 pens and 30 pads, but only 47 pads are left.
    Sanctum::actingAs(pccStaff($scope, ['/sell/pos']));
    $line = fn (array $product, int $quantity): array => [
        'product_id' => $product['product_id'], 'variation_id' => $product['variation_id'], 'unit_id' => $product['unit_id'],
        'quantity' => $quantity, 'packing_qty' => 1, 'unit_price' => 15, 'discount_percent' => 0, 'unit_price_after_discount' => 15, 'row_subtotal' => 15 * $quantity,
    ];
    $this->postJson('/api/sells', validSellPayload($scope, ['is_pos' => true, 'selllines' => [$line($kit, 60)], 'final_amount' => 900]))->assertStatus(422);
    $this->postJson('/api/sells', validSellPayload($scope, ['is_pos' => true, 'selllines' => [$line($kit, 10)], 'final_amount' => 190]))->assertOk();
});

test('a component list cannot loop, include a service or point to another company', function () {
    $scope = pccScope();
    $other = pccOtherCompany();
    $a = pccProduct($scope, 'Part A');
    $b = pccProduct($scope, 'Part B');
    $service = pccProduct($scope, 'Labour', 'service');
    Sanctum::actingAs(pccStaff($scope, ['/productbom', '/productbom/:id/edit']));

    $put = fn (int $parent, array $components) => $this->putJson("/api/products/{$parent}/components", ['components' => $components]);

    $put($a['product_id'], [['component_product_id' => $b['product_id'], 'quantity' => 1]])->assertOk();
    $put($b['product_id'], [['component_product_id' => $a['product_id'], 'quantity' => 1]])->assertStatus(422);
    $put($a['product_id'], [['component_product_id' => $a['product_id'], 'quantity' => 1]])->assertStatus(422);
    $put($a['product_id'], [['component_product_id' => $service['product_id'], 'quantity' => 1]])->assertStatus(422);
    $put($a['product_id'], [['component_product_id' => $other['product_id'], 'quantity' => 1]])->assertStatus(422);
    $put($a['product_id'], [['component_product_id' => $b['product_id'], 'quantity' => 0]])->assertStatus(422);
    $this->putJson("/api/products/{$other['product_id']}/components", ['components' => []])->assertNotFound();
});

test('kitting and de-kitting are completed stock adjustments through the normal path', function () {
    $scope = pccScope();
    $finished = pccProduct($scope, 'Assembled Desk');
    $leg = pccProduct($scope, 'Leg');
    $top = pccProduct($scope, 'Top');
    pccReceive($scope, $leg, 40);
    pccReceive($scope, $top, 10);
    Sanctum::actingAs(pccStaff($scope, ['/productbom', '/productbom/:id/edit', '/productbom/kitting']));

    $this->putJson("/api/products/{$finished['product_id']}/components", ['components' => [
        ['component_product_id' => $leg['product_id'], 'quantity' => 4],
        ['component_product_id' => $top['product_id'], 'quantity' => 1],
    ]])->assertOk();

    $this->postJson("/api/products/{$finished['product_id']}/assemble", ['mode' => 'kit', 'quantity' => 5, 'date' => '2026-09-20'])->assertOk();

    expect(pccStock($scope, $finished))->toBe(5.0)
        ->and(pccStock($scope, $leg))->toBe(20.0)
        ->and(pccStock($scope, $top))->toBe(5.0)
        ->and(Transaction::query()->adjustments()->where('adjustment_type', 'unboxing')->where('status', 'completed')->count())->toBe(1);

    // 6 more would need 24 legs and there are 20: refused, nothing moves.
    $this->postJson("/api/products/{$finished['product_id']}/assemble", ['mode' => 'kit', 'quantity' => 6, 'date' => '2026-09-20'])->assertStatus(422);
    expect(pccStock($scope, $leg))->toBe(20.0);

    $this->postJson("/api/products/{$finished['product_id']}/assemble", ['mode' => 'dekit', 'quantity' => 2, 'date' => '2026-09-21'])->assertOk();

    expect(pccStock($scope, $finished))->toBe(3.0)
        ->and(pccStock($scope, $leg))->toBe(28.0)
        ->and(pccStock($scope, $top))->toBe(7.0);

    // A kit-behaviour product is not assembled.
    $kit = pccProduct($scope, 'Combo', 'kit');
    $this->putJson("/api/products/{$kit['product_id']}/components", ['components' => [['component_product_id' => $top['product_id'], 'quantity' => 1]]])->assertOk();
    $this->postJson("/api/products/{$kit['product_id']}/assemble", ['mode' => 'kit', 'quantity' => 1, 'date' => '2026-09-21'])->assertStatus(422);
});

// ---------------------------------------------------------------------------------------------------------------------
// 5. Warranty claims

function pccWarrantyProduct(array $scope, array $warranty = []): array
{
    $warrantyId = DB::table('warranties')->insertGetId($warranty + [
        'company_id' => $scope['company_id'], 'name' => 'Warranty '.uniqid(), 'duration' => 6, 'type' => 'month', 'active' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $product = pccProduct($scope, 'Appliance '.uniqid(), null, ['warranty_id' => $warrantyId]);

    return $product + ['warranty_id' => $warrantyId];
}

test('a warranty claim is judged against the sale date and the warranty rules', function () {
    $scope = pccScope();
    $product = pccWarrantyProduct($scope, ['require_invoice' => 1, 'max_claims' => 1]);
    $saleId = pccSell($scope, $product, 1, ['transaction_date' => '2026-03-01']);
    Sanctum::actingAs(pccStaff($scope, ['/warrantyclaim', '/warrantyclaim/add', '/warrantyclaim/:id/edit', '/warrantyclaim/delete']));

    $claim = ['product_id' => $product['product_id'], 'issue' => 'Does not switch on', 'claimed_on' => '2026-08-15'];

    $this->postJson('/api/warranty-claims', $claim)->assertStatus(422); // the warranty needs the invoice

    $first = $this->postJson('/api/warranty-claims', $claim + ['transaction_id' => $saleId])->assertCreated();
    $first->assertJsonPath('data.in_warranty', true)->assertJsonPath('data.warranty_expires_on', '2026-09-01')->assertJsonPath('data.claim_no', 'WC-00001');

    $this->postJson('/api/warranty-claims', $claim + ['transaction_id' => $saleId])->assertStatus(422); // one claim per sale

    $id = $first->json('data.id');
    $this->postJson("/api/warranty-claims/{$id}/status", ['status' => 'resolved', 'resolution' => 'repair'])->assertStatus(422); // not approved yet
    $this->postJson("/api/warranty-claims/{$id}/status", ['status' => 'in_review'])->assertOk();
    $this->postJson("/api/warranty-claims/{$id}/status", ['status' => 'approved'])->assertOk();
    $this->postJson("/api/warranty-claims/{$id}/status", ['status' => 'resolved'])->assertStatus(422); // needs a resolution
    $this->postJson("/api/warranty-claims/{$id}/status", ['status' => 'resolved', 'resolution' => 'replace', 'resolution_note' => 'Swapped'])->assertOk();
    $this->deleteJson("/api/warranty-claims/{$id}")->assertStatus(422);
});

test('a claim after the warranty ended needs a reason to be approved', function () {
    $scope = pccScope();
    $product = pccWarrantyProduct($scope);
    $saleId = pccSell($scope, $product, 1, ['transaction_date' => '2026-01-01']);
    Sanctum::actingAs(pccStaff($scope, ['/warrantyclaim', '/warrantyclaim/add', '/warrantyclaim/:id/edit']));

    $created = $this->postJson('/api/warranty-claims', ['product_id' => $product['product_id'], 'transaction_id' => $saleId, 'issue' => 'Broken hinge', 'claimed_on' => '2026-09-15'])->assertCreated();
    $created->assertJsonPath('data.in_warranty', false);
    $id = $created->json('data.id');

    $this->postJson("/api/warranty-claims/{$id}/status", ['status' => 'approved'])->assertStatus(422);
    $this->postJson("/api/warranty-claims/{$id}/status", ['status' => 'approved', 'resolution_note' => 'Goodwill, valued customer'])->assertOk();

    $noWarranty = pccProduct($scope, 'No Warranty Item');
    $this->postJson('/api/warranty-claims', ['product_id' => $noWarranty['product_id'], 'issue' => 'x y z', 'claimed_on' => '2026-09-15'])->assertStatus(422);
});

test('warranty claims are company and branch scoped', function () {
    $scope = pccScope();
    $other = pccOtherCompany();
    $product = pccWarrantyProduct($scope);

    $foreign = DB::table('warranty_claims')->insertGetId([
        'company_id' => $other['company_id'], 'branch_id' => $other['branch_id'], 'claim_no' => 'WC-00001', 'product_id' => $other['product_id'],
        'issue' => 'Foreign', 'claimed_on' => '2026-09-01', 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $branchB = DB::table('branches')->insertGetId(['code' => 'PCCB3', 'company_id' => $scope['company_id'], 'name' => 'Second Branch', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $ownBranchClaim = DB::table('warranty_claims')->insertGetId([
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'claim_no' => 'WC-00002', 'product_id' => $product['product_id'],
        'issue' => 'Mine', 'claimed_on' => '2026-09-01', 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('warranty_claims')->insert([
        'company_id' => $scope['company_id'], 'branch_id' => $branchB, 'claim_no' => 'WC-00003', 'product_id' => $product['product_id'],
        'issue' => 'Other branch', 'claimed_on' => '2026-09-01', 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
    ]);

    Sanctum::actingAs(pccStaff($scope, ['/warrantyclaim', '/warrantyclaim/:id/edit']));

    $ids = collect($this->getJson('/api/warranty-claims')->assertOk()->json('data.data'))->pluck('id')->all();
    expect($ids)->toBe([$ownBranchClaim]);
    $this->getJson("/api/warranty-claims/{$foreign}")->assertNotFound();
    $this->postJson("/api/warranty-claims/{$foreign}/status", ['status' => 'in_review'])->assertNotFound();
});

// ---------------------------------------------------------------------------------------------------------------------
// 6. Consignment

test('consignment ownership needs a supplier of the company and the stock report counts what was sold', function () {
    $scope = pccScope();
    $other = pccOtherCompany();
    $product = pccProduct($scope, 'Consigned Vase');
    Sanctum::actingAs(pccStaff($scope, ['/product/:id/edit', '/consignment', '/consignment/add', '/consignment/:id/edit', '/consignment/delete']));

    $detail = [['variation_name' => 'dummy', 'default_purchase_price' => 1, 'default_sell_price' => 2]];
    $payload = ['company_id' => $scope['company_id'], 'name' => 'Consigned Vase', 'unit_id' => $product['unit_id'], 'brand_id' => DB::table('brands')->value('id'), 'category_id' => $scope['category_id'], 'itemtype_id' => $scope['itemtype_id'], 'type' => 'single', 'productdetail' => $detail];

    $this->putJson('/api/products/'.$product['product_id'], $payload + ['ownership' => 'consignment'])->assertStatus(422);
    $this->putJson('/api/products/'.$product['product_id'], $payload + ['ownership' => 'consignment', 'consignment_supplier_id' => $scope['customer_id']])->assertStatus(422);

    $supplierId = (int) $scope['contact_id'] === (int) $scope['customer_id'] ? Contact::query()->where('user_type', 'supplier')->value('id') : $scope['contact_id'];
    $supplierId = Contact::query()->where('company_id', $scope['company_id'])->where('user_type', 'supplier')->value('id');
    expect($supplierId)->not->toBeNull();

    $this->putJson('/api/products/'.$product['product_id'], $payload + ['ownership' => 'consignment', 'consignment_supplier_id' => $supplierId])->assertOk();
    expect(DB::table('products')->where('id', $product['product_id'])->value('ownership'))->toBe('consignment');

    // The product form rewrites the variation rows, so the ids are read again.
    $product['variation_id'] = (int) DB::table('product_details')->where('product_id', $product['product_id'])->value('id');
    DB::table('product_details')->where('id', $product['variation_id'])->update(['default_purchase_price' => 40]);
    pccReceive($scope, $product, 10, 0);
    pccSell($scope, $product, 4, ['transaction_date' => '2026-09-05']);
    pccSell($scope, $product, 3, ['transaction_date' => '2026-09-20']);

    $stock = $this->getJson('/api/consignment/stock')->assertOk();
    $row = collect($stock->json('data'))->firstWhere('product_id', $product['product_id']);
    expect($row['on_hand'])->toEqual(3.0)->and($row['sold_unsettled'])->toEqual(7.0)->and($row['owed'])->toEqual(280.0);

    $period = ['supplier_id' => $supplierId, 'period_from' => '2026-09-01', 'period_to' => '2026-09-10'];
    $created = $this->postJson('/api/consignment/settlements', $period)->assertCreated();
    expect((float) $created->json('data.total_amount'))->toBe(160.0);
    $id = $created->json('data.id');

    $this->postJson('/api/consignment/settlements', ['period_from' => '2026-09-05', 'period_to' => '2026-09-30'] + $period)->assertStatus(422); // overlap
    $this->postJson('/api/consignment/settlements', ['period_from' => '2026-08-01', 'period_to' => '2026-08-31'] + $period)->assertStatus(422); // nothing sold

    $this->postJson("/api/consignment/settlements/{$id}/settle", ['reference' => 'BILL-77', 'settled_on' => '2026-09-12'])->assertOk()->assertJsonPath('data.status', 'settled');
    $this->deleteJson("/api/consignment/settlements/{$id}")->assertStatus(422);

    // What was settled is no longer owed.
    $after = collect($this->getJson('/api/consignment/stock')->json('data'))->firstWhere('product_id', $product['product_id']);
    expect($after['sold_unsettled'])->toEqual(3.0)->and($after['owed'])->toEqual(120.0);

    // Another company sees none of it.
    Sanctum::actingAs(pccStaff(['company_id' => $other['company_id'], 'branch_id' => $other['branch_id']], ['/consignment']));
    $this->getJson('/api/consignment/settlements')->assertOk()->assertJsonCount(0, 'data.data');
    $this->getJson("/api/consignment/settlements/{$id}")->assertNotFound();
});

// ---------------------------------------------------------------------------------------------------------------------
// 7. Discount rules

test('the narrowest matching discount rule wins and the product search carries the rules', function () {
    $scope = pccScope();
    Sanctum::actingAs(pccStaff($scope, ['/productdiscountrule', '/productdiscountrule/add', '/productdiscountrule/:id/edit', '/productdiscountrule/delete', '/sell', '/sell/add']));
    $brandId = (int) DB::table('products')->where('id', $scope['product_id'])->value('brand_id');

    $this->postJson('/api/product-discount-rules', ['name' => 'All 5', 'scope' => 'all', 'discount_type' => 'percent', 'value' => 5])->assertCreated();
    $this->postJson('/api/product-discount-rules', ['name' => 'Category 10', 'scope' => 'category', 'category_id' => $scope['category_id'], 'discount_type' => 'percent', 'value' => 10])->assertCreated();
    $this->postJson('/api/product-discount-rules', ['name' => 'Rice bulk', 'scope' => 'product', 'product_id' => $scope['product_id'], 'discount_type' => 'fixed', 'value' => 20, 'min_quantity' => 10])->assertCreated();
    $this->postJson('/api/product-discount-rules', ['name' => 'Old', 'scope' => 'brand', 'brand_id' => $brandId, 'discount_type' => 'percent', 'value' => 50, 'starts_on' => '2020-01-01', 'ends_on' => '2020-12-31'])->assertCreated();
    $this->postJson('/api/product-discount-rules', ['name' => 'Over', 'scope' => 'all', 'discount_type' => 'percent', 'value' => 150])->assertStatus(422);
    $this->postJson('/api/product-discount-rules', ['name' => 'Bad target', 'scope' => 'product', 'product_id' => 999999, 'discount_type' => 'percent', 'value' => 5])->assertStatus(422);

    $one = $this->getJson('/api/product-discount-rules/resolve?product_id='.$scope['product_id'].'&quantity=1')->assertOk();
    expect($one->json('rule.name'))->toBe('Category 10');

    $many = $this->getJson('/api/product-discount-rules/resolve?product_id='.$scope['product_id'].'&quantity=10')->assertOk();
    expect($many->json('rule.name'))->toBe('Rice bulk');

    $row = collect($this->getJson('/api/sells/search-products?search=Basmati')->assertOk()->json())->first();
    expect(collect($row['discount_rules'])->pluck('name')->all())->toBe(['Rice bulk', 'Category 10', 'All 5']);

    $id = DB::table('product_discount_rules')->where('name', 'Category 10')->value('id');
    $this->deleteJson("/api/product-discount-rules/{$id}")->assertOk();
});

test('discount rules of another company are neither listed nor applied', function () {
    $scope = pccScope();
    $other = pccOtherCompany();
    DB::table('product_discount_rules')->insert(['company_id' => $other['company_id'], 'name' => 'Foreign', 'scope' => 'all', 'discount_type' => 'percent', 'value' => 90, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $foreignId = DB::table('product_discount_rules')->where('name', 'Foreign')->value('id');
    Sanctum::actingAs(pccStaff($scope, ['/productdiscountrule', '/productdiscountrule/:id/edit', '/productdiscountrule/delete', '/sell']));

    $this->getJson('/api/product-discount-rules')->assertOk()->assertJsonCount(0, 'data');
    $this->putJson("/api/product-discount-rules/{$foreignId}", ['name' => 'x', 'scope' => 'all', 'discount_type' => 'percent', 'value' => 1])->assertNotFound();
    expect($this->getJson('/api/product-discount-rules/resolve?product_id='.$scope['product_id'])->json('rule'))->toBeNull();
});

// ---------------------------------------------------------------------------------------------------------------------
// 8. Opening stock

test('opening stock is entered once per variation and branch, valued, and does not post to the ledger', function () {
    $scope = pccScope();
    $product = pccProduct($scope, 'Opening Item');
    Sanctum::actingAs(pccStaff($scope, ['/openingstock', '/openingstock/add']));

    $journalsBefore = DB::table('t_accounts')->count();
    $rows = [['sku' => 'SKU-'.DB::table('products')->where('id', $product['product_id'])->value('id'), 'quantity' => 25, 'unit_cost' => 12.5]];
    $rows = [['product_id' => $product['product_id'], 'variation_id' => $product['variation_id'], 'quantity' => 25, 'unit_cost' => 12.5]];

    $check = $this->postJson('/api/opening-stock', ['date' => '2026-07-01', 'rows' => $rows, 'dry_run' => true])->assertOk();
    expect($check->json('dry_run'))->toBeTrue()->and(pccStock($scope, $product))->toBe(0.0);

    $this->postJson('/api/opening-stock', ['date' => '2026-07-01', 'rows' => $rows])->assertCreated();

    expect(pccStock($scope, $product))->toBe(25.0)
        ->and(AverageCost::byVariation($scope['company_id'])->get($product['variation_id']))->toBe(12.5)
        ->and(DB::table('t_accounts')->count())->toBe($journalsBefore);

    $this->postJson('/api/opening-stock', ['date' => '2026-07-02', 'rows' => $rows])->assertStatus(422);
    $this->getJson('/api/opening-stock')->assertOk()->assertJsonCount(1, 'data.data');

    // A SKU is accepted in place of ids; an unknown one, a service and a duplicate row are named.
    $second = pccProduct($scope, 'Second Opening');
    $service = pccProduct($scope, 'Opening Service', 'service');
    $bad = $this->postJson('/api/opening-stock', ['date' => '2026-07-03', 'rows' => [
        ['sku' => 'NO-SUCH-SKU', 'quantity' => 1],
        ['product_id' => $service['product_id'], 'variation_id' => $service['variation_id'], 'quantity' => 1],
    ]])->assertStatus(422);
    expect($bad->json('errors'))->toHaveCount(2);

    $skuOf = DB::table('product_details')->where('id', $second['variation_id'])->value('sku');
    $this->postJson('/api/opening-stock', ['date' => '2026-07-03', 'rows' => [['sku' => $skuOf, 'quantity' => 4]]])->assertCreated();
    expect(pccStock($scope, $second))->toBe(4.0);
});

test('average cost is untouched by adjustments that carry no opening cost', function () {
    $scope = pccScope();
    $product = pccProduct($scope, 'Adjusted Item');
    pccReceive($scope, $product, 10, 6);

    $adjustment = Transaction::query()->create([
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'invoice_no' => 'SA-PCC', 'type' => 'adjustment',
        'adjustment_type' => 'opening', 'status' => 'completed', 'transaction_date' => '2026-07-01', 'total_item' => 1,
    ]);
    DB::table('purchase_lines')->insert([
        'transaction_id' => $adjustment->id, 'product_id' => $product['product_id'], 'variation_id' => $product['variation_id'], 'unit_id' => $product['unit_id'],
        'quantity' => 1, 'quantity_adjustment' => 50, 'purchase_rate' => 999, 'packing_qty' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(AverageCost::byVariation($scope['company_id'])->get($product['variation_id']))->toBe(6.0)
        ->and(pccStock($scope, $product))->toBe(60.0);
});

// ---------------------------------------------------------------------------------------------------------------------
// 9. Reorder fields, bulk update and export

test('reorder settings are saved with their limits checked', function () {
    $scope = pccScope();
    Sanctum::actingAs(pccStaff($scope, ['/product/:id/edit']));

    $detail = [['variation_name' => 'dummy', 'default_purchase_price' => 1, 'default_sell_price' => 2]];
    $base = ['company_id' => $scope['company_id'], 'name' => 'Premium Basmati Rice', 'unit_id' => $scope['unit_id'], 'brand_id' => DB::table('brands')->value('id'), 'category_id' => $scope['category_id'], 'itemtype_id' => $scope['itemtype_id'], 'type' => 'single', 'productdetail' => $detail];

    $this->putJson('/api/products/'.$scope['product_id'], $base + ['reorder_level' => 10, 'reorder_qty' => 50, 'min_stock' => 5, 'max_stock' => 100, 'lead_time_days' => 7])->assertOk();
    $product = $this->getJson('/api/products/'.$scope['product_id'])->assertOk()->json();
    expect((float) $product['reorder_level'])->toBe(10.0)->and((float) $product['max_stock'])->toBe(100.0)->and($product['lead_time_days'])->toBe(7);

    $this->putJson('/api/products/'.$scope['product_id'], $base + ['min_stock' => 50, 'max_stock' => 5])->assertStatus(422);
    $this->putJson('/api/products/'.$scope['product_id'], $base + ['reorder_level' => -1])->assertStatus(422);
});

test('bulk update changes the chosen fields of the selected products only inside the company', function () {
    $scope = pccScope();
    $other = pccOtherCompany();
    $second = pccProduct($scope, 'Second Bulk');
    $newCategory = DB::table('categories')->insertGetId(['company_id' => $scope['company_id'], 'name' => 'Bulk Target', 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $foreignCategory = DB::table('categories')->insertGetId(['company_id' => $other['company_id'], 'name' => 'Foreign Cat', 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    Sanctum::actingAs(pccStaff($scope, ['/product/:id/edit']));

    $this->postJson('/api/products/bulk-update', ['ids' => [$scope['product_id'], $second['product_id']], 'fields' => ['category_id' => $newCategory, 'alert_qty' => 12, 'reorder_level' => 20, 'active' => false]])->assertOk()->assertJsonPath('updated', 2);

    expect(DB::table('products')->whereIn('id', [$scope['product_id'], $second['product_id']])->pluck('category_id')->unique()->all())->toBe([$newCategory])
        ->and((int) DB::table('products')->where('id', $scope['product_id'])->value('alert_qty'))->toBe(12)
        ->and((float) DB::table('products')->where('id', $second['product_id'])->value('reorder_level'))->toBe(20.0)
        ->and((int) DB::table('products')->where('id', $scope['product_id'])->value('active'))->toBe(0);

    $this->postJson('/api/products/bulk-update', ['ids' => [$scope['product_id']], 'fields' => ['category_id' => $foreignCategory]])->assertStatus(422);
    $this->postJson('/api/products/bulk-update', ['ids' => [$other['product_id']], 'fields' => ['alert_qty' => 1]])->assertStatus(422);
    expect(DB::table('products')->where('id', $other['product_id'])->value('alert_qty'))->toBeNull();

    Sanctum::actingAs(pccStaff($scope, ['/product']));
    $this->postJson('/api/products/bulk-update', ['ids' => [$scope['product_id']], 'fields' => ['alert_qty' => 1]])->assertForbidden();
});

test('the complete product export has every field and only the company products', function () {
    $scope = pccScope();
    $other = pccOtherCompany();
    DB::table('products')->where('id', $scope['product_id'])->update(['tags' => json_encode(['A', 'B']), 'reorder_level' => 7, 'lead_time_days' => 3]);
    DB::table('product_barcodes')->insert(['company_id' => $scope['company_id'], 'product_id' => $scope['product_id'], 'barcode' => 'EXP-1', 'created_at' => now(), 'updated_at' => now()]);
    Sanctum::actingAs(pccStaff($scope, ['/product', '/product/export']));

    $response = $this->get('/api/products/export-all');
    $response->assertOk();
    $csv = $response->streamedContent();
    $lines = array_map('str_getcsv', array_filter(explode("\n", $csv)));
    $header = $lines[0];

    expect($header)->toContain('product_sku', 'variation_sku', 'default_sell_price', 'tags', 'reorder_level', 'lead_time_days', 'ownership', 'barcodes', 'custom_fields')
        ->and($csv)->toContain('Premium Basmati Rice')->not->toContain('Other Product');

    $row = array_combine($header, $lines[1]);
    expect($row['tags'])->toBe('A,B')->and($row['barcodes'])->toBe('EXP-1');

    Sanctum::actingAs(pccStaff($scope, ['/product']));
    $this->get('/api/products/export-all')->assertForbidden();
});

// ---------------------------------------------------------------------------------------------------------------------
// 14. Menus

test('the catalog menu migration adds each page once, in the product group, and grants it to companyadmin', function () {
    $admin = Role::query()->firstOrCreate(['name' => 'companyadmin', 'company_id' => null], ['is_active' => true])->id;
    $group = DB::table('menus')->where('route_path', '/product')->value('parent_id')
        ?? DB::table('menus')->insertGetId(['name' => 'Products', 'route_name' => 'products-group', 'route_path' => '/products-group', 'icon' => 'Grid', 'menu_color' => '#000', 'sort_order' => 1, 'is_hidden' => 0, 'is_active' => 1, 'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now()]);

    if (! DB::table('menus')->where('route_path', '/product')->exists()) {
        DB::table('menus')->insert(['parent_id' => $group, 'name' => 'Product', 'route_name' => 'product', 'route_path' => '/product', 'icon' => 'Grid', 'menu_color' => '#000', 'sort_order' => 1, 'is_hidden' => 0, 'is_active' => 1, 'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    $migration = require database_path('migrations/2026_11_04_110000_add_product_catalog_menus.php');
    $migration->up();
    $migration->up();

    $paths = ['/productattribute', '/productdiscountrule', '/warrantyclaim', '/consignment', '/productbom', '/openingstock'];
    $granted = DB::table('permissions')->join('menus', 'menus.id', '=', 'permissions.menu_id')
        ->where('permissions.role_id', $admin)->whereIn('menus.route_path', $paths)->pluck('menus.route_path')->sort()->values()->all();

    expect($granted)->toBe(collect($paths)->sort()->values()->all());

    foreach ($paths as $path) {
        expect(DB::table('menus')->where('route_path', $path)->count())->toBe(1)
            ->and((int) DB::table('menus')->where('route_path', $path)->value('parent_id'))->toBe((int) $group);
    }
});
