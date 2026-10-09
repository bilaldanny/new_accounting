<?php

use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductDetail;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\PriceRules;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-15 12:00:00');
    Sanctum::actingAs(User::query()->findOrFail(1));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * An approved price list for the scope's brand or a contact, with one line (the scope's variation) and tiers.
 *
 * @param  array<string, mixed>  $scope
 * @param  list<array{min_qty: float|int, sell_price: float|int}>  $tiers
 */
function pcList(array $scope, float $price, array $tiers = [], array $attributes = []): PriceList
{
    $list = PriceList::query()->create(array_merge([
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'brand_id' => $scope['brand_id'] ?? null,
        'date' => '2026-09-01', 'discount' => 0, 'status' => 'approved',
    ], $attributes));

    $detail = $list->pricelistdetails()->create([
        'product_id' => $scope['product_id'], 'variation_id' => $scope['variation_id'], 'unit_id' => $scope['unit_id'],
        'purchase_price' => 50, 'sell_price' => $price, 'profit_margin' => 0, 'discount' => $attributes['line_discount'] ?? 0,
    ]);

    foreach ($tiers as $tier) {
        $detail->tiers()->create($tier);
    }

    return $list;
}

/**
 * @param  array<string, mixed>  $scope
 * @return array<string, mixed>
 */
function pcResolve(array $scope, int $quantity = 1, ?int $contactId = null): array
{
    return test()->getJson('/api/pricing/resolve?'.http_build_query(array_filter([
        'product_id' => $scope['product_id'], 'variation_id' => $scope['variation_id'], 'quantity' => $quantity, 'contact_id' => $contactId,
    ])))->assertSuccessful()->json('data');
}

test('quantity break tiers are saved with the price list and come back on show', function () {
    $scope = seedPriceListScope();
    $payload = validPriceListPayload($scope);
    $payload['pricelistdetails'][0]['tiers'] = [
        ['min_qty' => 50, 'sell_price' => 90], ['min_qty' => 10, 'sell_price' => 95], ['min_qty' => 10, 'sell_price' => 96], ['min_qty' => 0, 'sell_price' => 1],
    ];

    $this->postJson('/api/pricelists', $payload)->assertUnprocessable()->assertJsonValidationErrors(['pricelistdetails.0.tiers.3.min_qty']);

    array_pop($payload['pricelistdetails'][0]['tiers']);
    $this->postJson('/api/pricelists', $payload)->assertSuccessful();

    $id = PriceList::query()->latest('id')->value('id');
    $line = $this->getJson("/api/pricelists/{$id}")->assertSuccessful()->json('pricelistdetails.0');

    expect($line['tiers'])->toEqual([['min_qty' => 10, 'sell_price' => 96], ['min_qty' => 50, 'sell_price' => 90]])
        ->and($line['tiers_text'])->toBe('10:96, 50:90');

    $payload['pricelistdetails'][0]['tiers'] = [];
    $this->putJson("/api/pricelists/{$id}", $payload)->assertSuccessful();

    expect($this->getJson("/api/pricelists/{$id}")->json('pricelistdetails.0.tiers'))->toBe([])
        ->and(DB::table('price_list_tiers')->count())->toBe(0);
});

test('the price comes from the default, then a brand list with its quantity breaks', function () {
    $scope = seedPriceListScope();

    expect(pcResolve($scope)['source'])->toBe('default')
        ->and(pcResolve($scope)['price'])->toEqual(100);

    pcList($scope, 98, [['min_qty' => 10, 'sell_price' => 95], ['min_qty' => 50, 'sell_price' => 90]], ['line_discount' => 2]);

    $one = pcResolve($scope, 1);
    $ten = pcResolve($scope, 12);
    $fifty = pcResolve($scope, 50);

    expect($one['source'])->toBe('brand_price_list')
        ->and($one['price'])->toEqual(98)
        ->and($one['discount_percent'])->toEqual(2)
        ->and($one['tier_min_qty'])->toBeNull()
        ->and($ten['price'])->toEqual(95)
        ->and($ten['tier_min_qty'])->toEqual(10)
        ->and($fifty['price'])->toEqual(90)
        ->and($fifty['default_price'])->toEqual(100);
});

