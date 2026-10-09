<?php

use App\Mail\Support\SoftwareBranding;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function gsSetting(array $values = []): void
{
    DB::table('settings')->updateOrInsert(['id' => 1], $values + [
        'name' => 'Acme Books', 'email' => 'private-owner@example.test', 'contact_no' => '0300-5550000', 'address' => 'Secret Street 5',
        'smtp_host' => 'smtp.secret.test', 'smtp_username' => 'mailer-user', 'smtp_from_address' => 'from@secret.test',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('every signed-out page gets only the name and the login logo, never the settings row', function () {
    gsSetting(['login_logo' => '/storage/photos/1/logo_icon.png']);

    foreach (['/login', '/forgot-password', '/', '/register', '/blog'] as $url) {
        $response = $this->get($url)->assertOk();
        $props = $response->viewData('page')['props'];
        $json = json_encode($props);

        expect($json)->not->toContain('smtp')->not->toContain('mailer-user')->not->toContain('private-owner')->not->toContain('0300-5550000')->not->toContain('Secret Street')
            ->and($props['name'])->toBe('Acme Books')
            ->and(array_keys($props['setting'] ?? []))->toBe(['login_logo_url'])
            ->and($props)->not->toHaveKeys(['dailCode', 'ipAddress', 'timezone']);
    }
});

test('the login page still receives the login logo it shows', function () {
    gsSetting(['login_logo' => '/storage/photos/1/logo_icon.png']);

    $this->get('/login')->assertInertia(fn (Assert $page) => $page
        ->component('auth/Login')
        ->where('name', 'Acme Books')
        ->where('setting.login_logo_url', fn (string $url) => str_ends_with($url, '/storage/photos/1/logo_icon.png')));

    gsSetting(['login_logo' => null]);
    $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('setting.login_logo_url', null));
});

test('a signed-in page still gets its full settings and user data', function () {
    gsSetting();

    $this->actingAs(User::query()->findOrFail(1))->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('setting.name', 'Acme Books')
        ->has('dailCode')
        ->has('auth.user.permissions'));
});

test('the shipped default logo is a text-only placeholder, not another product\'s mark', function () {
    expect(public_path(Setting::PLACEHOLDER_LOGO))->toBeFile()
        ->and(file_get_contents(public_path(Setting::PLACEHOLDER_LOGO)))->toContain('Your logo')
        ->and(public_path('assets/images/logo-img.png'))->not->toBeFile()
        ->and(public_path('assets/images/logo-icon.png'))->not->toBeFile()
        ->and((new Setting)->getAttribute('system_logo'))->toBe(Setting::PLACEHOLDER_LOGO);

    foreach (['app', 'resources/js', 'resources/views'] as $dir) {
        $hits = collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS)))
            ->filter(fn (SplFileInfo $file) => $file->isFile() && preg_match('/\.(php|vue|ts)$/', $file->getFilename()) === 1)
            ->filter(fn (SplFileInfo $file) => str_contains((string) file_get_contents($file->getPathname()), 'logo-icon.png'))
            ->map(fn (SplFileInfo $file) => $file->getPathname())->all();

        expect($hits)->toBe([]);
    }
});

test('emails fall back to the letter mark when only the placeholder is set, and use an uploaded logo otherwise', function () {
    gsSetting(['system_logo' => Setting::PLACEHOLDER_LOGO, 'email_logo' => null]);
    expect(SoftwareBranding::resolve()['companyLogo'])->toBeNull();

    gsSetting(['system_logo' => 'assets/images/logo-img.png', 'email_logo' => null]);
    expect(SoftwareBranding::resolve()['companyLogo'])->toBeNull();

    gsSetting(['system_logo' => '/storage/photos/1/logo_icon.png', 'email_logo' => '/storage/photos/1/full_logo_dark.png']);
    expect(SoftwareBranding::resolve()['companyLogo'])->toEndWith('/storage/photos/1/full_logo_dark.png');
});

test('the migration swaps the old default for the placeholder and leaves uploaded logos alone', function () {
    gsSetting(['system_logo' => 'assets/images/logo-img.png', 'email_logo' => 'assets/images/logo-img.png', 'login_logo' => '/storage/photos/1/mine.png']);

    (require database_path('migrations/2026_10_29_100000_replace_legacy_placeholder_logo.php'))->up();

    $row = DB::table('settings')->first();
    expect($row->system_logo)->toBe(Setting::PLACEHOLDER_LOGO)->and($row->email_logo)->toBeNull()->and($row->login_logo)->toBe('/storage/photos/1/mine.png');

    gsSetting(['system_logo' => '/storage/photos/1/logo_icon.png']);
    (require database_path('migrations/2026_10_29_100000_replace_legacy_placeholder_logo.php'))->up();

    expect(DB::table('settings')->value('system_logo'))->toBe('/storage/photos/1/logo_icon.png');
});
