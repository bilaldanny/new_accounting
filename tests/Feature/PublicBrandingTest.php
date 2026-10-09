<?php

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function pbSetting(array $values): void
{
    DB::table('settings')->updateOrInsert(['id' => 1], $values + ['name' => 'Acme Books', 'created_at' => now(), 'updated_at' => now()]);
}

function pbLogoFile(string $name = 'pb-test-logo.png'): string
{
    file_put_contents(public_path($name), base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

    return $name;
}

afterEach(function () {
    @unlink(public_path('pb-test-logo.png'));
});

test('the landing page carries the app name and the system logo from settings', function () {
    pbSetting(['name' => 'Acme Books', 'system_logo' => '/'.pbLogoFile()]);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('branding.name', 'Acme Books')
        ->where('branding.logoUrl', fn (string $url) => str_ends_with($url, '/pb-test-logo.png')));
});

test('the register page gets the same branding', function () {
    pbSetting(['name' => 'Acme Books', 'system_logo' => '/'.pbLogoFile()]);

    $this->get('/register')->assertInertia(fn (Assert $page) => $page->where('branding.name', 'Acme Books')->has('branding.logoUrl'));
});

test('a missing logo file falls back to the name and a broken image is never offered', function () {
    pbSetting(['name' => 'Acme Books', 'system_logo' => '/storage/photos/1/gone.png']);

    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('branding.name', 'Acme Books')->where('branding.logoUrl', null));
});

test('the placeholder of a fresh install and the old default count as no logo', function () {
    foreach ([Setting::PLACEHOLDER_LOGO, 'assets/images/logo-img.png', '/assets/images/logo-img.png'] as $path) {
        pbSetting(['name' => 'Acme Books', 'system_logo' => $path]);

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('branding.logoUrl', null));
    }
});

test('a full URL logo is used as it is, and an empty setting falls back to the app name', function () {
    pbSetting(['name' => '', 'system_logo' => 'https://cdn.example.test/logo.svg']);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('branding.logoUrl', 'https://cdn.example.test/logo.svg')
        ->where('branding.name', config('app.name')));
});

test('a change in settings shows on the landing page at once', function () {
    pbSetting(['name' => 'First Name']);
    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('branding.name', 'First Name'));

    Setting::query()->first()->update(['name' => 'Second Name']);

    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('branding.name', 'Second Name'));
});

test('a guest on a public page receives no settings row and no other setting', function () {
    pbSetting(['smtp_host' => 'smtp.secret.test', 'smtp_username' => 'mailer-user', 'contact_no' => '0300', 'address' => 'Private Street']);

    foreach (['/', '/register'] as $url) {
        $response = $this->get($url);
        $props = json_encode($response->viewData('page')['props']);

        expect($props)->not->toContain('smtp_')->not->toContain('mailer-user')->not->toContain('Private Street')->not->toContain('smtp.secret.test');
        $response->assertInertia(fn (Assert $page) => $page->where('setting.login_logo_url', null)->missing('setting.smtp_host')->missing('setting.email'));
    }
});

test('signed-in pages still get their settings as before', function () {
    pbSetting(['name' => 'Acme Books']);

    $this->actingAs(User::query()->findOrFail(1))->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('setting.name', 'Acme Books'));
});
