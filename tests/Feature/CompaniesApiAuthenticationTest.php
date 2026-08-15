<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('guests cannot access the companies api', function () {
    $this->getJson('/api/companies')
        ->assertUnauthorized();
});

test('company web routes require authentication', function () {
    $this->get(route('company'))
        ->assertRedirect();

    $this->get(route('company.trash'))
        ->assertRedirect();
});

test('companies generate-code api returns the next sequential code', function () {
    Company::query()->create([
        'code' => 'CO-00001',
        'name' => 'First Company',
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 2,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->getJson('/api/companies/generate-code')
        ->assertSuccessful()
        ->assertJson(['code' => 'CO-00002']);

    Company::query()->create([
        'code' => 'CO-00005',
        'name' => 'Fifth Company',
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 2,
    ]);

    $this->getJson('/api/companies/generate-code')
        ->assertSuccessful()
        ->assertJson(['code' => 'CO-00006']);
});

test('companies check-code api reports when a code already exists', function () {
    Company::query()->create([
        'code' => 'CO-00001',
        'name' => 'Acme Corp',
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 2,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/companies/check-code', [
        'code' => 'CO-00001',
    ])->assertSuccessful()->assertJson(['code_taken' => true]);

    $this->postJson('/api/companies/check-code', [
        'code' => 'CO-00099',
    ])->assertSuccessful()->assertJson(['code_taken' => false]);
});

test('companies check-code api ignores the current record when editing', function () {
    $company = Company::query()->create([
        'code' => 'CO-00001',
        'name' => 'Acme Corp',
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 2,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/companies/check-code', [
        'code' => 'CO-00001',
        'except_id' => $company->id,
    ])->assertSuccessful()->assertJson(['code_taken' => false]);
});

test('companies check-admin-identity api reports when admin email or username already exists', function () {
    User::query()->create([
        'first_name' => 'Existing',
        'last_name' => 'Admin',
        'username' => 'existingadmin',
        'email' => 'existing@example.com',
        'password' => bcrypt('password'),
        'pass' => 'password',
        'is_active' => true,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/companies/check-admin-identity', [
        'email' => 'existing@example.com',
    ])->assertSuccessful()->assertJson(['email_taken' => true]);

    $this->postJson('/api/companies/check-admin-identity', [
        'email' => 'new@example.com',
    ])->assertSuccessful()->assertJson(['email_taken' => false]);

    $this->postJson('/api/companies/check-admin-identity', [
        'username' => 'existingadmin',
    ])->assertSuccessful()->assertJson(['username_taken' => true]);

    $this->postJson('/api/companies/check-admin-identity', [
        'username' => 'newadmin',
    ])->assertSuccessful()->assertJson(['username_taken' => false]);
});

test('companies check-admin-identity api ignores the current admin user when editing', function () {
    $admin = User::query()->create([
        'first_name' => 'Current',
        'last_name' => 'Admin',
        'username' => 'currentadmin',
        'email' => 'current@example.com',
        'password' => bcrypt('password'),
        'pass' => 'password',
        'is_active' => true,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/companies/check-admin-identity', [
        'email' => 'current@example.com',
        'except_user_id' => $admin->id,
    ])->assertSuccessful()->assertJson(['email_taken' => false]);

    $this->postJson('/api/companies/check-admin-identity', [
        'username' => 'currentadmin',
        'except_user_id' => $admin->id,
    ])->assertSuccessful()->assertJson(['username_taken' => false]);
});

test('companies index includes owner and usage columns for the list', function () {
    $company = Company::query()->create([
        'code' => 'CO-00020',
        'name' => 'List Test Co',
        'email' => 'company@example.com',
        'phone' => '03001111111',
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 3,
    ]);

    Branch::query()->create([
        'code' => 'BR-00001',
        'company_id' => $company->id,
        'name' => 'Main Branch',
        'is_active' => true,
        'is_default' => true,
    ]);

    User::query()->forceCreate([
        'first_name' => 'Owner',
        'last_name' => 'Person',
        'username' => 'ownerperson',
        'email' => 'owner@example.com',
        'phone' => '03002222222',
        'password' => bcrypt('password'),
        'pass' => 'password',
        'company_id' => $company->id,
        'is_active' => true,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->getJson('/api/companies?show_record=100&cur_page=1&sort_by=created_at&sort_type=desc')
        ->assertSuccessful()
        ->assertJsonFragment([
            'name' => 'List Test Co',
            'email' => 'company@example.com',
            'phone' => '03001111111',
            'admin_name' => 'Owner Person',
            'admin_email' => 'owner@example.com',
            'admin_phone' => '03002222222',
            'branches_usage' => '1/3',
            'users_usage' => '1/10',
        ]);
});

test('company admin phone is persisted on the admin user', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/companies', [
        'code' => 'CO-00010',
        'name' => 'Phone Test Co',
        'password' => 'Password1!',
        'password_confirmation' => 'Password1!',
        'admin_name' => 'Jane Admin',
        'admin_username' => 'janeadmin',
        'admin_email' => 'jane@example.com',
        'admin_phone' => '03001234567',
        'max_users' => 10,
        'max_branches' => 2,
        'logo' => 'companies/test-logo.png',
        'is_active' => true,
    ])->assertSuccessful();

    $admin = User::query()->where('email', 'jane@example.com')->first();

    expect($admin)->not->toBeNull()
        ->and($admin->phone)->toBe('03001234567');

    $company = Company::query()->where('code', 'CO-00010')->firstOrFail();

    $this->getJson("/api/companies/{$company->id}")
        ->assertSuccessful()
        ->assertJsonPath('admin_phone', '03001234567');
});
