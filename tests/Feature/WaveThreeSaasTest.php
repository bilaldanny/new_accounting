<?php

use App\Models\Company;
use App\Models\Role;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Integrations\HttpJsonBankFeed;
use App\Services\Integrations\LeopardsAdapter;
use App\Services\Integrations\PostExAdapter;
use App\Services\Integrations\StripeGateway;
use App\Services\Saas\PlanFeatures;
use App\Services\Saas\TenantLifecycle;
use App\Services\Saas\TenantServices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Wave 3: SaaS billing (plan features and settings, usage trend, export and deletion, onboarding, support tickets, custom domain,
 * partners, add-ons, cancellation, retry reminders and suspension) and integrations (OpenAPI / Postman, couriers, payment gateway,
 * bank feed against recorded fixtures).
 */
function w3Fixture(string $name): array
{
    return json_decode((string) file_get_contents(base_path("tests/Fixtures/integrations/{$name}.json")), true, 512, JSON_THROW_ON_ERROR);
}

function w3Tenant(array $planExtra = [], array $companyExtra = [], bool $fresh = false): array
{
    $scope = $fresh ? fcoOtherCompany(uniqid()) : fcoScope();
    $jsonColumns = array_intersect_key($planExtra, ['features' => 1, 'settings' => 1]);
    $plan = SubscriptionPlan::query()->create(array_diff_key($planExtra, $jsonColumns) + ['name' => 'Starter', 'code' => 'ST'.uniqid(), 'billing_cycle' => 'monthly', 'price' => 1000, 'trial_days' => 0, 'is_active' => true]);
    if ($jsonColumns !== []) {
        DB::table('subscription_plans')->where('id', $plan->id)->update($jsonColumns);
    }

    DB::table('companies')->where('id', $scope['company_id'])->update($companyExtra + ['subscription_plan_id' => $plan->id, 'tenant_status' => 'active', 'is_active' => 1, 'code' => 'W3'.uniqid()]);
    $role = Role::query()->firstOrCreate(['name' => 'companyadmin', 'company_id' => null], ['is_active' => true]);
    foreach (['/tenant-billing', '/tenant-billing/manage', '/integrations', '/integrations/manage', '/dispatch', '/bankreconciliation', '/leads', '/project', '/apikeys'] as $path) {
        grantMenuPermission($role->id, $path);
    }

    $admin = createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'email_verified_at' => now()]);

    return $scope + ['plan' => $plan, 'admin' => $admin];
}

function w3SuperAdmin(): User
{
    return User::query()->findOrFail(1);
}

function w3Invoice(array $tenant, array $extra = []): SubscriptionInvoice
{
    return SubscriptionInvoice::query()->create($extra + [
        'company_id' => $tenant['company_id'], 'subscription_plan_id' => $tenant['plan']->id, 'invoice_no' => 'SI-'.uniqid(), 'period_start' => now()->subDays(40)->toDateString(),
        'period_end' => now()->subDays(10)->toDateString(), 'amount' => 1000, 'discount_amount' => 0, 'total_amount' => 1000, 'status' => 'overdue', 'due_date' => now()->subDays(10)->toDateString(),
    ]);
}

// ---------------------------------------------------------------------------------------------------------------------
// plan features and settings

test('a plan with a feature list switches the other features off for its tenants, and a plan with none includes everything', function () {
    $tenant = w3Tenant(['features' => json_encode(['crm']), 'settings' => json_encode(['max_exports' => 3, 'nested' => ['x' => 1]])]);
    Sanctum::actingAs($tenant['admin']);

    $this->getJson('/api/projects')->assertForbidden()->assertJsonPath('plan_feature_off', 'projects');
    $this->getJson('/api/leads')->assertOk();
    expect(PlanFeatures::setting((int) $tenant['company_id'], 'max_exports'))->toBe(3)->and(PlanFeatures::setting((int) $tenant['company_id'], 'nested.x'))->toBe(1)
        ->and(PlanFeatures::setting((int) $tenant['company_id'], 'missing', 'fallback'))->toBe('fallback');

    DB::table('subscription_plans')->where('id', $tenant['plan']->id)->update(['features' => null]);
    $this->getJson('/api/projects')->assertOk();

    // the superadmin sets the list from the platform page
    Sanctum::actingAs(w3SuperAdmin());
    $this->putJson("/api/saas/plans/{$tenant['plan']->id}", ['features' => ['crm', 'projects'], 'settings' => ['seats' => 5]])->assertOk();
    $this->putJson("/api/saas/plans/{$tenant['plan']->id}", ['features' => ['not-a-feature']])->assertUnprocessable();
    Sanctum::actingAs($tenant['admin']);
    $this->getJson('/api/projects')->assertOk();
    $this->putJson("/api/saas/plans/{$tenant['plan']->id}", ['features' => null])->assertForbidden();
});