test('a customer price list beats the brand list, and pending, future and other branch lists are ignored', function () {
    $scope = seedPriceListScope();
    $customer = Contact::query()->create([
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'business_name' => 'VIP', 'first_name' => 'V', 'mobile' => '0300',
        'address' => 'x', 'code' => 'CU-V', 'user_type' => 'customer', 'type' => 'local', 'ntn_number' => '1', 'active' => true,
    ]);

    pcList($scope, 98);
    pcList($scope, 80, [], ['contact_id' => $customer->id, 'brand_id' => null]);
    pcList($scope, 10, [], ['status' => 'pending', 'contact_id' => $customer->id, 'brand_id' => null]);
    pcList($scope, 11, [], ['date' => '2026-10-01', 'contact_id' => $customer->id, 'brand_id' => null]);

    expect(pcResolve($scope, 1, $customer->id)['price'])->toEqual(80)
        ->and(pcResolve($scope, 1, $customer->id)['source'])->toBe('contact_price_list')
        ->and(pcResolve($scope, 1)['price'])->toEqual(98)
        ->and(pcResolve($scope, 1, 999999)['source'])->toBe('brand_price_list');
});

test('a later effective date wins among lists of the same kind', function () {
    $scope = seedPriceListScope();
    pcList($scope, 90, [], ['date' => '2026-08-01']);
    pcList($scope, 85, [], ['date' => '2026-09-10']);

    expect(pcResolve($scope)['price'])->toEqual(85);
});

test('only a user of the products company may ask for its price', function () {
    $scope = seedPriceListScope();
    $other = trpScope('P');
    $role = Role::query()->create(['name' => 'seller', 'company_id' => $other['company_id'], 'is_active' => true]);
    grantMenuPermission($role->id, '/sell', 'sell'.uniqid());
    Sanctum::actingAs(createStaffUserForRole($role, ['company_id' => $other['company_id'], 'branch_id' => $other['branch_id']]));

    test()->getJson('/api/pricing/resolve?product_id='.$scope['product_id'])->assertNotFound();
});

