<?php

use App\Models\Contact;
use App\Models\Product;
use App\Models\ProductDetail;
use App\Models\Role;
use App\Models\SellLine;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;

/**
 * Not part of the normal run: it takes the screenshots shown on the landing page, from a database filled with obviously fictional
 * sample data. Run it on purpose with CAPTURE_LANDING_SCREENSHOTS=1 (and the inline sqlite environment of the other Dusk runs); the
 * pictures land in tests/Browser/screenshots/landing-*.png and are turned into public/assets/landing/*.jpg by hand.
 */
test('capture the screenshots for the landing page', function () {
    if (getenv('CAPTURE_LANDING_SCREENSHOTS') !== '1') {
        $this->markTestSkipped('Only runs on request.');
    }

    $world = $this->world();
    $company = (int) $world['company_id'];
    $branch = (int) $world['branch_id'];

    DB::table('companies')->where('id', $company)->update(['name' => 'Sample Trading Co.']);
    DB::table('settings')->updateOrInsert(['id' => 1], ['name' => 'ACCUNIVO', 'system_logo' => '/storage/photos/1/logo_icon.png', 'created_at' => now(), 'updated_at' => now()]);

    // The real menu tree (proper names, no permission-only rows) and a company admin who has all of it, like a live admin.
    DB::table('permissions')->delete();
    DB::table('menus')->delete();
    $rows = json_decode((string) file_get_contents(base_path('tests/Fixtures/live-menus.json')), true);
    foreach ($rows as $row) {
        DB::table('menus')->insert(array_merge($row, ['route_path' => $row['route_path'] ?? '', 'route_name' => $row['route_name'] ?? '', 'parent_id' => null, 'menu_color' => '#199683', 'icon' => '', 'created_at' => now(), 'updated_at' => now()]));
    }
    foreach ($rows as $row) {
        DB::table('menus')->where('id', $row['id'])->update(['parent_id' => $row['parent_id']]);
    }
    $role = Role::query()->firstOrCreate(['name' => 'companyadmin', 'company_id' => null], ['is_active' => true]);
    foreach (DB::table('menus')->where(fn ($q) => $q->where('route_path', 'not like', '/blogposts%')->where('route_path', 'not like', '/subscription%')->where('route_path', 'not like', '/tenants%')->where('route_path', 'not like', '/coupons%')->where('route_path', 'not like', '/webhooks%')->where('route_path', 'not like', '/company%')->where('route_path', 'not like', '/menu%')->where('name', '!=', 'Website'))->pluck('id') as $menuId) {
        DB::table('permissions')->insert(['role_id' => $role->id, 'menu_id' => $menuId, 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
    $admin = createStaffUserForRole($role, ['company_id' => $company, 'branch_id' => $branch, 'first_name' => 'Amina', 'last_name' => 'Rahman', 'email' => 'amina@sample-trading.test']);

    $customers = [];
    foreach (['Bluebird Stores', 'Maple Grocers', 'Cedar & Co', 'Orchid Traders', 'Lakeview Mart', 'Summit Foods'] as $i => $name) {
        $customers[] = Contact::query()->create([
            'company_id' => $company, 'branch_id' => $branch, 'business_name' => $name, 'first_name' => 'Sample', 'mobile' => '0300555010'.$i, 'address' => 'Sample address',
            'code' => 'CU-0010'.$i, 'ntn_number' => '123456'.$i, 'user_type' => 'customer', 'type' => 'local', 'pay_term' => 15, 'pay_type' => 'day', 'credit_limit' => 500000, 'active' => true,
        ]);
    }

    $products = [];
    foreach ([['Basmati Rice 5 kg', 1450], ['Olive Oil 1 L', 1890], ['Green Tea 100 bags', 640], ['Whole Wheat Flour 10 kg', 1280], ['Sunflower Oil 5 L', 3150], ['Black Pepper 200 g', 520], ['Honey 500 g', 980], ['Dates 1 kg', 760]] as $i => [$name, $price]) {
        $product = Product::query()->create([
            'company_id' => $company, 'unit_id' => $world['unit_id'], 'itemtype_id' => $world['itemtype_id'], 'category_id' => $world['category_id'],
            'name' => $name, 'sku' => 'SMP-0010'.$i, 'type' => 'single', 'active' => true,
        ]);
        $detail = ProductDetail::query()->create([
            'product_id' => $product->id, 'name' => $name.' dummy', 'sku' => 'SMP-0010'.$i.'-1', 'variation_name' => 'dummy', 'default_purchase_price' => round($price * 0.78),
            'dpp_unit_price' => round($price * 0.78), 'largequantity' => 40 + $i * 7, 'smallquantity' => 0, 'profit_percent' => 28, 'default_sell_price' => $price,
        ]);
        $products[] = [$product, $detail, $price];
    }

    $scope = $world;
    foreach (range(1, 26) as $n) {
        [$product, $detail, $price] = $products[$n % count($products)];
        $customer = $customers[$n % count($customers)];
        $quantity = 2 + ($n * 3) % 9;
        $sell = createSellRecord($scope, [
            'contact_id' => $customer->id, 'status' => 'issue', 'invoice_no' => sprintf('INV-%05d', $n), 'transaction_date' => now()->subDays(27 - $n), 'final_amount' => $price * $quantity,
            'payment_status' => $n % 3 === 0 ? 'due' : ($n % 3 === 1 ? 'paid' : 'partial'),
        ]);
        $sell->selllines()->update(['product_id' => $product->id, 'variation_id' => $detail->id, 'quantity' => $quantity, 'unit_price' => $price, 'unit_price_after_discount' => $price, 'subtotal' => $price * $quantity]);
    }

    $invoice = Transaction::query()->where('invoice_no', 'INV-00014')->firstOrFail();
    foreach ([1, 2, 3] as $k) {
        [$product, $detail, $price] = $products[$k];
        SellLine::query()->create([
            'transaction_id' => $invoice->id, 'product_id' => $product->id, 'variation_id' => $detail->id, 'itemtype_id' => $world['itemtype_id'], 'unit_id' => $world['unit_id'],
            'quantity' => 2 + $k, 'unit_price' => $price, 'discount_percent' => 0, 'unit_price_after_discount' => $price, 'subtotal' => $price * (2 + $k), 'packing_qty' => 1,
        ]);
    }

    $shots = [
        'dashboard' => '/dashboard',
        'sales' => '/sell',
        'products' => '/product',
        'customers' => '/customer',
        'accounts' => '/chart-of-account',
        'sellreport' => '/report/sell',
        'stockreport' => '/report/stock',
    ];

    $this->browse(function (Browser $browser) use ($shots, $admin) {
        $this->signIn($browser, $admin)->resize(1440, 900);

        foreach ($shots as $name => $url) {
            $browser->visit($url)->pause(7000)->screenshot('landing-'.$name);
        }
    });

    expect(true)->toBeTrue();
});