// ---------------------------------------------------------------------------------------------------------------------
// usage, billing run, suspension

test('usage is snapshotted once a day and the trend shows the change', function () {
    $tenant = w3Tenant();
    $lifecycle = app(TenantLifecycle::class);
    $company = Company::query()->findOrFail($tenant['company_id']);

    $lifecycle->snapshot($company, now()->subDays(5)->toDateString());
    createStaffUserForRole(Role::query()->create(['name' => 'w3u', 'company_id' => $tenant['company_id'], 'is_active' => true]), ['company_id' => $tenant['company_id'], 'branch_id' => $tenant['branch_id'], 'email_verified_at' => now()]);
    $lifecycle->snapshot($company);
    $lifecycle->snapshot($company);

    $trend = $lifecycle->trend((int) $tenant['company_id'], 30);
    expect($trend['points'])->toHaveCount(2)->and($trend['change']['users'])->toBe(1);

    Sanctum::actingAs(w3SuperAdmin());
    $this->getJson("/api/saas/usage/{$tenant['company_id']}")->assertOk()->assertJsonCount(2, 'data.points');
});

test('overdue invoices get reminders once, an account unpaid past the grace days is suspended and paying reactivates it', function () {
    $tenant = w3Tenant();
    $invoice = w3Invoice($tenant, ['due_date' => now()->subDays(3)->toDateString()]);
    $lifecycle = app(TenantLifecycle::class);

    $first = $lifecycle->runBilling((int) $tenant['company_id']);
    $again = $lifecycle->runBilling((int) $tenant['company_id']);
    expect($first['reminders'])->toBe(1)->and($again['reminders'])->toBe(0)->and($first['suspended'])->toBe(0)
        ->and(DB::table('tenant_billing_notices')->pluck('kind')->all())->toBe(['overdue_1'])
        ->and(DB::table('app_notifications')->where('user_id', $tenant['admin']->id)->where('kind', 'billing_overdue')->count())->toBe(1);

    $invoice->forceFill(['due_date' => now()->subDays(12)->toDateString()])->save();
    $late = $lifecycle->runBilling((int) $tenant['company_id']);
    expect($late['suspended'])->toBe(1)->and(Company::query()->find($tenant['company_id'])->tenant_status)->toBe('suspended');

    // a suspended company is shut out, apart from its billing page, and nothing else changes for others
    Sanctum::actingAs($tenant['admin']);
    $this->getJson('/api/leads')->assertStatus(402)->assertJsonPath('tenant_status', 'suspended');
    $this->getJson('/api/tenant-billing/overview')->assertOk();

    $invoice->fresh()->markPaid('bank', 'TRX1', 1000, null, null);
    expect(Company::query()->find($tenant['company_id'])->tenant_status)->toBe('active');
    $this->getJson('/api/leads')->assertOk();
});

test('a subscription set to end at the period end is cancelled when the period ends, and can be renewed before', function () {
    $tenant = w3Tenant([], ['current_period_ends_at' => now()->addDays(5)->toDateString()]);
    Sanctum::actingAs($tenant['admin']);

    $this->postJson('/api/tenant-billing/cancel')->assertOk();
    expect(app(TenantLifecycle::class)->runBilling((int) $tenant['company_id'])['cancelled'])->toBe(0);

    $this->postJson('/api/tenant-billing/renew')->assertOk();
    $this->postJson('/api/tenant-billing/cancel')->assertOk();
    DB::table('companies')->where('id', $tenant['company_id'])->update(['current_period_ends_at' => now()->subDay()->toDateString()]);

    expect(app(TenantLifecycle::class)->runBilling((int) $tenant['company_id'])['cancelled'])->toBe(1)
        ->and(Company::query()->find($tenant['company_id'])->tenant_status)->toBe('cancelled');
});