test('a sale line outside the minimum or maximum price is refused unless the user may override', function () {
    $scope = seedPriceListScope();
    ProductDetail::query()->findOrFail($scope['variation_id'])->update(['min_sell_price' => 90, 'max_sell_price' => 150]);

    $role = Role::query()->create(['name' => 'cashier', 'company_id' => $scope['company_id'], 'is_active' => true]);
    Sanctum::actingAs(createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']]));

    $line = fn (float $price): array => [['variation_id' => $scope['variation_id'], 'unit_price' => $price, 'unit_price_after_discount' => $price]];
    $rules = app(PriceRules::class);

    $rules->assertSellLines($line(100));
    $rules->assertSellLines($line(90));
    $rules->assertSellLines($line(150));

    foreach ([[80, 'minimum'], [151, 'maximum']] as [$price, $word]) {
        try {
            $rules->assertSellLines($line($price));
            $this->fail('should have been refused');
        } catch (ValidationException $e) {
            expect($e->errors()['selllines.0.unit_price'][0])->toContain($word);
        }
    }

    grantMenuPermission($role->id, '/sell/price-override', 'priceoverride'.uniqid());
    $rules->assertSellLines($line(10));
});

test('a variation with no limit is free and the sale api enforces the rule for non-draft sales', function () {
    $scope = seedSellScope();
    ProductDetail::query()->findOrFail($scope['variation_id'])->update(['min_sell_price' => 200]);

    $role = Role::query()->create(['name' => 'cashier', 'company_id' => $scope['company_id'], 'is_active' => true]);
    foreach (['/sell', '/sell/add'] as $path) {
        grantMenuPermission($role->id, $path, ltrim($path, '/').uniqid());
    }
    Sanctum::actingAs(createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']]));

    $this->postJson('/api/sells', validSellPayload($scope))->assertUnprocessable()->assertJsonValidationErrors(['selllines.0.unit_price']);
    $this->postJson('/api/sells', validSellPayload($scope, ['status' => 'draft']))->assertSuccessful();

    ProductDetail::query()->findOrFail($scope['variation_id'])->update(['min_sell_price' => null]);

    $this->postJson('/api/sells', validSellPayload($scope))->assertSuccessful();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pcBulk(array $scope, array $overrides = []): array
{
    return array_merge(['company_id' => $scope['company_id'], 'field' => 'default_sell_price', 'mode' => 'percent', 'value' => 10], $overrides);
}

test('bulk update previews and then applies a percentage change, recalculating profit', function () {
    $scope = seedPriceListScope();
    $detail = ProductDetail::query()->findOrFail($scope['variation_id']);

    $preview = $this->postJson('/api/bulk-price-update/preview', pcBulk($scope))->assertSuccessful();

    expect($preview->json('total'))->toBe(1)
        ->and($preview->json('will_change'))->toBe(1)
        ->and($preview->json('data.0.old_price'))->toEqual(100)
        ->and($preview->json('data.0.new_price'))->toEqual(110)
        ->and((float) $detail->fresh()->default_sell_price)->toBe(100.0);

    $this->postJson('/api/bulk-price-update/apply', pcBulk($scope))->assertSuccessful()->assertJsonPath('changed', 1);

    $detail->refresh();

    expect((float) $detail->default_sell_price)->toBe(110.0)
        ->and((float) $detail->profit_percent)->toBe(37.5);
});

test('bulk update supports fixed amounts, set values, rounding and the limit modes', function () {
    $scope = seedPriceListScope();
    $detail = ProductDetail::query()->findOrFail($scope['variation_id']);

    $this->postJson('/api/bulk-price-update/apply', pcBulk($scope, ['mode' => 'fixed', 'value' => -15]))->assertSuccessful();
    expect((float) $detail->fresh()->default_sell_price)->toBe(85.0);

    $this->postJson('/api/bulk-price-update/apply', pcBulk($scope, ['mode' => 'percent', 'value' => 7, 'round_to' => 5]))->assertSuccessful();
    expect((float) $detail->fresh()->default_sell_price)->toBe(90.0);

    $this->postJson('/api/bulk-price-update/apply', pcBulk($scope, ['mode' => 'set', 'value' => 120]))->assertSuccessful();
    expect((float) $detail->fresh()->default_sell_price)->toBe(120.0);

    $skipped = $this->postJson('/api/bulk-price-update/preview', pcBulk($scope, ['field' => 'min_sell_price', 'mode' => 'percent', 'value' => 5]))->assertSuccessful();
    expect($skipped->json('skipped'))->toBe(1)->and($skipped->json('will_change'))->toBe(0);

    $this->postJson('/api/bulk-price-update/apply', pcBulk($scope, ['field' => 'min_sell_price', 'mode' => 'percent_of_sell', 'value' => 80]))->assertSuccessful();
    expect((float) $detail->fresh()->min_sell_price)->toBe(96.0);

    $this->postJson('/api/bulk-price-update/apply', pcBulk($scope, ['mode' => 'fixed', 'value' => -500]))->assertSuccessful();
    expect((float) $detail->fresh()->default_sell_price)->toBe(0.0);

    $this->postJson('/api/bulk-price-update/preview', pcBulk($scope, ['mode' => 'percent_of_sell']))->assertUnprocessable();
    $this->postJson('/api/bulk-price-update/preview', pcBulk($scope, ['field' => 'name']))->assertUnprocessable();
});

test('bulk update moves the purchase price with its unit price and filters by name and company', function () {
    $scope = seedPriceListScope();
    $other = seedPriceListScope2();
    $detail = ProductDetail::query()->findOrFail($scope['variation_id']);

    $this->postJson('/api/bulk-price-update/apply', pcBulk($scope, ['field' => 'default_purchase_price', 'mode' => 'percent', 'value' => 25]))->assertSuccessful();

    $detail->refresh();

    expect((float) $detail->default_purchase_price)->toBe(100.0)
        ->and((float) $detail->dpp_unit_price)->toBe(100.0)
        ->and((float) ProductDetail::query()->findOrFail($other['variation_id'])->default_purchase_price)->toBe(80.0);

    $none = $this->postJson('/api/bulk-price-update/preview', pcBulk($scope, ['search' => 'no such product']))->assertSuccessful();
    expect($none->json('total'))->toBe(0);
});

/**
 * A second company with a priced product, so a bulk update of the first company must leave it alone.
 *
 * @return array<string, mixed>
 */
function seedPriceListScope2(): array
{
    $company = DB::table('companies')->insertGetId(['code' => 'PRL002', 'name' => 'Other Company', 'address' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $unit = Unit::query()->create(['company_id' => $company, 'name' => 'Piece', 'short_name' => 'PC', 'type' => 'large', 'active' => true, 'auto_adjustment' => false]);
    $product = Product::query()->create(['company_id' => $company, 'unit_id' => $unit->id, 'name' => 'Other Product', 'sku' => 'OP-1', 'type' => 'single', 'active' => true]);
    $detail = ProductDetail::query()->create([
        'product_id' => $product->id, 'name' => 'Other dummy', 'sku' => 'OP-1-1', 'variation_name' => 'dummy', 'default_purchase_price' => 80, 'dpp_unit_price' => 80,
        'largequantity' => 1, 'smallquantity' => 1, 'profit_percent' => 25, 'default_sell_price' => 100,
    ]);

    return ['company_id' => $company, 'variation_id' => $detail->id];
}

test('a company user can only bulk update their own company and needs the permission', function () {
    $scope = seedPriceListScope();
    $other = seedPriceListScope2();

    $role = Role::query()->create(['name' => 'catalog', 'company_id' => $other['company_id'], 'is_active' => true]);
    $user = createStaffUserForRole($role, ['company_id' => $other['company_id']]);
    Sanctum::actingAs($user);

    $this->postJson('/api/bulk-price-update/preview', pcBulk($scope))->assertForbidden();

    grantMenuPermission($role->id, '/bulkpriceupdate', 'bulkpriceupdate'.uniqid());

    $preview = $this->postJson('/api/bulk-price-update/preview', pcBulk($scope))->assertSuccessful();

    expect($preview->json('total'))->toBe(1)->and($preview->json('data.0.product_name'))->toBe('Other Product');

    $this->postJson('/api/bulk-price-update/apply', pcBulk($scope))->assertForbidden();
});

test('price changes are in the activity log and the price history report', function () {
    $scope = seedPriceListScope();
    $this->postJson('/api/bulk-price-update/apply', pcBulk($scope))->assertSuccessful();

    $log = AuditLog::query()->where('auditable_type', ProductDetail::class)->where('event', 'updated')->latest('id')->firstOrFail();

    expect((float) $log->old_values['default_sell_price'])->toBe(100.0)->and((float) $log->new_values['default_sell_price'])->toBe(110.0);

    $report = $this->getJson('/api/reports/price-history?'.http_build_query(['company_id' => $scope['company_id'], 'show_record' => 50]))->assertSuccessful()->json();
    $row = collect($report['data']['data'])->firstWhere('field_label', 'Sell price');

    expect($row['old_value'])->toEqual(100)
        ->and($row['new_value'])->toEqual(110)
        ->and($row['change_percent'])->toEqual(10)
        ->and($row['product_name'])->toContain('Canned Tomatoes')
        ->and($report['summary']['increases'])->toBeGreaterThanOrEqual(1);
});

test('the menu migration adds the bulk page, the override row and the report, and grants companyadmin', function () {
    DB::table('permissions')->delete();
    DB::table('menus')->delete();
    $row = fn (array $attributes): int => DB::table('menus')->insertGetId(array_merge([
        'icon' => '', 'menu_color' => '#000', 'sort_order' => 1, 'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 0, 'created_at' => now(), 'updated_at' => now(),
    ], $attributes));

    $products = $row(['parent_id' => null, 'name' => 'Products', 'route_name' => 'pg', 'route_path' => '', 'type' => 2]);
    $row(['parent_id' => $products, 'name' => 'Product', 'route_name' => 'product', 'route_path' => '/product', 'type' => 1]);
    $sell = $row(['parent_id' => null, 'name' => 'Sell', 'route_name' => 'sell', 'route_path' => '/sell', 'type' => 1]);
    $reports = $row(['parent_id' => null, 'name' => 'Reports', 'route_name' => 'rg', 'route_path' => '', 'type' => 2]);
    $row(['parent_id' => $reports, 'name' => 'Stock', 'route_name' => 'report.stock', 'route_path' => '/report/stock', 'type' => 1]);
    $roleId = DB::table('roles')->insertGetId(['name' => 'companyadmin', 'company_id' => null, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    (require database_path('migrations/2026_10_10_100100_add_pricing_cluster_menus.php'))->up();

    expect(DB::table('menus')->whereIn('route_path', ['/bulkpriceupdate', '/bulkpriceupdate/apply', '/sell/price-override', '/report/price-history'])->count())->toBe(4)
        ->and((int) DB::table('menus')->where('route_path', '/sell/price-override')->value('parent_id'))->toBe($sell)
        ->and((int) DB::table('menus')->where('route_path', '/report/price-history')->value('parent_id'))->toBe($reports)
        ->and(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(4);
});
