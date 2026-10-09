<?php

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function wlFile(string $name): string
{
    file_put_contents(public_path($name), base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

    return '/'.$name;
}

function wlSetting(array $values): void
{
    DB::table('settings')->updateOrInsert(['id' => 1], $values + ['name' => 'Acme Books', 'created_at' => now(), 'updated_at' => now()]);
}

afterEach(function () {
    foreach (['wl-system.png', 'wl-header.png', 'wl-footer.png'] as $file) {
        @unlink(public_path($file));
    }
});

test('the superadmin saves a website header and footer logo in Software Settings', function () {
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->putJson('/api/software-settings', ['name' => 'Ledger Desk', 'header_logo' => '/storage/photos/1/header.png', 'footer_logo' => '/storage/photos/1/footer.png'])
        ->assertSuccessful()
        ->assertJsonPath('softwareSetting.header_logo', '/storage/photos/1/header.png')
        ->assertJsonPath('softwareSetting.footer_logo', '/storage/photos/1/footer.png')
        ->assertJsonPath('softwareSetting.header_logo_url', Setting::logoUrl('/storage/photos/1/header.png'))
        ->assertJsonPath('softwareSetting.footer_logo_url', Setting::logoUrl('/storage/photos/1/footer.png'));

    expect(Setting::query()->first()->only(['header_logo', 'footer_logo']))->toBe(['header_logo' => '/storage/photos/1/header.png', 'footer_logo' => '/storage/photos/1/footer.png']);

    $this->putJson('/api/software-settings', ['name' => 'Ledger Desk', 'header_logo' => '', 'footer_logo' => ''])->assertSuccessful();
    expect(Setting::query()->first()->header_logo)->toBeNull()->and(Setting::query()->first()->footer_logo)->toBeNull();
});

test('the public pages use the header logo in the header and the footer logo in the footer, drawn as they are', function () {
    wlSetting(['system_logo' => wlFile('wl-system.png'), 'header_logo' => wlFile('wl-header.png'), 'footer_logo' => wlFile('wl-footer.png')]);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('branding.headerLogoUrl', fn (string $url) => str_ends_with($url, '/wl-header.png'))
        ->where('branding.footerLogoUrl', fn (string $url) => str_ends_with($url, '/wl-footer.png'))
        ->where('branding.headerChip', false)
        ->where('branding.footerChip', false));
});

test('an empty footer logo falls back to the header logo, and an empty header logo to the software logo on a chip', function () {
    wlSetting(['system_logo' => wlFile('wl-system.png'), 'header_logo' => wlFile('wl-header.png'), 'footer_logo' => null]);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('branding.footerLogoUrl', fn (string $url) => str_ends_with($url, '/wl-header.png'))
        ->where('branding.footerChip', false));

    wlSetting(['system_logo' => wlFile('wl-system.png'), 'header_logo' => null, 'footer_logo' => null]);

    $this->get('/')->assertInertia(fn (Assert $page) => $page
        ->where('branding.headerLogoUrl', fn (string $url) => str_ends_with($url, '/wl-system.png'))
        ->where('branding.footerLogoUrl', fn (string $url) => str_ends_with($url, '/wl-system.png'))
        ->where('branding.headerChip', true)
        ->where('branding.footerChip', true));
});

test('a missing header or footer file falls back instead of offering a broken image', function () {
    wlSetting(['system_logo' => null, 'header_logo' => '/storage/photos/1/gone-header.png', 'footer_logo' => '/storage/photos/1/gone-footer.png']);

    foreach (['/', '/register', '/blog'] as $url) {
        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('branding.headerLogoUrl', null)->where('branding.footerLogoUrl', null));
    }
});

test('the blog and register pages receive the same header and footer branding', function () {
    wlSetting(['header_logo' => wlFile('wl-header.png'), 'footer_logo' => wlFile('wl-footer.png')]);

    foreach (['/register', '/blog'] as $url) {
        $this->get($url)->assertInertia(fn (Assert $page) => $page->has('branding.headerLogoUrl')->has('branding.footerLogoUrl'));
    }
});