// ---------------------------------------------------------------------------------------------------------------------
// add-ons and partners

test('active add-ons are billed with the plan and kept by a coupon, an ended one is not, and a company without any pays the plan price', function () {
    $tenant = w3Tenant();
    $company = Company::query()->findOrFail($tenant['company_id']);
    $addon = DB::table('addons')->insertGetId(['name' => 'Extra user', 'code' => 'USER', 'price' => 150, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    $plain = SubscriptionInvoice::generateForCompany($company);
    expect((float) $plain->total_amount)->toBe(1000.0)->and((float) $plain->addons_amount)->toBe(0.0);

    Sanctum::actingAs(w3SuperAdmin());
    $this->postJson("/api/saas/companies/{$tenant['company_id']}/addons", ['addon_id' => $addon, 'quantity' => 2, 'starts_on' => now()->subMonth()->toDateString()])->assertCreated();
    DB::table('companies')->where('id', $tenant['company_id'])->update(['current_period_starts_at' => now()->addDays(31)->toDateString(), 'current_period_ends_at' => now()->addDays(61)->toDateString()]);
    $withAddon = SubscriptionInvoice::generateForCompany($company->fresh());
    expect((float) $withAddon->addons_amount)->toBe(300.0)->and((float) $withAddon->total_amount)->toBe(1300.0);

    $this->postJson("/api/saas/companies/{$tenant['company_id']}/addons", ['addon_id' => 999999])->assertUnprocessable();
});

test('a partner earns its commission on the invoices its companies paid in the period', function () {
    $tenant = w3Tenant();
    Sanctum::actingAs(w3SuperAdmin());
    $partner = $this->postJson('/api/saas/partners', ['name' => 'Reseller Co', 'commission_percent' => 10])->assertCreated()->json('id');
    $this->putJson("/api/saas/companies/{$tenant['company_id']}/partner", ['partner_id' => $partner])->assertOk();
    $invoice = w3Invoice($tenant, ['status' => 'unpaid']);
    $invoice->markPaid('bank', 'T', 1000, null, null);
    w3Invoice($tenant, ['status' => 'overdue']);

    $statement = $this->getJson("/api/saas/partners/{$partner}/statement?from=".now()->subDay()->toDateString().'&to='.now()->addDay()->toDateString())->assertOk()->json('data');
    expect($statement['rows'])->toHaveCount(1)->and($statement['commission'])->toEqual(100)->and($statement['base'])->toEqual(1000);
    $this->putJson("/api/saas/companies/{$tenant['company_id']}/partner", ['partner_id' => 99999])->assertUnprocessable();
});

// ---------------------------------------------------------------------------------------------------------------------
// export, deletion, onboarding, tickets, domain

test('a tenant exports its own data as a zip and cannot download another tenant export', function () {
    $tenant = w3Tenant();
    $other = fcoOtherCompany();
    DB::table('contacts')->where('company_id', $tenant['company_id'])->update(['business_name' => 'Mine Co']);
    DB::table('contacts')->insert(['company_id' => $other['company_id'], 'branch_id' => $other['branch_id'], 'business_name' => 'Foreign Co', 'first_name' => 'F', 'mobile' => '1', 'address' => 'x', 'code' => 'CU-X', 'user_type' => 'customer', 'type' => 'local', 'ntn_number' => '1', 'created_at' => now(), 'updated_at' => now()]);
    Sanctum::actingAs($tenant['admin']);

    $export = $this->postJson('/api/tenant-billing/exports')->assertCreated()->json('data');
    expect($export['tables']['contacts'])->toBeGreaterThan(0);

    $zip = new ZipArchive;
    $path = Storage::disk('local')->path('tenant-exports/'.DB::table('tenant_data_exports')->value('path'));
    expect($zip->open($path))->toBeTrue();
    $contacts = (string) $zip->getFromName('contacts.csv');
    expect($contacts)->toContain('Mine Co')->and($contacts)->not->toContain('Foreign Co');

    $this->get("/api/tenant-billing/exports/{$export['id']}/download")->assertOk();
    Sanctum::actingAs(w3Tenant([], [], true)['admin']);
    $this->get("/api/tenant-billing/exports/{$export['id']}/download")->assertNotFound();
});

test('deletion needs a cancelled subscription, a typed confirmation and the grace period, and removes only that company', function () {
    $tenant = w3Tenant();
    $other = fcoOtherCompany();
    DB::table('companies')->where('id', $other['company_id'])->update(['code' => 'OTHERCO']);
    DB::table('contacts')->insert(['company_id' => $other['company_id'], 'branch_id' => $other['branch_id'], 'business_name' => 'Keep Me', 'first_name' => 'K', 'mobile' => '1', 'address' => 'x', 'code' => 'CU-K', 'user_type' => 'customer', 'type' => 'local', 'ntn_number' => '1', 'created_at' => now(), 'updated_at' => now()]);
    Sanctum::actingAs($tenant['admin']);

    $this->postJson('/api/tenant-billing/deletion/request')->assertUnprocessable();
    DB::table('companies')->where('id', $tenant['company_id'])->update(['tenant_status' => 'cancelled']);
    $this->postJson('/api/tenant-billing/deletion/request')->assertCreated();
    $this->postJson('/api/tenant-billing/deletion/confirm', ['code' => 'wrong'])->assertUnprocessable();
    $code = (string) DB::table('companies')->where('id', $tenant['company_id'])->value('code');
    $this->postJson('/api/tenant-billing/deletion/confirm', ['code' => $code])->assertOk();

    $lifecycle = app(TenantLifecycle::class);
    expect($lifecycle->purgeDue()['purged'])->toBe(0)
        ->and(DB::table('contacts')->where('company_id', $tenant['company_id'])->count())->toBeGreaterThan(0);

    // changing its mind inside the grace period
    $this->postJson('/api/tenant-billing/deletion/cancel')->assertOk();
    expect($lifecycle->purgeDue()['purged'])->toBe(0);

    $this->postJson('/api/tenant-billing/deletion/request')->assertCreated();
    $this->postJson('/api/tenant-billing/deletion/confirm', ['code' => $code])->assertOk();
    DB::table('tenant_deletion_requests')->where('status', 'confirmed')->update(['scheduled_for' => now()->subDay()->toDateString()]);

    expect($lifecycle->purgeDue()['purged'])->toBe(1)
        ->and(DB::table('contacts')->where('company_id', $tenant['company_id'])->count())->toBe(0)
        ->and(DB::table('contacts')->where('company_id', $other['company_id'])->count())->toBe(1)
        ->and((bool) DB::table('companies')->where('id', $tenant['company_id'])->value('is_active'))->toBeFalse();
});

test('the onboarding checklist follows what the company has set up', function () {
    $tenant = w3Tenant();
    $services = app(TenantServices::class);
    $before = $services->onboarding((int) $tenant['company_id']);
    createSellRecord($tenant, ['invoice_no' => 'W3-1']);
    $after = $services->onboarding((int) $tenant['company_id']);

    $done = fn (array $c, string $key): bool => collect($c['items'])->firstWhere('key', $key)['done'];
    expect($before['total'])->toBe(10)->and($done($before, 'first_sale'))->toBeFalse()->and($done($after, 'first_sale'))->toBeTrue()
        ->and($done($after, 'accounts'))->toBeTrue()->and($after['done'])->toBeGreaterThan($before['done']);
});

test('support tickets are the tenant own, staff see and answer all, and a closed ticket says so', function () {
    $a = w3Tenant();
    $b = w3Tenant([], [], true);
    Sanctum::actingAs($a['admin']);
    $id = $this->postJson('/api/support-tickets', ['subject' => 'Cannot print', 'body' => 'The receipt is blank'])->assertCreated()->json('id');
    $this->getJson('/api/support-tickets')->assertOk()->assertJsonCount(1, 'data');

    Sanctum::actingAs($b['admin']);
    $this->getJson('/api/support-tickets')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/support-tickets/{$id}")->assertNotFound();

    Sanctum::actingAs(w3SuperAdmin());
    $this->getJson('/api/support-tickets')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/support-tickets/{$id}/reply", ['body' => 'Please update the printer driver', 'close' => true])->assertOk();

    Sanctum::actingAs($a['admin']);
    $ticket = $this->getJson("/api/support-tickets/{$id}")->assertOk()->json('data');
    expect($ticket['status'])->toBe('closed')->and($ticket['messages'])->toHaveCount(2)->and((bool) $ticket['messages'][1]['is_staff'])->toBeTrue()
        ->and(DB::table('app_notifications')->where('user_id', $a['admin']->id)->where('kind', 'support_reply')->count())->toBe(1);
});

test('a custom domain is verified by its DNS record, then the branding of its company is shown on that host only', function () {
    $tenant = w3Tenant([], ['brand_name' => 'Acme ERP', 'brand_color' => '#112233', 'hide_powered_by' => 1]);
    $services = app(TenantServices::class);
    Sanctum::actingAs($tenant['admin']);

    $this->postJson('/api/tenant-billing/domains', ['domain' => 'not a domain'])->assertUnprocessable();
    $row = $this->postJson('/api/tenant-billing/domains', ['domain' => 'ERP.Acme.test'])->assertCreated()->json('data');
    $this->postJson('/api/tenant-billing/domains', ['domain' => 'erp.acme.test'])->assertUnprocessable();

    expect($services->brandingForHost('erp.acme.test'))->toBeNull();
    $record = DB::table('custom_domains')->find($row['id']);
    expect($services->verifyDomain($record, fn (string $host): array => ['something else']))->toBeFalse()
        ->and($services->verifyDomain($record, fn (string $host): array => $host === '_erp-verify.erp.acme.test' ? [$record->verification_token] : []))->toBeTrue();

    $branding = $services->brandingForHost('erp.acme.test');
    expect($branding['name'])->toBe('Acme ERP')->and($branding['color'])->toBe('#112233')->and($branding['hide_powered_by'])->toBeTrue()
        ->and($services->brandingForHost('other.test'))->toBeNull();
    $this->getJson('/api/branding')->assertOk()->assertJsonPath('data', null);
});

// ---------------------------------------------------------------------------------------------------------------------
// integrations

test('the OpenAPI document and the Postman collection list the API routes and need permission', function () {
    $tenant = w3Tenant();
    Sanctum::actingAs(w3SuperAdmin());

    $openApi = $this->getJson('/api/docs/openapi')->assertOk()->json();
    expect($openApi['openapi'])->toBe('3.0.3')->and($openApi['paths'])->toHaveKey('/saas/overview')->and($openApi['paths']['/support-tickets/{id}']['get']['parameters'][0]['name'])->toBe('id');

    $postman = $this->getJson('/api/docs/postman')->assertOk()->json();
    expect($postman['info']['schema'])->toContain('v2.1.0')->and(collect($postman['item'])->pluck('name')->all())->toContain('saas');

    $plain = createStaffUserForRole(Role::query()->create(['name' => 'w3p', 'company_id' => $tenant['company_id'], 'is_active' => true]), ['company_id' => $tenant['company_id'], 'branch_id' => $tenant['branch_id'], 'email_verified_at' => now()]);
    Sanctum::actingAs($plain);
    $this->getJson('/api/docs/openapi')->assertForbidden();
});

test('the courier adapters send the documented requests and read tracking numbers and errors from recorded answers', function () {
    Http::fake([
        'api.postex.pk/*track-order*' => Http::response(w3Fixture('postex_track')),
        'api.postex.pk/*' => Http::response(w3Fixture('postex_create_order')),
        'merchantapi.leopardscourier.com/*trackBookedPacket*' => Http::response(w3Fixture('leopards_track')),
        'merchantapi.leopardscourier.com/*' => Http::response(w3Fixture('leopards_book')),
    ]);
    $shipment = ['consignee_name' => 'Ali Khan', 'consignee_phone' => '03001234567', 'consignee_address' => 'House 5, Street 2', 'city' => 'Lahore', 'cod_amount' => 2500, 'pieces' => 1, 'weight_grams' => 700, 'reference' => 'INV-77', 'description' => 'Shirts'];

    $postex = (new PostExAdapter)->book(['token' => 'pxtoken'], $shipment);
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), '/order/v3/create-order') && $r->hasHeader('token', 'pxtoken') && $r->data()['cityName'] === 'Lahore' && $r->data()['orderRefNumber'] === 'INV-77' && $r->data()['invoicePayment'] === '2500');
    expect($postex)->toMatchArray(['ok' => true, 'tracking_no' => '30301100008877'])
        ->and((new PostExAdapter)->track(['token' => 'pxtoken'], '30301100008877')['status'])->toBe('Delivered');

    $leopards = (new LeopardsAdapter)->book(['api_key' => 'k', 'api_password' => 'p'], $shipment);
    Http::assertSent(fn (HttpRequest $r): bool => str_contains($r->url(), '/bookPacket/format/json') && $r->data()['api_key'] === 'k' && $r->data()['booked_packet_collect_amount'] == 2500 && $r->data()['destination_city'] === 'Lahore');
    expect($leopards)->toMatchArray(['ok' => true, 'tracking_no' => 'KI730012345'])
        ->and((new LeopardsAdapter)->track(['api_key' => 'k', 'api_password' => 'p'], 'KI730012345')['status'])->toBe('Pending');

    expect((new PostExAdapter)->book([], $shipment)['ok'])->toBeFalse()->and((new LeopardsAdapter)->book(['api_key' => 'k'], $shipment)['ok'])->toBeFalse();
});

