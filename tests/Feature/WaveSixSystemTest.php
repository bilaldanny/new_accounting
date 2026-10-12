<?php

use App\Models\Company;
use App\Models\Contact;
use App\Models\DatabaseBackup;
use App\Models\User;
use App\Services\AppNotifier;
use App\Services\DatabaseBackups;
use App\Services\System\BackupTools;
use App\Services\System\BranchTree;
use App\Services\System\BusinessSettings;
use App\Services\System\DataMigration;
use App\Services\System\DataRetention;
use App\Services\System\DemoCompany;
use App\Services\System\ExchangeRateSync;
use App\Services\System\FranchiseGroups;
use App\Services\System\SystemLogs;
use App\Services\System\WebPush;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * Wave 6: business settings and number format, branch tree, exchange rate sync, notification wording, web push (RFC 8291 / 8292),
 * import / export center, import history with undo, contact migration, backup copy and restore, retention, log viewer, franchise
 * groups, setup wizard, demo company, help center and custom field values of contacts and products.
 */
function w6Scope(): array
{
    $scope = fcoScope();
    DB::table('companies')->where('id', $scope['company_id'])->update(['name' => 'Acme Traders', 'code' => 'ACME'.uniqid()]);

    return $scope;
}

function w6Admin(array $scope, array $paths = []): User
{
    return fcoStaff($scope, array_merge(['/businesssettings', '/businesssettings/manage', '/businesssettings/import', '/setup', '/help', '/franchise', '/customer/:id/view', '/customer/:id/edit', '/product', '/product/:id/edit'], $paths), false);
}

function w6Super(): User
{
    return User::query()->findOrFail(1);
}

function w6Fixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/system/{$name}"));
}

