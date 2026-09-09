<?php

use App\Mail\SmtpTestMail;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('guests cannot access software settings api', function () {
    $this->getJson('/api/software-settings')
        ->assertUnauthorized();
});

test('software setting web route requires authentication', function () {
    $this->get(route('software.setting'))
        ->assertRedirect();
});

test('software setting menu route renders software settings page', function () {
    $superadmin = User::query()->findOrFail(1);
    $setting = Setting::instance();
    $setting->system_logo = 'photos/software-logo.png';
    $setting->save();

    $this->actingAs($superadmin)
        ->get(route('software.setting'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('software/setting')
            ->where('name', $setting->name)
            ->where('setting.system_logo', 'photos/software-logo.png')
            ->where('setting.system_logo_url', Setting::logoUrl('photos/software-logo.png'))
        );
});

test('software setting menu is created for the sidebar', function () {
    expect(DB::table('menus')->where('route_path', '/software/setting')->exists())->toBeTrue();
});

test('superadmin can view and update software settings', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->getJson('/api/software-settings')
        ->assertSuccessful()
        ->assertJsonPath('softwareSetting.name', config('app.name'))
        ->assertJsonPath('softwareSetting.smtp_password', '');

    $this->putJson('/api/software-settings', [
        'name' => 'Ledger Desk',
        'email' => 'support@ledger.test',
        'contact_no' => '03001234567',
        'address' => 'Software HQ',
        'system_logo' => 'assets/images/logo-light.png',
        'email_logo' => 'photos/email-logo.png',
        'login_logo' => 'photos/login-logo.png',
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => 587,
        'smtp_username' => 'mailer@example.com',
        'smtp_password' => 'secret-pass',
        'smtp_encryption' => 'tls',
        'smtp_scheme' => 'smtp',
        'smtp_from_address' => 'noreply@example.com',
        'smtp_from_name' => 'Ledger Desk',
    ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Saved')
        ->assertJsonPath('softwareSetting.name', 'Ledger Desk')
        ->assertJsonPath('softwareSetting.email', 'support@ledger.test')
        ->assertJsonPath('softwareSetting.email_logo', 'photos/email-logo.png')
        ->assertJsonPath('softwareSetting.email_logo_url', Setting::logoUrl('photos/email-logo.png'))
        ->assertJsonPath('softwareSetting.login_logo', 'photos/login-logo.png')
        ->assertJsonPath('softwareSetting.login_logo_url', Setting::logoUrl('photos/login-logo.png'))
        ->assertJsonPath('softwareSetting.smtp_host', 'smtp.example.com')
        ->assertJsonPath('softwareSetting.smtp_port', 587)
        ->assertJsonPath('softwareSetting.smtp_scheme', 'smtp')
        ->assertJsonPath('softwareSetting.has_smtp_password', true)
        ->assertJsonPath('softwareSetting.smtp_password', '');

    $setting = Setting::query()->first();

    expect($setting)->not->toBeNull()
        ->and($setting->name)->toBe('Ledger Desk')
        ->and($setting->smtp_password)->toBe('secret-pass')
        ->and(config('app.name'))->toBe('Ledger Desk');

    $this->getJson('/api/software-settings')
        ->assertSuccessful()
        ->assertJsonPath('softwareSetting.smtp_host', 'smtp.example.com')
        ->assertJsonPath('softwareSetting.smtp_port', 587)
        ->assertJsonPath('softwareSetting.smtp_username', 'mailer@example.com')
        ->assertJsonPath('softwareSetting.smtp_scheme', 'smtp')
        ->assertJsonPath('softwareSetting.smtp_encryption', 'tls')
        ->assertJsonPath('softwareSetting.smtp_from_address', 'noreply@example.com')
        ->assertJsonPath('softwareSetting.smtp_from_name', 'Ledger Desk')
        ->assertJsonPath('softwareSetting.has_smtp_password', true)
        ->assertJsonPath('softwareSetting.smtp_password', '');
});

test('blank smtp password does not overwrite the stored password', function () {
    $setting = Setting::instance();
    $setting->smtp_password = 'keep-me';
    $setting->name = 'Existing App';
    $setting->save();

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->putJson('/api/software-settings', [
        'name' => 'Existing App',
        'smtp_password' => '',
    ])
        ->assertSuccessful()
        ->assertJsonPath('softwareSetting.has_smtp_password', true);

    expect($setting->fresh()->smtp_password)->toBe('keep-me');
});

test('company users cannot access software settings', function () {
    $company = Company::query()->create([
        'code' => 'CO-00020',
        'name' => 'Own Corp',
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 2,
    ]);

    $branchId = Branch::query()->create([
        'code' => 'BR-00020',
        'company_id' => $company->id,
        'name' => 'Main Branch',
        'is_active' => true,
    ])->id;

    $roleId = Role::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branchId,
        'name' => 'companyadmin',
        'is_active' => true,
    ])->id;

    $user = User::query()->create([
        'first_name' => 'Company',
        'last_name' => 'Admin',
        'username' => 'companyadmin_software',
        'email' => 'companyadmin_software@example.com',
        'password' => bcrypt('password'),
        'is_active' => true,
        'company_id' => $company->id,
        'branch_id' => $branchId,
        'role_id' => $roleId,
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/software-settings')->assertForbidden();
    $this->putJson('/api/software-settings', ['name' => 'Hacked'])->assertForbidden();
    $this->actingAs($user)->get(route('software.setting'))->assertForbidden();
});

test('software settings name is required', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->putJson('/api/software-settings', [
        'name' => '',
    ])->assertUnprocessable();
});

test('superadmin can send a software settings test email', function () {
    Mail::fake();

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/software-settings/test-send', [
        'test_email' => 'qa@example.com',
    ])
        ->assertSuccessful()
        ->assertJsonPath('status', 'success');

    Mail::assertSent(SmtpTestMail::class, function (SmtpTestMail $mail): bool {
        return $mail->hasTo('qa@example.com');
    });
});