test('a courier error is reported and nothing is stored, and a booking is kept per company', function () {
    $tenant = w3Tenant();
    $other = fcoOtherCompany('x');
    Http::fake(['api.postex.pk/*' => Http::sequence()->push(w3Fixture('postex_error'), 400)->push(w3Fixture('postex_create_order'))]);
    Sanctum::actingAs($tenant['admin']);

    $payload = ['provider' => 'postex', 'consignee_name' => 'Ali', 'consignee_phone' => '0300', 'consignee_address' => 'Somewhere', 'city' => 'Karachi', 'cod_amount' => 100];
    $this->postJson('/api/integrations/courier/book', $payload)->assertUnprocessable();

    $this->putJson('/api/integrations/accounts', ['kind' => 'courier', 'provider' => 'postex', 'credentials' => ['token' => 'secret-token'], 'is_active' => true])->assertOk();
    $listed = $this->getJson('/api/integrations/accounts')->assertOk()->json('data');
    expect($listed[0]['has_credentials'])->toBeTrue()->and(json_encode($listed))->not->toContain('secret-token')
        ->and(Crypt::decryptString((string) DB::table('integration_accounts')->value('credentials')))->toContain('secret-token');

    $this->postJson('/api/integrations/courier/book', $payload)->assertUnprocessable();
    $booked = $this->postJson('/api/integrations/courier/book', $payload)->assertCreated()->json('data');
    expect($booked['tracking_no'])->toBe('30301100008877')->and(DB::table('courier_shipments')->where('company_id', $tenant['company_id'])->count())->toBe(1);

    // another company has no such shipment and no account
    Sanctum::actingAs(w3Tenant([], [], true)['admin']);
    $this->getJson('/api/integrations/courier/track?tracking_no=30301100008877')->assertNotFound();
    expect($other['company_id'])->toBeGreaterThan(0);
});

