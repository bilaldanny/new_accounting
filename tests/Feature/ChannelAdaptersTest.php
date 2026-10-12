<?php

use App\Models\Contact;
use App\Models\EcommerceConnection;
use App\Models\EcommerceOrder;
use App\Models\MessagingSetting;
use App\Services\Ecommerce\EcommerceNotConfigured;
use App\Services\Ecommerce\EcommerceSync;
use App\Services\Ecommerce\ShopifyAdapter;
use App\Services\Ecommerce\WooCommerceAdapter;
use App\Services\MessageDispatcher;
use App\Services\Messaging\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Task E: contract tests of the Shopify, WooCommerce, WhatsApp Cloud API and Twilio adapters against recorded response fixtures
 * (tests/Fixtures/channels), written from the providers' published API documentation. They prove the requests and the reading of
 * the answers; they do not prove a live account: see docs/final-closeout.md for the live test checklist.
 */
function chFixture(string $name): array
{
    return json_decode((string) file_get_contents(base_path("tests/Fixtures/channels/{$name}.json")), true, 512, JSON_THROW_ON_ERROR);
}

function chConnection(string $platform, array $credentials, array $extra = []): EcommerceConnection
{
    static $scopeCache = [];
    $key = (string) DB::table('companies')->min('id');
    $scope = $scopeCache[$key] ??= DB::table('companies')->exists()
        ? ['company_id' => (int) DB::table('companies')->min('id'), 'branch_id' => (int) DB::table('branches')->min('id'), 'customer_id' => (int) DB::table('contacts')->min('id')]
        : seedSellScope();

    return EcommerceConnection::query()->create($extra + [
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'platform' => $platform, 'name' => ucfirst($platform).' shop',
        'store_url' => $platform === 'shopify' ? 'demo-shop.myshopify.com' : 'https://shop.example.test', 'credentials' => $credentials,
        'default_contact_id' => $scope['customer_id'], 'is_active' => true,
    ]);
}

test('the Shopify adapter sends the documented request, follows the Link header and reads orders into the common shape', function () {
    Http::fake([
        'demo-shop.myshopify.com/admin/api/*/orders.json?page_info=*' => Http::response(chFixture('shopify_orders_page2')),
        'demo-shop.myshopify.com/admin/api/*/orders.json*' => Http::response(chFixture('shopify_orders_page1'), 200, [
            'Link' => '<https://demo-shop.myshopify.com/admin/api/2024-10/orders.json?page_info=abc123&limit=250>; rel="next"',
        ]),
    ]);

    $connection = chConnection('shopify', ['access_token' => 'shpat_test']);
    $orders = (new ShopifyAdapter)->fetchOrders($connection, null);

    Http::assertSent(fn (HttpRequest $request): bool => $request->hasHeader('X-Shopify-Access-Token', 'shpat_test')
        && str_contains($request->url(), '/admin/api/2024-10/orders.json')
        && str_contains($request->url(), 'status=any') && str_contains($request->url(), 'limit=250'));
    Http::assertSentCount(2);

    expect($orders)->toHaveCount(3)
        ->and($orders[0]['external_id'])->toBe('450789469')
        ->and($orders[0]['customer']['name'])->toBe('Bob Norman')->and($orders[0]['customer']['email'])->toBe('bob.norman@example.com')
        // 100.00 less a 10.00 discount allocated to 2 units: 95.00 each
        ->and($orders[0]['lines'][0])->toMatchArray(['sku' => 'IPOD2008PINK', 'quantity' => 2.0, 'unit_price' => 95.0])
        ->and($orders[0]['cancelled'])->toBeFalse()
        ->and($orders[1]['cancelled'])->toBeTrue()
        ->and($orders[2]['external_id'])->toBe('450789471')->and($orders[2]['shipping'])->toBe(5.0)->and($orders[2]['placed_at'])->toBe('2026-10-03');
});

test('Shopify errors are told apart: a refused token, a rate limit that is retried once, and no credentials at all', function () {
    $connection = chConnection('shopify', ['access_token' => 'bad']);
    Http::fake(['*' => Http::sequence()
        ->push(['errors' => 'Invalid API key or access token'], 401)
        ->push('', 429, ['Retry-After' => '1'])
        ->push(chFixture('shopify_orders_page2'), 200)]);
    expect(fn () => (new ShopifyAdapter)->fetchOrders($connection, null))->toThrow(EcommerceNotConfigured::class, 'refused the access token');

    $orders = (new ShopifyAdapter)->fetchOrders(chConnection('shopify', ['access_token' => 'ok']), null);
    expect($orders)->toHaveCount(1);

    expect(fn () => (new ShopifyAdapter)->fetchOrders(chConnection('shopify', []), null))->toThrow(EcommerceNotConfigured::class);
});

