<?php

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function seedCompanyAdminRole(): void
{
    if (DB::table('roles')->where('id', 2)->exists()) {
        return;
    }

    DB::table('roles')->insert([
        'id' => 2,
        'name' => 'companyadmin',
        'is_active' => 1,
        'is_admin' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function createCompanyForImport(array $attributes = []): Company
{
    return Company::query()->create(array_merge([
        'code' => 'CO-00088',
        'name' => 'Existing Company',
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 2,
    ], $attributes));
}

test('companies import creates records when id is empty', function () {
    seedCompanyAdminRole();

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/companies/import', [
        'rows' => [
            [
                'id' => '',
                'code' => 'CO-00020',
                'name' => 'Imported Company',
                'email' => 'company@import.test',
                'phone' => '03001234567',
                'ntn_no' => '',
                'address' => '',
                'zipcode' => '',
                'country_id' => '',
                'state_id' => '',
                'city_id' => '',
                'max_users' => 10,
                'max_branches' => 2,
                'is_active' => 1,
                'admin_name' => 'Import Admin',
                'admin_username' => 'importadmin',
                'admin_email' => 'admin@import.test',
                'admin_phone' => '',
                'password' => 'Password1!',
            ],
        ],
    ])->assertSuccessful()
        ->assertJsonPath('message', 'Successfully imported 1 new and updated 0 company records.');

    $company = Company::query()->where('name', 'Imported Company')->first();

    expect($company)->not->toBeNull()
        ->and($company->code)->toBe('CO-00020')
        ->and($company->is_active)->toBeTrue();

    expect(User::query()->where('email', 'admin@import.test')->exists())->toBeTrue();
});

test('companies import updates records when id is provided', function () {
    seedCompanyAdminRole();

    $company = createCompanyForImport();
    $admin = User::query()->forceCreate([
        'company_id' => $company->id,
        'role_id' => 2,
        'first_name' => 'Existing',
        'last_name' => 'Admin',
        'username' => 'existingadmin',
        'email' => 'existing@admin.test',
        'password' => bcrypt('Password1!'),
        'pass' => 'Password1!',
        'is_active' => true,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/companies/import', [
        'rows' => [
            [
                'id' => $company->id,
                'code' => $company->code,
                'name' => 'Updated Company Name',
                'email' => 'updated@company.test',
                'phone' => '',
                'ntn_no' => '',
                'address' => '',
                'zipcode' => '',
                'country_id' => '',
                'state_id' => '',
                'city_id' => '',
                'max_users' => 15,
                'max_branches' => 3,
                'is_active' => 1,
                'admin_name' => 'Updated Admin',
                'admin_username' => $admin->username,
                'admin_email' => $admin->email,
                'admin_phone' => '',
                'password' => '',
            ],
        ],
    ])->assertSuccessful()
        ->assertJsonPath('message', 'Successfully imported 0 new and updated 1 company records.');

    $company->refresh();
    $admin->refresh();

    expect($company->name)->toBe('Updated Company Name')
        ->and($company->email)->toBe('updated@company.test')
        ->and($company->max_users)->toBe(15)
        ->and($admin->first_name)->toBe('Updated');
});

test('companies import rejects unknown ids', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/companies/import', [
        'rows' => [
            [
                'id' => 999999,
                'code' => 'CO-00001',
                'name' => 'Missing Company',
                'max_users' => 10,
                'max_branches' => 2,
                'is_active' => 1,
            ],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['rows']);
});

test('guests cannot import companies', function () {
    $this->postJson('/api/companies/import', [
        'rows' => [],
    ])->assertUnauthorized();
});
