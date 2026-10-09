<?php

use App\Jobs\DispatchWebhook;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\WebhookDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function whCompany(): Company
{
    static $counter = 0;
    $counter++;

    return Company::query()->create([
        'code' => 'CO-A'.str_pad((string) $counter, 4, '0', STR_PAD_LEFT),
        'name' => 'Webhook Co '.$counter,
        'is_active' => true, 'max_users' => 10, 'max_branches' => 2,
    ]);
}

function whCompanyAdmin(Company $company): User
{
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => $company->id, 'is_active' => true]);
    grantMenuPermission((int) $role->id, '/webhooks');
    grantMenuPermission((int) $role->id, '/webhooks/add');
    grantMenuPermission((int) $role->id, '/webhooks/:id/edit');
    grantMenuPermission((int) $role->id, '/webhooks/delete');

    return createStaffUserForRole($role, ['company_id' => $company->id]);
}

test('a company admin can register a webhook and the secret is shown once', function () {
    $company = whCompany();
    Sanctum::actingAs(whCompanyAdmin($company));

    $response = $this->postJson('/api/webhooks', [
        'url' => 'https://example.com/hooks/accunivo',
        'events' => ['invoice.created'],
    ])->assertSuccessful();

    expect($response->json('secret'))->not->toBeNull();

    $webhook = Webhook::query()->where('company_id', $company->id)->firstOrFail();
    expect($webhook->url)->toBe('https://example.com/hooks/accunivo')
        ->and($webhook->events)->toBe(['invoice.created'])
        // The secret column holds the real value (encrypted at rest) — the API response is the only
        // place it's shown in full; a later read exposes only a masked preview.
        ->and($webhook->secret)->not->toBeEmpty();
});

test('a webhook only fires for events it is subscribed to', function () {
    $company = whCompany();
    $subscribed = Webhook::query()->create([
        'company_id' => $company->id, 'url' => 'https://a.example.com', 'events' => ['invoice.created'], 'secret' => 'sek1', 'is_active' => true,
    ]);
    $notSubscribed = Webhook::query()->create([
        'company_id' => $company->id, 'url' => 'https://b.example.com', 'events' => ['subscription.cancelled'], 'secret' => 'sek2', 'is_active' => true,
    ]);

    expect($subscribed->subscribesTo('invoice.created'))->toBeTrue()
        ->and($notSubscribed->subscribesTo('invoice.created'))->toBeFalse();
});

test('an inactive webhook does not fire', function () {
    $company = whCompany();
    Webhook::query()->create([
        'company_id' => $company->id, 'url' => 'https://a.example.com', 'events' => ['invoice.created'], 'secret' => 'sek1', 'is_active' => false,
    ]);

    Bus::fake();
    WebhookDispatcher::fire($company->id, 'invoice.created', ['x' => 1]);

    Bus::assertNotDispatched(DispatchWebhook::class);
});

test('firing an event dispatches a job per subscribed webhook', function () {
    $company = whCompany();
    Webhook::query()->create([
        'company_id' => $company->id, 'url' => 'https://a.example.com', 'events' => ['invoice.created'], 'secret' => 'sek1', 'is_active' => true,
    ]);
    Webhook::query()->create([
        'company_id' => $company->id, 'url' => 'https://b.example.com', 'events' => ['invoice.created'], 'secret' => 'sek2', 'is_active' => true,
    ]);

    Bus::fake();
    WebhookDispatcher::fire($company->id, 'invoice.created', ['invoice_no' => 'SUB-1']);

    Bus::assertDispatchedTimes(DispatchWebhook::class, 2);
});

test('the dispatch job POSTs a signed payload and records the delivery', function () {
    Http::fake(['https://example.com/hook' => Http::response(['ok' => true], 200)]);

    $company = whCompany();
    $webhook = Webhook::query()->create([
        'company_id' => $company->id, 'url' => 'https://example.com/hook', 'events' => ['invoice.created'], 'secret' => 'topsecret', 'is_active' => true,
    ]);

    (new DispatchWebhook($webhook->id, 'invoice.created', ['invoice_no' => 'SUB-1']))->handle();

    Http::assertSent(fn ($request) => $request->hasHeader('X-Webhook-Signature') && $request->url() === 'https://example.com/hook');

    $delivery = WebhookDelivery::query()->where('webhook_id', $webhook->id)->firstOrFail();
    expect($delivery->success)->toBeTrue()
        ->and($delivery->response_code)->toBe(200);

    expect($webhook->fresh()->failure_count)->toBe(0);
});

test('a failed delivery increments the webhook failure count', function () {
    Http::fake(['https://example.com/down' => Http::response('nope', 500)]);

    $company = whCompany();
    $webhook = Webhook::query()->create([
        'company_id' => $company->id, 'url' => 'https://example.com/down', 'events' => ['invoice.created'], 'secret' => 'topsecret', 'is_active' => true,
    ]);

    (new DispatchWebhook($webhook->id, 'invoice.created', ['invoice_no' => 'SUB-1']))->handle();

    expect($webhook->fresh()->failure_count)->toBe(1);
    $delivery = WebhookDelivery::query()->where('webhook_id', $webhook->id)->firstOrFail();
    expect($delivery->success)->toBeFalse();
});

test('a webhook cannot be registered for an unknown event', function () {
    $company = whCompany();
    Sanctum::actingAs(whCompanyAdmin($company));

    $this->postJson('/api/webhooks', [
        'url' => 'https://example.com/hook',
        'events' => ['not.a.real.event'],
    ])->assertUnprocessable()->assertJsonValidationErrors(['events.0']);
});