test('the WooCommerce adapter uses basic auth, pages with X-WP-TotalPages and flags cancelled orders', function () {
    Http::fake([
        'shop.example.test/wp-json/wc/v3/orders*page=2*' => Http::response(chFixture('woocommerce_orders_page2'), 200, ['X-WP-TotalPages' => '2']),
        'shop.example.test/wp-json/wc/v3/orders*' => Http::response(chFixture('woocommerce_orders_page1'), 200, ['X-WP-TotalPages' => '2']),
    ]);

    $connection = chConnection('woocommerce', ['consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test']);
    $orders = (new WooCommerceAdapter)->fetchOrders($connection, Carbon::parse('2026-09-30'));

    Http::assertSent(fn (HttpRequest $request): bool => $request->hasHeader('Authorization', 'Basic '.base64_encode('ck_test:cs_test'))
        && str_contains($request->url(), 'per_page=100') && str_contains($request->url(), 'after=2026-09-30'));
    Http::assertSentCount(2);

    expect($orders)->toHaveCount(3)
        // 50.00 for 2 units after the coupon: 25.00 each
        ->and($orders[0]['lines'][0])->toMatchArray(['sku' => 'WOO-SINGLE-1', 'quantity' => 2.0, 'unit_price' => 25.0])
        ->and($orders[0]['shipping'])->toBe(10.0)->and($orders[0]['placed_at'])->toBe('2026-10-01')
        ->and($orders[1]['cancelled'])->toBeTrue()
        ->and($orders[2]['customer']['phone'])->toBe('03001234567');

    // an http:// store is refused (basic auth would travel in the clear)
    $plain = chConnection('woocommerce', ['consumer_key' => 'a', 'consumer_secret' => 'b'], ['store_url' => 'http://shop.example.test']);
    expect(fn () => (new WooCommerceAdapter)->fetchOrders($plain, null))->toThrow(EcommerceNotConfigured::class);
});

test('web orders become draft sales once, a cancelled one never, and an unknown SKU is kept as failed', function () {
    $scope = seedSellScope();
    DB::table('products')->where('id', $scope['product_id'])->update(['sku' => 'IPOD2008PINK']);
    DB::table('product_details')->where('id', $scope['variation_id'])->update(['sku' => 'IPOD2008PINK']);
    Http::fake([
        'demo-shop.myshopify.com/admin/api/*/orders.json?page_info=*' => Http::response(chFixture('shopify_orders_page2')),
        'demo-shop.myshopify.com/admin/api/*/orders.json*' => Http::response(chFixture('shopify_orders_page1'), 200, [
            'Link' => '<https://demo-shop.myshopify.com/admin/api/2024-10/orders.json?page_info=abc123&limit=250>; rel="next"',
        ]),
    ]);

    $connection = EcommerceConnection::query()->create([
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'platform' => 'shopify', 'name' => 'Demo', 'store_url' => 'demo-shop.myshopify.com',
        'credentials' => ['access_token' => 'shpat_test'], 'default_contact_id' => $scope['customer_id'], 'is_active' => true,
    ]);

    $sync = app(EcommerceSync::class);
    $first = $sync->sync($connection);

    // #1001 has one line with a known SKU and one (CASE-BLK) the catalog does not know: failed. #1002 is cancelled. #1003 has CASE-BLK too.
    expect($first['fetched'])->toBe(3)->and($first['skipped'])->toBe(1)->and($first['failed'])->toBe(2)->and($first['imported'])->toBe(1 - 1);
    expect(DB::table('ecommerce_orders')->where('status', 'failed')->count())->toBe(2)
        ->and(DB::table('transactions')->where('type', 'sell')->count())->toBe(0);

    // the catalog gains the SKU; retrying imports them as drafts, and a second sync does not duplicate
    prdProduct(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']], 'Case', ['sku' => 'CASE-BLK'], ['sku' => 'CASE-BLK', 'default_sell_price' => 25]);

    foreach (DB::table('ecommerce_orders')->where('status', 'failed')->get() as $failed) {
        $sync->import($connection, EcommerceOrder::query()->findOrFail($failed->id));
    }

    expect(DB::table('transactions')->where('type', 'sell')->where('status', 'draft')->count())->toBe(2);
    $again = $sync->sync($connection->fresh());
    expect($again['imported'])->toBe(0)->and(DB::table('transactions')->where('type', 'sell')->count())->toBe(2);
    expect(Contact::query()->count())->toBeGreaterThan(0);
});

test('the WhatsApp Cloud API adapter posts the documented body to the phone number id and reads the message id', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(chFixture('whatsapp_cloud_success'))]);
    $scope = seedSellScope();
    MessagingSetting::query()->create([
        'company_id' => $scope['company_id'], 'channel' => 'whatsapp', 'provider' => 'whatsapp_cloud', 'sender' => '1055550001', 'credentials' => 'EAAG-token',
        'is_active' => true, 'options' => ['country_code' => '92'],
    ]);

    $result = app(MessageDispatcher::class)->send($scope['company_id'], 'whatsapp', '0300 1234567', 'Your receipt: https://example.test/r/abc');

    Http::assertSent(function (HttpRequest $request): bool {
        $body = $request->data();

        return $request->url() === 'https://graph.facebook.com/v21.0/1055550001/messages'
            && $request->hasHeader('Authorization', 'Bearer EAAG-token')
            && $body['messaging_product'] === 'whatsapp' && $body['to'] === '923001234567' && $body['type'] === 'text'
            && $body['text']['body'] === 'Your receipt: https://example.test/r/abc';
    });
    expect($result['status'])->toBe('sent')->and($result['provider_id'])->toStartWith('wamid.');
});

