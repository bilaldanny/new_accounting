<?php

use App\Models\Lead;
use App\Models\LeadSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The array cache store (tests' CACHE_STORE) persists across tests in the same run, and the
    // lead-capture rate limiter is keyed by IP alone — without this, an earlier test's hits would
    // bleed into a later one and cause spurious 429s.
    RateLimiter::clear('lead-capture:127.0.0.1');
});

function plcCompany(string $code = 'PLC001', bool $active = true): int
{
    return DB::table('companies')->insertGetId([
        'code' => $code,
        'name' => 'Company '.$code,
        'address' => '1 Test Street',
        'is_active' => $active,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('the capture page renders for a valid, active company', function () {
    plcCompany('PLC001');

    $this->get('/capture/PLC001')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('public/leadcapture')
            ->where('companyCode', 'PLC001')
            ->where('companyName', 'Company PLC001'));
});

test('the capture page 404s for an unknown or inactive company code', function () {
    plcCompany('PLC002', active: false);

    $this->get('/capture/does-not-exist')->assertNotFound();
    $this->get('/capture/PLC002')->assertNotFound();
});

test('the capture page needs no authentication at all', function () {
    plcCompany('PLC001');

    // No Sanctum::actingAs(), no session — a cold, anonymous request.
    $this->get('/capture/PLC001')->assertSuccessful();
});

test('submitting the form creates a lead with the website lead source, unauthenticated', function () {
    $companyId = plcCompany('PLC001');

    $this->postJson('/api/leadcapture', [
        'company_code' => 'PLC001',
        'name' => 'Ahmed Raza',
        'company_name' => 'Raza Traders',
        'email' => 'ahmed@example.com',
        'phone' => '0300-1234567',
        'notes' => 'Interested in a demo.',
    ])->assertSuccessful();

    $lead = Lead::query()->where('company_id', $companyId)->firstOrFail();

    expect($lead->name)->toBe('Ahmed Raza')
        ->and($lead->company_name)->toBe('Raza Traders')
        ->and($lead->status)->toBe('new')
        ->and($lead->source)->toBe('Website');

    $source = LeadSource::query()->where('company_id', $companyId)->where('name', 'Website')->first();
    expect($source)->not->toBeNull()
        ->and($lead->lead_source_id)->toBe($source->id);
});

test('the website lead source is reused, not duplicated, on repeat submissions', function () {
    $companyId = plcCompany('PLC001');

    $this->postJson('/api/leadcapture', ['company_code' => 'PLC001', 'name' => 'First Lead'])->assertSuccessful();
    $this->postJson('/api/leadcapture', ['company_code' => 'PLC001', 'name' => 'Second Lead'])->assertSuccessful();

    expect(LeadSource::query()->where('company_id', $companyId)->where('name', 'Website')->count())->toBe(1)
        ->and(Lead::query()->count())->toBe(2);
});

test('a filled honeypot field silently skips creating the lead', function () {
    plcCompany('PLC001');

    $this->postJson('/api/leadcapture', [
        'company_code' => 'PLC001',
        'name' => 'Bot Submission',
        'website_url' => 'http://spam.example.com',
    ])->assertSuccessful();

    expect(Lead::query()->count())->toBe(0);
});

test('the form validates required and malformed fields', function (array $overrides, string $field) {
    plcCompany('PLC001');

    $this->postJson('/api/leadcapture', array_merge([
        'company_code' => 'PLC001',
        'name' => 'Ahmed Raza',
    ], $overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(Lead::query()->count())->toBe(0);
})->with([
    'no name' => [['name' => ''], 'name'],
    'name too short' => [['name' => 'a'], 'name'],
    'invalid email' => [['email' => 'not-an-email'], 'email'],
    'phone with letters' => [['phone' => '03OO-abc'], 'phone'],
    'no company code' => [['company_code' => ''], 'company_code'],
]);

test('an unknown company code 404s on submit too', function () {
    $this->postJson('/api/leadcapture', [
        'company_code' => 'does-not-exist',
        'name' => 'Ahmed Raza',
    ])->assertNotFound();

    expect(Lead::query()->count())->toBe(0);
});

test('a lead from one company never leaks into another company\'s dropdown', function () {
    plcCompany('PLC001');
    $otherCompanyId = plcCompany('PLC002');

    $this->postJson('/api/leadcapture', ['company_code' => 'PLC001', 'name' => 'Ahmed Raza'])->assertSuccessful();

    expect(Lead::query()->where('company_id', $otherCompanyId)->count())->toBe(0);
});

test('the submit endpoint is rate limited per ip', function () {
    plcCompany('PLC001');

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/leadcapture', ['company_code' => 'PLC001', 'name' => 'Lead '.$i])
            ->assertSuccessful();
    }

    $this->postJson('/api/leadcapture', ['company_code' => 'PLC001', 'name' => 'One Too Many'])
        ->assertStatus(429);

    expect(Lead::query()->count())->toBe(5);
});