test('smtp connection test fails when smtp is not configured', function () {
    config([
        'mail.mailers.smtp.host' => null,
        'mail.mailers.smtp.username' => null,
        'mail.mailers.smtp.password' => null,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/software-settings/test-smtp', [])
        ->assertStatus(400)
        ->assertJsonPath('status', 'alert');
});

test('mail configuration is read from software settings instead of env', function () {
    config([
        'mail.default' => 'array',
        'mail.mailers.smtp.scheme' => 'smtps',
        'mail.mailers.smtp.host' => 'env-host.example.com',
        'mail.mailers.smtp.port' => 465,
        'mail.mailers.smtp.username' => 'env-user',
        'mail.mailers.smtp.password' => 'env-pass',
        'mail.from.address' => 'env@example.com',
        'mail.from.name' => 'Env Sender',
    ]);

    $setting = Setting::instance();
    $setting->smtp_host = 'db-host.example.com';
    $setting->smtp_port = 587;
    $setting->smtp_username = 'db-user';
    $setting->smtp_password = 'db-pass';
    $setting->smtp_scheme = 'smtp';
    $setting->smtp_encryption = 'tls';
    $setting->smtp_from_address = 'db@example.com';
    $setting->smtp_from_name = 'DB Sender';
    $setting->save();

    expect(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('db-host.example.com')
        ->and(config('mail.mailers.smtp.port'))->toBe(587)
        ->and(config('mail.mailers.smtp.username'))->toBe('db-user')
        ->and(config('mail.mailers.smtp.password'))->toBe('db-pass')
        ->and(config('mail.mailers.smtp.scheme'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.encryption'))->toBe('tls')
        ->and(config('mail.from.address'))->toBe('db@example.com')
        ->and(config('mail.from.name'))->toBe('DB Sender');
});

test('login page shares the configured login logo url', function () {
    $setting = Setting::instance();
    $setting->login_logo = 'photos/login-logo.png';
    $setting->save();

    $this->get(route('login'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/Login')
            ->where('setting.login_logo', 'photos/login-logo.png')
            ->where('setting.login_logo_url', Setting::logoUrl('photos/login-logo.png'))
        );
});

test('application name is read from software settings instead of env', function () {
    config(['app.name' => 'Env App']);

    $setting = Setting::instance();
    $setting->name = 'Database App';
    $setting->save();

    expect(config('app.name'))->toBe('Database App');

    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('software.setting'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('software/setting')
            ->where('name', 'Database App'));
});