test('with an approved template the text goes as its body parameter, and the provider refusal is reported with its code', function () {
    $scope = seedSellScope();
    $setting = MessagingSetting::query()->create([
        'company_id' => $scope['company_id'], 'channel' => 'whatsapp', 'provider' => 'whatsapp_cloud', 'sender' => '1055550001', 'credentials' => 'EAAG-token',
        'is_active' => true, 'options' => ['template' => 'receipt_link', 'language' => 'en_US'],
    ]);
    Http::fake(['graph.facebook.com/*' => Http::response(chFixture('whatsapp_cloud_error'), 400)]);

    $result = app(MessageDispatcher::class)->send($scope['company_id'], 'whatsapp', '+92 300 1234567', 'Receipt INV-1');

    Http::assertSent(fn (HttpRequest $request): bool => $request->data()['type'] === 'template'
        && $request->data()['template']['name'] === 'receipt_link' && $request->data()['template']['language']['code'] === 'en_US'
        && $request->data()['template']['components'][0]['parameters'][0]['text'] === 'Receipt INV-1');
    expect($result['status'])->toBe('failed')->and($result['error'])->toContain('131047')->and($setting->id)->toBeGreaterThan(0);
});

test('the Twilio adapter posts the documented form with basic auth and reads the message sid or the error', function () {
    $scope = seedSellScope();
    MessagingSetting::query()->create([
        'company_id' => $scope['company_id'], 'channel' => 'sms', 'provider' => 'twilio', 'sender' => '+15005550006',
        'credentials' => 'ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa:secret-token', 'is_active' => true,
    ]);
    Http::fake(['api.twilio.com/*' => Http::sequence()->push(chFixture('twilio_success'), 201)->push(chFixture('twilio_error'), 400)]);

    $result = app(MessageDispatcher::class)->send($scope['company_id'], 'sms', '03001234567', 'Your receipt');

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/Messages.json'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('ACaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa:secret-token'))
        && $request->data()['To'] === '+923001234567' && $request->data()['From'] === '+15005550006' && $request->data()['Body'] === 'Your receipt');
    expect($result['status'])->toBe('sent')->and($result['provider_id'])->toStartWith('SM');

    $failed = app(MessageDispatcher::class)->send($scope['company_id'], 'sms', '03001234567', 'x');
    expect($failed['status'])->toBe('failed')->and($failed['error'])->toContain('21211');
});

test('a channel with no provider, an inactive one or incomplete credentials sends nothing and says so', function () {
    Http::fake();
    $scope = seedSellScope();

    expect(app(MessageDispatcher::class)->send($scope['company_id'], 'sms', '03001234567', 'x')['status'])->toBe('not_configured');

    MessagingSetting::query()->create(['company_id' => $scope['company_id'], 'channel' => 'sms', 'provider' => 'twilio', 'sender' => '+1500', 'credentials' => 'no-colon', 'is_active' => true]);
    expect(app(MessageDispatcher::class)->send($scope['company_id'], 'sms', '03001234567', 'x')['status'])->toBe('not_configured');

    Http::assertNothingSent();
    expect(PhoneNumber::digits('0300-1234567'))->toBe('923001234567')->and(PhoneNumber::digits('+44 7700 900123'))->toBe('447700900123')->and(PhoneNumber::digits('12'))->toBeNull();
});