test('the Stripe adapter creates a checkout from the documented request and accepts only a correctly signed, recent webhook', function () {
    Http::fake(['api.stripe.com/*' => Http::sequence()->push(w3Fixture('stripe_checkout_session'))->push(w3Fixture('stripe_error'), 401)]);
    $gateway = new StripeGateway;

    $checkout = $gateway->createCheckout(['secret_key' => 'sk_test_x'], 1500.00, 'PKR', 'sub-1', 'Subscription', 'https://x.test/ok', 'https://x.test/no');
    Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://api.stripe.com/v1/checkout/sessions' && $r->hasHeader('Authorization', 'Bearer sk_test_x')
        && $r['mode'] === 'payment' && $r['client_reference_id'] === 'sub-1' && $r['line_items'][0]['price_data']['unit_amount'] === 150000 && $r['line_items'][0]['price_data']['currency'] === 'pkr');
    expect($checkout)->toMatchArray(['ok' => true, 'external_id' => 'cs_test_a1B2c3'])->and($checkout['checkout_url'])->toStartWith('https://checkout.stripe.com/');

    $payload = json_encode(w3Fixture('stripe_event_completed'));
    $now = 1_800_000_000;
    $sign = fn (string $secret, int $time): string => 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, $secret);
    $credentials = ['webhook_secret' => 'whsec_test'];

    $event = $gateway->readWebhook($credentials, $payload, $sign('whsec_test', $now), $now + 10);
    expect($event)->toMatchArray(['paid' => true, 'external_id' => 'cs_test_a1B2c3', 'reference' => 'sub-1', 'amount' => 1500.0])
        ->and($gateway->readWebhook($credentials, $payload, $sign('another', $now), $now))->toBeNull()
        ->and($gateway->readWebhook($credentials, $payload, $sign('whsec_test', $now), $now + 3600))->toBeNull()
        ->and($gateway->readWebhook($credentials, $payload.' ', $sign('whsec_test', $now), $now))->toBeNull();

    expect($gateway->createCheckout(['secret_key' => 'bad'], 1, 'PKR', 'r', 'd', 'https://a', 'https://b')['error'])->toContain('Invalid API Key');
});