function w6Currencies(int $companyId): array
{
    $pkr = DB::table('currencies')->insertGetId(['currency_name' => 'Rupee', 'code' => 'PKR', 'symbol' => 'Rs', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $usd = DB::table('currencies')->insertGetId(['currency_name' => 'Dollar', 'code' => 'USD', 'symbol' => '$', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $eur = DB::table('currencies')->insertGetId(['currency_name' => 'Euro', 'code' => 'EUR', 'symbol' => 'E', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('currencies')->insert(['currency_name' => 'Pound', 'code' => 'GBP', 'symbol' => 'P', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('company_settings')->where('company_id', $companyId)->update(['currency_id' => $pkr]);

    return ['pkr' => $pkr, 'usd' => $usd, 'eur' => $eur];
}

// ---------------------------------------------------------------------------------------------------------------------
// business settings, number format

test('business settings default to the format the application always used and validate what they save', function () {
    $scope = w6Scope();
    $settings = app(BusinessSettings::class);

    expect($settings->all($scope['company_id'])['number_format'])->toBe(['decimals' => 2, 'thousand' => ',', 'decimal' => '.', 'grouping' => 'western', 'negative' => 'minus'])
        ->and($settings->format(1234567.891, $scope['company_id']))->toBe('1,234,567.89')
        ->and($settings->format(-1234.5, $scope['company_id']))->toBe('-1,234.50')
        ->and($settings->format(0, $scope['company_id'], 0))->toBe('0');

    Sanctum::actingAs(w6Admin($scope));
    $this->putJson('/api/business-settings', ['number_format' => ['decimals' => 0, 'thousand' => ',', 'decimal' => '.', 'grouping' => 'indian', 'negative' => 'brackets']])->assertOk();

    expect($settings->format(1234567.891, $scope['company_id']))->toBe('12,34,568')
        ->and($settings->format(-1234.5, $scope['company_id'], 2))->toBe('(1,234.50)')
        ->and($settings->format(999, $scope['company_id']))->toBe('999');

    $this->putJson('/api/business-settings', ['number_format' => ['decimals' => 9]])->assertUnprocessable();
    $this->putJson('/api/business-settings', ['number_format' => ['thousand' => '.', 'decimal' => '.']])->assertUnprocessable();
    $this->putJson('/api/business-settings', ['pos' => ['cash_drawer' => 'always']])->assertUnprocessable();
    $this->putJson('/api/business-settings', ['pos' => ['cash_drawer_pulse' => '<script>']])->assertUnprocessable();
    $this->putJson('/api/business-settings', ['language' => ['default' => 'fr']])->assertUnprocessable();
    $this->putJson('/api/business-settings', ['pos' => ['cash_drawer' => 'cash_sale', 'require_open_shift' => true], 'language' => ['default' => 'ur'], 'unknown' => ['x' => 1]])->assertOk();

    $shown = $this->getJson('/api/business-settings')->assertOk()->json('data');
    expect($shown['settings']['pos'])->toMatchArray(['cash_drawer' => 'cash_sale', 'require_open_shift' => true])->and($shown['settings']['language']['default'])->toBe('ur')
        ->and($shown['settings']['number_format']['grouping'])->toBe('indian')->and($shown['summary']['company']['name'])->toBe('Acme Traders');
});

test('another company keeps its own format and a user without permission is refused', function () {
    $mine = w6Scope();
    $theirs = fcoOtherCompany('w6a');
    Sanctum::actingAs(w6Admin($mine));
    $this->putJson('/api/business-settings', ['number_format' => ['decimals' => 3]])->assertOk();

    expect(app(BusinessSettings::class)->all($theirs['company_id'])['number_format']['decimals'])->toBe(2);

    Sanctum::actingAs(w6Admin($theirs));
    $this->getJson('/api/business-settings')->assertOk()->assertJsonPath('data.settings.number_format.decimals', 2);

    Sanctum::actingAs(fcoStaff($mine, [], false));
    $this->getJson('/api/business-settings')->assertForbidden();
    Sanctum::actingAs(fcoStaff($mine, ['/businesssettings'], false));
    $this->getJson('/api/business-settings')->assertOk();
    $this->putJson('/api/business-settings', ['number_format' => ['decimals' => 1]])->assertForbidden();
});

// ---------------------------------------------------------------------------------------------------------------------
// branch tree

test('branches can be arranged in a tree without cycles and only inside their company', function () {
    $scope = w6Scope();
    $other = fcoOtherCompany('w6b');
    $region = DB::table('branches')->insertGetId(['code' => 'REG1', 'company_id' => $scope['company_id'], 'name' => 'North Region', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $shop = DB::table('branches')->insertGetId(['code' => 'SHOP1', 'company_id' => $scope['company_id'], 'name' => 'Shop One', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    Sanctum::actingAs(w6Admin($scope));

    $this->putJson("/api/business-settings/branches/{$shop}/parent", ['parent_id' => $region])->assertOk();
    $this->putJson("/api/business-settings/branches/{$region}/parent", ['parent_id' => $scope['branch_id']])->assertOk();

    $tree = app(BranchTree::class);
    $flat = collect($tree->flat($scope['company_id']))->keyBy('id');
    expect($flat[$shop]['depth'])->toBe(2)->and($flat[$region]['depth'])->toBe(1)->and($tree->subtree($scope['company_id'], $scope['branch_id']))->toContain($shop)->toContain($region);

    // a cycle, itself and another company's branch are refused
    $this->putJson("/api/business-settings/branches/{$scope['branch_id']}/parent", ['parent_id' => $shop])->assertUnprocessable();
    $this->putJson("/api/business-settings/branches/{$region}/parent", ['parent_id' => $region])->assertUnprocessable();
    $this->putJson("/api/business-settings/branches/{$shop}/parent", ['parent_id' => $other['branch_id']])->assertUnprocessable();
    $this->putJson("/api/business-settings/branches/{$other['branch_id']}/parent", ['parent_id' => null])->assertNotFound();

    $this->putJson("/api/business-settings/branches/{$shop}/parent", ['parent_id' => null])->assertOk();
    expect(DB::table('branches')->where('id', $shop)->value('parent_id'))->toBeNull();
});

// ---------------------------------------------------------------------------------------------------------------------
// exchange rates

test('the rate sync writes dated rates for known currencies, skips bad ones and leaves manual rates when the feed fails', function () {
    $scope = w6Scope();
    $c = w6Currencies($scope['company_id']);
    $other = fcoOtherCompany('w6c');
    DB::table('currency_rates')->insert(['company_id' => $other['company_id'], 'currency_id' => $c['usd'], 'rate' => 0.5, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('currency_rates')->insert(['company_id' => $scope['company_id'], 'currency_id' => $c['usd'], 'rate' => 0.0040, 'created_at' => now(), 'updated_at' => now()]);
    Sanctum::actingAs(w6Admin($scope));

    $this->putJson('/api/business-settings/rate-feed', ['url' => 'http://insecure.example.test/rates'])->assertUnprocessable();
    $this->putJson('/api/business-settings/rate-feed', ['url' => 'https://rates.example.test/latest?base={base}', 'rates_path' => 'rates', 'token' => 'sekret'])->assertOk();
    expect(DB::table('integration_accounts')->value('credentials'))->not->toContain('sekret')->and(json_encode($this->getJson('/api/business-settings')->json()))->not->toContain('sekret');

    Http::fake(['rates.example.test/*' => Http::sequence()->push(json_decode(w6Fixture('rates_feed.json'), true))->push('down', 503)->push(['data' => []])]);
    $result = $this->postJson('/api/business-settings/rate-feed/sync')->assertOk()->json('data');
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'base=PKR') && $request->hasHeader('Authorization', 'Bearer sekret'));

    expect($result)->toMatchArray(['ok' => true, 'updated' => 2, 'skipped' => 4])
        ->and((float) DB::table('currency_rates')->where('company_id', $scope['company_id'])->where('currency_id', $c['usd'])->value('rate'))->toBe(0.0036)
        ->and((float) DB::table('currency_rates')->where('company_id', $scope['company_id'])->where('currency_id', $c['eur'])->value('rate'))->toBe(0.0033)
        ->and(DB::table('currency_rate_history')->where('company_id', $scope['company_id'])->where('effective_on', now()->toDateString())->count())->toBe(2)
        ->and((float) DB::table('currency_rates')->where('company_id', $other['company_id'])->value('rate'))->toBe(0.5);

    // a failing feed changes nothing and says why
    $failed = $this->postJson('/api/business-settings/rate-feed/sync')->assertUnprocessable()->json('data');
    expect($failed['ok'])->toBeFalse()->and($failed['error'])->toContain('503')->and((float) DB::table('currency_rates')->where('company_id', $scope['company_id'])->where('currency_id', $c['usd'])->value('rate'))->toBe(0.0036);
    $shown = $this->getJson('/api/business-settings')->json('data.rate_feed');
    expect($shown['last_status'])->toContain('failed');

    // no rates under the path, no base currency
    expect(app(ExchangeRateSync::class)->run($scope['company_id'])['error'])->toContain('no rates');
    DB::table('company_settings')->where('company_id', $scope['company_id'])->update(['currency_id' => null]);
    expect(app(ExchangeRateSync::class)->run($scope['company_id'])['error'])->toContain('base currency');
});

// ---------------------------------------------------------------------------------------------------------------------
// notification wording

test('a notification template wraps the application wording for its company only', function () {
    $scope = w6Scope();
    $other = fcoOtherCompany('w6d');
    $mine = fcoStaff($scope, [], false);
    $theirs = fcoStaff($other, [], false);
    Sanctum::actingAs(w6Admin($scope));

    $this->putJson('/api/business-settings/notification-templates/crm_reminder', ['title' => '[{company}] {title}', 'body' => '{body} - open {url}'])->assertOk();
    $this->putJson('/api/business-settings/notification-templates/not_a_kind', ['title' => 'x'])->assertNotFound();

    $notifier = app(AppNotifier::class);
    $notifier->notify($mine, 'crm_reminder', 'Call Ali', 'Due today', '/activities');
    $notifier->notify($theirs, 'crm_reminder', 'Call Ali', 'Due today', '/activities');
    $notifier->notify($mine, 'billing_overdue', 'Invoice overdue', 'Pay up', '/tenant-billing');

    $a = DB::table('app_notifications')->where('user_id', $mine->id)->where('kind', 'crm_reminder')->first();
    $b = DB::table('app_notifications')->where('user_id', $theirs->id)->first();
    $c = DB::table('app_notifications')->where('user_id', $mine->id)->where('kind', 'billing_overdue')->first();

    expect($a->title)->toBe('[Acme Traders] Call Ali')->and($a->body)->toBe('Due today - open /activities')->and($b->title)->toBe('Call Ali')->and($c->title)->toBe('Invoice overdue');

    $this->putJson('/api/business-settings/notification-templates/crm_reminder', ['title' => '<b>X</b> {title}', 'is_active' => false])->assertOk();
    $notifier->notify($mine, 'crm_reminder', 'Again', null);
    expect(DB::table('app_notifications')->where('user_id', $mine->id)->where('title', 'Again')->exists())->toBeTrue();

    $this->deleteJson('/api/business-settings/notification-templates/crm_reminder')->assertOk();
    expect(DB::table('notification_templates')->count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------------------------------
// web push

function w6Pem(string $dB64, string $publicB64): string
{
    $d = WebPush::unb64($dB64);
    $public = WebPush::unb64($publicB64);
    $der = "\x30\x77\x02\x01\x01\x04\x20".$d."\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\xa1\x44\x03\x42\x00".$public;

    return "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";
}

test('web push encryption follows the worked example of RFC 8291', function () {
    // RFC 8291 section 5 / appendix A values
    $plaintext = 'When I grow up, I want to be a watermelon';
    $asPrivate = 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw';
    $asPublic = 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';
    $uaPublic = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
    $auth = 'BTBZMqHH6r4Tts7J_aSIgg';
    $salt = 'DGv6ra1nlYgDCS1FRnbzlw';

    $message = WebPush::encrypt($plaintext, $uaPublic, $auth, WebPush::unb64($salt), w6Pem($asPrivate, $asPublic));
    $body = $message['body'];

    expect($message['server_public'])->toBe($asPublic)
        ->and(WebPush::b64(substr($body, 0, 16)))->toBe($salt)
        ->and(unpack('N', substr($body, 16, 4))[1])->toBe(4096)
        ->and(ord($body[20]))->toBe(65)
        ->and(WebPush::b64(substr($body, 21, 65)))->toBe($asPublic)
        ->and(strlen($body))->toBe(21 + 65 + strlen($plaintext) + 1 + 16);

    // the same body the RFC prints
    expect(WebPush::b64($body))->toBe('DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN');
});

test('web push encrypts for a fresh subscription so only its private key can read it, and signs the VAPID header with ES256', function () {
    $subscriber = WebPush::newKey();
    $details = openssl_pkey_get_details($subscriber);
    $uaPublic = "\x04".str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
    $auth = random_bytes(16);
    $message = WebPush::encrypt('{"title":"Hello"}', WebPush::b64($uaPublic), WebPush::b64($auth));

    // decrypt as the browser would
    $body = $message['body'];
    $salt = substr($body, 0, 16);
    $serverPublic = substr($body, 21, 65);
    $peer = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$serverPublic), 64, "\n")."-----END PUBLIC KEY-----\n");
    $shared = openssl_pkey_derive($peer, $subscriber, 32);
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0".$uaPublic.$serverPublic, $auth);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $cipher = substr($body, 86, -16);
    $tag = substr($body, -16);

    expect(openssl_decrypt($cipher, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag))->toBe("{\"title\":\"Hello\"}\x02")
        ->and(fn () => WebPush::encrypt('x', 'AAAA', WebPush::b64($auth)))->toThrow(RuntimeException::class);

    // VAPID: a three part JWT whose raw signature verifies with the public key
    $keys = WebPush::generateKeys();
    $header = WebPush::vapidAuthorization('https://push.example.test:8443/send/abc', 'mailto:ops@example.test', $keys['public'], $keys['private_pem'], 1_900_000_000);
    preg_match('/^vapid t=([^,]+), k=(.+)$/', $header, $m);
    [$h, $c, $s] = explode('.', $m[1]);
    $claims = json_decode(WebPush::unb64($c), true);
    $raw = WebPush::unb64($s);
    $asn = fn (string $int): string => (ord($int[0]) > 127 ? "\0" : '').ltrim($int, "\0");
    $r = $asn(substr($raw, 0, 32));
    $sPart = $asn(substr($raw, 32));
    $der = "\x30".chr(4 + strlen($r) + strlen($sPart))."\x02".chr(strlen($r)).$r."\x02".chr(strlen($sPart)).$sPart;
    $publicPem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').WebPush::unb64($keys['public'])), 64, "\n")."-----END PUBLIC KEY-----\n";

    expect($m[2])->toBe($keys['public'])->and($claims)->toMatchArray(['aud' => 'https://push.example.test:8443', 'sub' => 'mailto:ops@example.test', 'exp' => 1_900_000_000 + 43200])
        ->and(json_decode(WebPush::unb64($h), true)['alg'])->toBe('ES256')->and(strlen($raw))->toBe(64)
        ->and(openssl_verify($h.'.'.$c, $der, $publicPem, OPENSSL_ALGO_SHA256))->toBe(1);
});

test('push subscriptions are kept per user, sending removes a gone subscription and keys are generated once', function () {
    $scope = w6Scope();
    $user = fcoStaff($scope, [], false);
    $other = fcoStaff($scope, [], false);
    $subscriber = WebPush::newKey();
    $d = openssl_pkey_get_details($subscriber);
    $key = WebPush::b64("\x04".str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT));
    $payload = ['endpoint' => 'https://push.example.test/send/one', 'keys' => ['p256dh' => $key, 'auth' => WebPush::b64(random_bytes(16))]];

    Sanctum::actingAs(w6Super());
    $first = $this->postJson('/api/system/push/keys')->assertOk()->json('data.public_key');
    expect($this->postJson('/api/system/push/keys')->json('data.public_key'))->toBe($first)->and(DB::table('system_settings')->where('key', 'vapid_private')->value('value'))->not->toContain('BEGIN');

    Sanctum::actingAs($user);
    $this->getJson('/api/push/key')->assertOk()->assertJsonPath('data.public_key', $first);
    $this->postJson('/api/push/subscribe', ['endpoint' => 'http://insecure.test/x', 'keys' => $payload['keys']])->assertUnprocessable();
    $this->postJson('/api/push/subscribe', ['endpoint' => $payload['endpoint'], 'keys' => ['p256dh' => 'AAAA', 'auth' => 'BBBB']])->assertUnprocessable();
    $this->postJson('/api/push/subscribe', $payload)->assertCreated();
    $this->postJson('/api/push/subscribe', $payload)->assertCreated();
    expect(DB::table('push_subscriptions')->count())->toBe(1);

    Http::fake(['push.example.test/*' => Http::sequence()->push('', 201)->push('', 410)]);
    $this->postJson('/api/push/test')->assertOk()->assertJsonPath('data.0.status', 201);
    Http::assertSent(fn ($r): bool => $r->hasHeader('Content-Encoding', 'aes128gcm') && str_starts_with((string) $r->header('Authorization')[0], 'vapid t=') && $r->hasHeader('TTL'));
    expect(DB::table('push_subscriptions')->value('last_used_at'))->not->toBeNull();

    $this->postJson('/api/push/test')->assertOk()->assertJsonPath('data.0.removed', true);
    expect(DB::table('push_subscriptions')->count())->toBe(0);

    // a user cannot remove another user's subscription
    $this->postJson('/api/push/subscribe', $payload)->assertCreated();
    Sanctum::actingAs($other);
    $this->postJson('/api/push/unsubscribe', ['endpoint' => $payload['endpoint']])->assertOk();
    expect(DB::table('push_subscriptions')->count())->toBe(1);
});

// ---------------------------------------------------------------------------------------------------------------------
// import / export center, history and undo

test('exports are the company own, templates match the importer and formulas are neutralised', function () {
    $scope = w6Scope();
    $other = fcoOtherCompany('w6e');
    DB::table('contacts')->where('id', $scope['contact_id'])->update(['business_name' => '=cmd|calc']);
    DB::table('contacts')->insert(['company_id' => $other['company_id'], 'branch_id' => $other['branch_id'], 'business_name' => 'Foreign Customer', 'first_name' => 'F', 'mobile' => '1', 'address' => 'x', 'code' => 'CU-F', 'user_type' => 'customer', 'type' => 'local', 'ntn_number' => '1', 'created_at' => now(), 'updated_at' => now()]);
    Sanctum::actingAs(w6Admin($scope));

    $csv = $this->get('/api/data-center/export/customers?format=csv')->assertOk()->streamedContent();
    expect($csv)->toContain("'=cmd|calc")->and($csv)->not->toContain('Foreign Customer')->and($csv)->toStartWith("\xEF\xBB\xBF");

    $template = $this->get('/api/data-center/template/customers?format=csv')->assertOk()->streamedContent();
    expect(trim(ltrim($template, "\xEF\xBB\xBF")))->toBe(implode(',', ['business_name', 'first_name', 'last_name', 'mobile', 'email', 'address', 'ntn_number', 'strn_number', 'code', 'opening_balance', 'credit_limit', 'pay_term', 'pay_type', 'type']));

    $xlsx = $this->get('/api/data-center/export/products?format=xlsx')->assertOk()->streamedContent();
    expect(substr($xlsx, 0, 2))->toBe('PK');
    $this->get('/api/data-center/export/secrets?format=csv')->assertNotFound();
    $this->get('/api/data-center/export/customers?format=pdf')->assertStatus(422);

    Sanctum::actingAs(fcoStaff($scope, ['/businesssettings'], false));
    $this->get('/api/data-center/export/customers?format=csv')->assertForbidden();
});

test('an import is recorded and can be undone while its records are untouched', function () {
    $scope = w6Scope();
    $other = fcoOtherCompany('w6f');
    Sanctum::actingAs(w6Admin($scope, ['/customer', '/customer/add', '/customer/import']));

    $rows = [
        ['business_name' => 'Import One', 'mobile' => '03001110001'],
        ['business_name' => 'Import Two', 'mobile' => '03001110002'],
    ];
    $this->postJson('/api/customers/import', ['rows' => $rows, 'dry_run' => true])->assertOk();
    expect(DB::table('import_runs')->count())->toBe(0);

    $this->postJson('/api/customers/import', ['rows' => $rows, 'file_name' => 'customers.csv'])->assertOk();
    $run = DB::table('import_runs')->first();
    $ids = json_decode($run->created_ids, true);

    expect($run->entity)->toBe('customers')->and($ids)->toHaveCount(2)->and($run->status)->toBe('active')->and($run->file_name)->toBe('customers.csv');
    expect(collect($this->getJson('/api/data-center/history')->json('data'))->pluck('created')->all())->toBe([2]);

    // one of them gets a sale: it is kept, the other goes
    createSellRecord($scope, ['contact_id' => $ids[0], 'invoice_no' => 'IMP-1']);

    Sanctum::actingAs(w6Admin($other));
    $this->postJson("/api/data-center/history/{$run->id}/undo")->assertNotFound();

    Sanctum::actingAs(w6Admin($scope));
    $result = $this->postJson("/api/data-center/history/{$run->id}/undo")->assertOk()->json('data');
    expect($result['undone'])->toBe(1)->and($result['kept'])->toHaveCount(1)->and($result['kept'][0]['id'])->toBe($ids[0])
        ->and(Contact::query()->whereKey($ids[0])->exists())->toBeTrue()->and(Contact::query()->whereKey($ids[1])->exists())->toBeFalse()
        ->and(DB::table('import_runs')->value('status'))->toBe('partly_undone');
    expect($this->postJson("/api/data-center/history/{$run->id}/undo")->json('data.undone'))->toBe(0);
});

test('contacts migrate from a template, a Tally export and a QuickBooks list through the one importer', function () {
    $scope = w6Scope();
    $migration = app(DataMigration::class);

    $tally = $migration->map('tally', $migration->parse(w6Fixture('tally_ledgers.csv')));
    expect($tally['customers'])->toHaveCount(2)->and($tally['suppliers'])->toHaveCount(1)->and($tally['skipped'])->toBe(2)
        ->and($tally['customers'][0])->toMatchArray(['business_name' => 'Al Noor Traders', 'opening_balance' => 15000.0, 'email' => 'alnoor@example.com'])
        ->and($tally['customers'][1]['opening_balance'])->toBe(-2000.0)->and($tally['suppliers'][0]['opening_balance'])->toBe(7500.5);

    $qb = $migration->map('quickbooks', $migration->parse(w6Fixture('quickbooks_customers.csv')), 'customer');
    expect($qb['customers'])->toHaveCount(2)->and($qb['skipped'])->toBe(1)->and($qb['customers'][0])->toMatchArray(['business_name' => 'Hamid Stores', 'first_name' => 'Hamid', 'last_name' => 'Raza', 'mobile' => '03211112222', 'opening_balance' => 4500.0]);

    expect($migration->balance('1,500.00 Dr', false))->toBe(1500.0)->and($migration->balance('1,500.00 Cr', false))->toBe(-1500.0)->and($migration->balance('1,500.00 Dr', true))->toBe(-1500.0)
        ->and($migration->balance('', true))->toBe(0.0);

    Sanctum::actingAs(w6Admin($scope));
    $check = $this->postJson('/api/data-center/migrate', ['source' => 'quickbooks', 'kind' => 'customer', 'csv' => w6Fixture('quickbooks_customers.csv')])->assertOk()->json();
    expect($check['dry_run'])->toBeTrue()->and($check['mapped']['customers'])->toBe(2)->and($check['mapped']['unmatched_columns'])->toContain('Company');
    expect(Contact::query()->where('business_name', 'Hamid Stores')->exists())->toBeFalse();

    $this->postJson('/api/data-center/migrate', ['source' => 'tally', 'csv' => "Name,Parent\n"])->assertUnprocessable();
    $this->postJson('/api/data-center/migrate', ['source' => 'oracle', 'csv' => 'x'])->assertUnprocessable();

    $done = $this->postJson('/api/data-center/migrate', ['source' => 'quickbooks', 'kind' => 'customer', 'csv' => "Customer,Main Phone\nPlain Customer,03000000001\n", 'commit' => true, 'file_name' => 'qb.csv'])->assertOk()->json();
    expect($done['ok'])->toBeTrue()->and(Contact::query()->where('business_name', 'Plain Customer')->exists())->toBeTrue()
        ->and(DB::table('import_runs')->where('source', 'quickbooks')->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------------------------------------------------
// backup copy and restore

class W6FakeBackups extends DatabaseBackups
{
    protected function runDump(string $target): void
    {
        file_put_contents($target, "-- fake dump\nCREATE TABLE t (id int);\n");
    }
}

test('a backup is copied to a configured remote disk and restored only with the typed confirmation after a safety backup', function () {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'w6-backups-'.uniqid();
    config(['database_backup.directory' => $directory, 'filesystems.disks.w6remote' => ['driver' => 'local', 'root' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'w6-remote-'.uniqid()]]);
    app()->bind(DatabaseBackups::class, W6FakeBackups::class);
    Sanctum::actingAs(w6Super());

    $backup = app(DatabaseBackups::class)->create(1);
    $tools = app(BackupTools::class);

    $this->putJson('/api/system/backup-remote', ['disk' => 'local'])->assertUnprocessable();
    $this->putJson('/api/system/backup-remote', ['disk' => 'public'])->assertUnprocessable();
    $this->postJson("/api/system/backups/{$backup->id}/copy")->assertUnprocessable();
    $this->putJson('/api/system/backup-remote', ['disk' => 'w6remote'])->assertOk();
    $this->postJson("/api/system/backups/{$backup->id}/copy")->assertOk();
    expect(Storage::disk('w6remote')->exists('database-backups/'.$backup->file_name))->toBeTrue()->and($tools->remoteDisk())->toBe('w6remote');

    // restore: wrong confirmation, then right, with a runner that reads the decompressed dump
    $this->postJson("/api/system/backups/{$backup->id}/restore", ['confirm' => 'yes'])->assertUnprocessable();
    $ran = [];
    $result = $tools->restore($backup->fresh(), 'RESTORE '.$backup->file_name, 1, function (string $sql) use (&$ran): void {
        $ran[] = file_get_contents($sql);
    });

    expect($result['ok'])->toBeTrue()->and($result['safety_backup'])->not->toBeNull()->and($ran[0])->toContain('CREATE TABLE t')
        ->and(DatabaseBackup::query()->count())->toBe(2);

    // a failing runner reports the safety backup and the error, and leaves no temporary file
    $failed = $tools->restore($backup->fresh(), 'RESTORE '.$backup->file_name, 1, function (): void {
        throw new RuntimeException('syntax error near line 3');
    });
    expect($failed['ok'])->toBeFalse()->and($failed['error'])->toContain('syntax error')->and(glob($directory.'/*.restore.sql'))->toBe([]);

    Sanctum::actingAs(fcoStaff(w6Scope(), ['/backup/create'], false));
    $this->postJson("/api/system/backups/{$backup->id}/restore", ['confirm' => 'RESTORE '.$backup->file_name])->assertForbidden();
    $this->putJson('/api/system/backup-remote', ['disk' => 'w6remote'])->assertForbidden();
});

// ---------------------------------------------------------------------------------------------------------------------
// retention and log viewer

test('retention removes only old log rows of its own company, archives them first and never goes below 30 days', function () {
    Storage::fake('local');
    $a = w6Scope();
    $b = fcoOtherCompany('w6g');
    $old = now()->subDays(400)->toDateTimeString();
    $recent = now()->subDays(10)->toDateTimeString();

    foreach ([$a['company_id'], $b['company_id']] as $company) {
        DB::table('audit_logs')->insert([['company_id' => $company, 'event' => 'old', 'auditable_type' => 'x', 'auditable_id' => 1, 'created_at' => $old], ['company_id' => $company, 'event' => 'recent', 'auditable_type' => 'x', 'auditable_id' => 1, 'created_at' => $recent]]);
    }

    $transaction = createSellRecord($a, ['invoice_no' => 'KEEP-1', 'transaction_date' => now()->subDays(800)]);
    Sanctum::actingAs(w6Admin($a));

    $this->putJson('/api/business-settings/retention', ['entity' => 'audit_logs', 'keep_days' => 10])->assertUnprocessable();
    $this->putJson('/api/business-settings/retention', ['entity' => 'transactions', 'keep_days' => 90])->assertUnprocessable();
    $this->putJson('/api/business-settings/retention', ['entity' => 'audit_logs', 'keep_days' => 90, 'archive' => true])->assertOk();

    $removed = app(DataRetention::class)->run();
    expect($removed)->toBe(['audit_logs' => 1])
        ->and(DB::table('audit_logs')->where('company_id', $a['company_id'])->whereIn('event', ['old', 'recent'])->pluck('event')->all())->toBe(['recent'])
        ->and(DB::table('audit_logs')->where('company_id', $b['company_id'])->whereIn('event', ['old', 'recent'])->count())->toBe(2)
        ->and(DB::table('transactions')->where('id', $transaction->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->allFiles('archives'))->toHaveCount(1);

    // a platform rule covers the company that has none
    Sanctum::actingAs(w6Super());
    $this->putJson('/api/system/retention', ['entity' => 'audit_logs', 'keep_days' => 60, 'archive' => false])->assertOk();
    expect(app(DataRetention::class)->run()['audit_logs'])->toBe(1)->and(DB::table('audit_logs')->where('company_id', $b['company_id'])->whereIn('event', ['old', 'recent'])->pluck('event')->all())->toBe(['recent']);
    expect(DB::table('document_reminder_log')->count())->toBe(0);
});

test('the log viewer lists entries newest first, filters, masks secrets and is for the superadmin only', function () {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'w6-logs-'.uniqid();
    mkdir($directory);
    file_put_contents($directory.'/laravel.log', implode("\n", [
        '[2026-10-12 08:00:00] production.INFO: Nightly job done',
        '[2026-10-12 08:05:00] production.ERROR: Payment failed {"password":"hunter2","token":"abc123"} Authorization: Bearer sk_live_ABCDEF',
        '#0 /app/vendor/x.php(10): thing()',
        '#1 /app/vendor/y.php(20): other()',
        '[2026-10-12 08:10:00] production.WARNING: Slow query',
        '',
    ]));
    $logs = new SystemLogs;

    $all = $logs->read(null, null, 100, $directory);
    expect($all['file'])->toBe('laravel.log')->and(array_column($all['entries'], 'level'))->toBe(['warning', 'error', 'info']);

    $error = $logs->read('error', null, 100, $directory)['entries'][0];
    expect($error['message'])->toContain('Payment failed')->and($error['message'])->not->toContain('hunter2')->and($error['message'])->not->toContain('abc123')->and($error['message'])->not->toContain('sk_live_ABCDEF')
        ->and($error['trace'])->toContain('#0 /app/vendor/x.php')->and($logs->read(null, 'Slow', 100, $directory)['entries'])->toHaveCount(1)
        ->and($logs->read(null, null, 100, sys_get_temp_dir().DIRECTORY_SEPARATOR.'nope-'.uniqid())['entries'])->toBe([]);

    Sanctum::actingAs(w6Admin(w6Scope()));
    $this->getJson('/api/system/logs')->assertForbidden();
    Sanctum::actingAs(w6Super());
    $this->getJson('/api/system/logs?level=error&limit=5')->assertOk()->assertJsonStructure(['data' => ['file', 'size', 'entries'], 'levels']);
    $this->getJson('/api/system/logs?level=loud')->assertUnprocessable();
});

// ---------------------------------------------------------------------------------------------------------------------
// franchise groups

test('a franchise owner sees only the member companies, totals appear only in one currency, and groups are checked', function () {
    $a = w6Scope();
    $b = fcoOtherCompany('w6h');
    $c = fcoOtherCompany('w6i');
    $d = w6Currencies($a['company_id']);
    DB::table('company_settings')->updateOrInsert(['company_id' => $b['company_id']], ['company_id' => $b['company_id'], 'currency_id' => $d['pkr'], 'created_at' => now(), 'updated_at' => now()]);
    DB::table('company_settings')->updateOrInsert(['company_id' => $c['company_id']], ['company_id' => $c['company_id'], 'currency_id' => $d['pkr'], 'created_at' => now(), 'updated_at' => now()]);
    $owner = w6Admin($a);
    $bUser = fcoStaff($b, ['/franchise'], false);
    $cUser = fcoStaff($c, ['/franchise'], false);
    createSellRecord($a, ['invoice_no' => 'F-A', 'final_amount' => 1000, 'total_before_tax' => 1000, 'transaction_date' => now()]);
    DB::table('transactions')->insert(['company_id' => $b['company_id'], 'branch_id' => $b['branch_id'], 'type' => 'sell', 'status' => 'final', 'invoice_no' => 'F-B', 'payment_status' => 'due', 'transaction_date' => now(), 'final_amount' => 500, 'total_before_tax' => 500, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('transactions')->insert(['company_id' => $c['company_id'], 'branch_id' => $c['branch_id'], 'type' => 'sell', 'status' => 'final', 'invoice_no' => 'F-C', 'payment_status' => 'due', 'transaction_date' => now(), 'final_amount' => 9999, 'total_before_tax' => 9999, 'created_at' => now(), 'updated_at' => now()]);

    Sanctum::actingAs(w6Super());
    $this->postJson('/api/system/franchise-groups', ['name' => 'No members', 'owner_user_id' => $owner->id, 'company_ids' => []])->assertUnprocessable();
    $this->postJson('/api/system/franchise-groups', ['name' => 'Wrong owner', 'owner_user_id' => $cUser->id, 'company_ids' => [$a['company_id'], $b['company_id']]])->assertUnprocessable();
    $groupId = $this->postJson('/api/system/franchise-groups', ['name' => 'Acme Group', 'owner_user_id' => $owner->id, 'company_ids' => [$a['company_id'], $b['company_id']]])->assertCreated()->json('id');
    $this->postJson('/api/system/franchise-groups', ['name' => 'Rival', 'owner_user_id' => $cUser->id, 'company_ids' => [$b['company_id'], $c['company_id']]])->assertUnprocessable();

    Sanctum::actingAs($owner);
    $overview = $this->getJson('/api/franchise/overview')->assertOk()->json('data');
    expect($overview)->toHaveCount(1)->and(collect($overview[0]['companies'])->pluck('company_id')->all())->toContain($a['company_id'])->toContain($b['company_id'])->not->toContain($c['company_id'])
        ->and($overview[0]['totals']['net_sales'])->toEqual(1500)->and(json_encode($overview))->not->toContain('9999');

    // a user of a member company who is not the owner, and a user of an outside company, see nothing
    Sanctum::actingAs($bUser);
    $this->getJson('/api/franchise/overview')->assertOk()->assertJsonPath('data', []);
    Sanctum::actingAs($cUser);
    $this->getJson('/api/franchise/overview')->assertOk()->assertJsonPath('data', []);

    // different currencies: no total
    DB::table('company_settings')->where('company_id', $b['company_id'])->update(['currency_id' => $d['usd']]);
    expect(app(FranchiseGroups::class)->overviewFor($owner->id)[0]['totals'])->toBeNull();

    Sanctum::actingAs(w6Super());
    $this->deleteJson("/api/system/franchise-groups/{$groupId}")->assertOk();
    expect(DB::table('company_group_members')->count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------------------------------
// setup wizard, demo company, help center

test('the setup wizard follows what exists, remembers skips and keeps the settings it shares a row with', function () {
    $scope = w6Scope();
    $other = fcoOtherCompany('w6j');
    Sanctum::actingAs(w6Admin($scope));

    $steps = collect($this->getJson('/api/setup-wizard')->assertOk()->json('data.steps'))->keyBy('key');
    expect($steps['accounts']['done'])->toBeTrue()->and($steps['first_sale']['done'])->toBeFalse()->and($steps['number_format']['done'])->toBeFalse()->and($steps['first_sale']['link'])->toBe('/sell/add');

    $this->putJson('/api/business-settings', ['number_format' => ['decimals' => 3]])->assertOk();
    $this->putJson('/api/setup-wizard/first_sale', ['skip' => true])->assertOk();
    $this->putJson('/api/setup-wizard/not_a_step', ['skip' => true])->assertNotFound();

    $after = collect($this->getJson('/api/setup-wizard')->json('data.steps'))->keyBy('key');
    expect($after['number_format']['done'])->toBeTrue()->and($after['first_sale']['skipped'])->toBeTrue()->and(app(BusinessSettings::class)->all($scope['company_id'])['number_format']['decimals'])->toBe(3);

    // saving settings again keeps the skip
    $this->putJson('/api/business-settings', ['number_format' => ['decimals' => 1]])->assertOk();
    expect(collect($this->getJson('/api/setup-wizard')->json('data.steps'))->firstWhere('key', 'first_sale')['skipped'])->toBeTrue();

    Sanctum::actingAs(w6Admin($other));
    expect(collect($this->getJson('/api/setup-wizard')->json('data.steps'))->firstWhere('key', 'first_sale')['skipped'])->toBeFalse();
});

test('a demo company is separate, shows its password once and only a demo company can be removed', function () {
    $real = w6Scope();
    $realContacts = Contact::query()->withoutGlobalScopes()->where('company_id', $real['company_id'])->count();
    Sanctum::actingAs(w6Super());

    $created = $this->postJson('/api/system/demo-companies', ['name' => 'Training Co'])->assertCreated()->json('data');
    expect($created['code'])->toStartWith('DEMO-')->and($created['email'])->toEndWith('@demo.invalid')->and(strlen($created['password']))->toBeGreaterThan(12);
    $listed = json_encode($this->getJson('/api/system/demo-companies')->json());
    expect($listed)->not->toContain($created['password']);

    $id = $created['company_id'];
    expect($id)->not->toBe($real['company_id'])
        ->and(Contact::query()->withoutGlobalScopes()->where('company_id', $id)->where('user_type', 'customer')->count())->toBe(3)
        ->and(Contact::query()->withoutGlobalScopes()->where('company_id', $id)->where('user_type', 'supplier')->count())->toBe(2)
        ->and(DB::table('products')->where('company_id', $id)->count())->toBe(3)
        ->and(DB::table('transactions')->where('company_id', $id)->count())->toBe(0)
        ->and(Contact::query()->withoutGlobalScopes()->where('company_id', $real['company_id'])->count())->toBe($realContacts);

    $this->deleteJson("/api/system/demo-companies/{$real['company_id']}")->assertUnprocessable();
    expect(Company::query()->find($real['company_id'])->is_active)->toBeTruthy();

    $this->deleteJson("/api/system/demo-companies/{$id}")->assertOk();
    expect(Contact::query()->withoutGlobalScopes()->where('company_id', $id)->count())->toBe(0)->and(Contact::query()->withoutGlobalScopes()->where('company_id', $real['company_id'])->count())->toBe($realContacts);
    expect(app(DemoCompany::class)->remove(999999))->toBeFalse();

    Sanctum::actingAs(w6Admin($real));
    $this->postJson('/api/system/demo-companies')->assertForbidden();
});

test('the help center shows published articles to every signed-in user and only the superadmin writes them', function () {
    $scope = w6Scope();
    Sanctum::actingAs(w6Super());
    $this->postJson('/api/system/help-articles', ['title' => 'How to <b>post</b> a sale', 'category' => 'Sales', 'body' => "Step one.\n<script>alert(1)</script>Step two.", 'is_published' => true])->assertCreated();
    $this->postJson('/api/system/help-articles', ['title' => 'Draft guide', 'body' => 'Not ready', 'is_published' => false])->assertCreated();
    $this->postJson('/api/system/help-articles', ['title' => 'x', 'body' => 'y'])->assertUnprocessable();

    Sanctum::actingAs(w6Admin($scope));
    $list = $this->getJson('/api/help-articles')->assertOk()->json('data');
    expect($list)->toHaveCount(1)->and($list[0]['title'])->toBe('How to post a sale')->and($list[0]['slug'])->toBe('how-to-post-a-sale');

    $article = $this->getJson('/api/help-articles/how-to-post-a-sale')->assertOk()->json('data');
    expect($article['body'])->not->toContain('<script>')->and($article['body'])->toContain('Step two.');
    $this->getJson('/api/help-articles/draft-guide')->assertNotFound();
    expect($this->getJson('/api/help-articles?q=sale')->json('data'))->toHaveCount(1)->and($this->getJson('/api/help-articles?q=nothing-here')->json('data'))->toBe([]);

    $this->postJson('/api/system/help-articles', ['title' => 'Mine', 'body' => 'Text here'])->assertForbidden();
    Sanctum::actingAs(fcoStaff($scope, [], false));
    $this->getJson('/api/help-articles')->assertForbidden();
});

// ---------------------------------------------------------------------------------------------------------------------
// custom field values of contacts and products

test('custom field values of a contact or product need that record page permission and stay inside the company', function () {
    $scope = w6Scope();
    $other = fcoOtherCompany('w6k');
    $foreign = DB::table('contacts')->insertGetId(['company_id' => $other['company_id'], 'branch_id' => $other['branch_id'], 'business_name' => 'Foreign', 'first_name' => 'F', 'mobile' => '1', 'address' => 'x', 'code' => 'CU-FF', 'user_type' => 'customer', 'type' => 'local', 'ntn_number' => '1', 'created_at' => now(), 'updated_at' => now()]);
    Sanctum::actingAs(w6Admin($scope, ['/documentcenter', '/documentcenter/manage']));

    $this->postJson('/api/custom-fields', ['entity' => 'contact', 'label' => 'Region', 'type' => 'select', 'options' => ['North', 'South']])->assertCreated();
    $this->postJson('/api/custom-fields', ['entity' => 'product', 'label' => 'Shelf life', 'type' => 'number'])->assertCreated();

    $this->putJson("/api/custom-values/contact/{$scope['contact_id']}", ['values' => ['region' => 'East']])->assertUnprocessable();
    $this->putJson("/api/custom-values/contact/{$scope['contact_id']}", ['values' => ['region' => 'North']])->assertOk();
    expect(collect($this->getJson("/api/custom-values/contact/{$scope['contact_id']}")->json('data'))->firstWhere('field_key', 'region')['value'])->toBe('North');

    $this->putJson("/api/custom-values/product/{$scope['product_id']}", ['values' => ['shelf_life' => 'soon']])->assertUnprocessable();
    $this->putJson("/api/custom-values/product/{$scope['product_id']}", ['values' => ['shelf_life' => '90']])->assertOk();

    $this->getJson("/api/custom-values/contact/{$foreign}")->assertNotFound();
    $this->putJson("/api/custom-values/contact/{$foreign}", ['values' => ['region' => 'North']])->assertNotFound();
    $this->getJson('/api/custom-values/user/1')->assertNotFound();

    Sanctum::actingAs(fcoStaff($scope, ['/customer/:id/view'], false));
    $this->getJson("/api/custom-values/contact/{$scope['contact_id']}")->assertOk();
    $this->putJson("/api/custom-values/contact/{$scope['contact_id']}", ['values' => ['region' => 'South']])->assertForbidden();
    $this->getJson("/api/custom-values/product/{$scope['product_id']}")->assertForbidden();
    expect(Cache::get('x'))->toBeNull()->and(Carbon::now()->year)->toBeGreaterThan(2025);
});