test('a paid checkout webhook marks the subscription invoice paid once, and a bad signature is refused', function () {
    $tenant = w3Tenant();
    $invoice = w3Invoice($tenant, ['status' => 'unpaid']);
    DB::table('integration_accounts')->insert([
        'company_id' => $tenant['company_id'], 'kind' => 'gateway', 'provider' => 'stripe', 'is_active' => 1, 'options' => '{}',
        'credentials' => Crypt::encryptString(json_encode(['secret_key' => 'sk', 'webhook_secret' => 'whsec_live'])), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('gateway_payments')->insert(['company_id' => $tenant['company_id'], 'subscription_invoice_id' => $invoice->id, 'provider' => 'stripe', 'external_id' => 'cs_test_a1B2c3', 'amount' => 1000, 'currency' => 'PKR', 'status' => 'created', 'created_at' => now(), 'updated_at' => now()]);

    $payload = json_encode(w3Fixture('stripe_event_completed'));
    $header = fn (string $secret): array => ['Stripe-Signature' => 't='.time().',v1='.hash_hmac('sha256', time().'.'.$payload, $secret)];

    $this->call('POST', "/api/gateway/stripe/webhook/{$tenant['company_id']}", [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=bad', 'CONTENT_TYPE' => 'application/json'], $payload)->assertStatus(400);
    expect($invoice->fresh()->status)->toBe('unpaid');

    $time = time();
    $server = ['HTTP_STRIPE_SIGNATURE' => 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$payload, 'whsec_live'), 'CONTENT_TYPE' => 'application/json'];
    $this->call('POST', "/api/gateway/stripe/webhook/{$tenant['company_id']}", [], [], [], $server, $payload)->assertOk();
    $this->call('POST', "/api/gateway/stripe/webhook/{$tenant['company_id']}", [], [], [], $server, $payload)->assertOk();

    expect($invoice->fresh()->status)->toBe('paid')->and($invoice->fresh()->payment_method)->toBe('gateway')
        ->and(DB::table('gateway_payments')->value('status'))->toBe('paid');
    expect($header('x'))->toBeArray();
});

test('the bank feed returns statement lines in the reconciliation shape, skips broken rows and needs https', function () {
    Http::fake(['bank.example.test/*' => Http::response(w3Fixture('bank_feed'))]);
    $feed = new HttpJsonBankFeed;

    $result = $feed->fetch('https://bank.example.test/feed', ['token' => 't'], '2026-10-01', '2026-10-31');
    Http::assertSent(fn (HttpRequest $r): bool => $r->hasHeader('Authorization', 'Bearer t') && $r['from'] === '2026-10-01' && $r['to'] === '2026-10-31');

    expect($result['ok'])->toBeTrue()->and($result['lines'])->toHaveCount(2)
        ->and($result['lines'][0])->toMatchArray(['txn_date' => '2026-10-01', 'amount' => 2500.5, 'reference' => 'TX-1001'])
        ->and($result['lines'][1]['amount'])->toBe(-150.0)
        ->and($feed->fetch('http://bank.example.test/feed', [], '2026-10-01', '2026-10-31')['ok'])->toBeFalse();
});
