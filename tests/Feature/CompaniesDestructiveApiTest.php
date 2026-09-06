<?php

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function createTestCompany(array $overrides = []): Company
{
    static $counter = 0;
    $counter++;

    return Company::query()->create(array_merge([
        'code' => 'CO-'.str_pad((string) (90000 + $counter), 5, '0', STR_PAD_LEFT),
        'name' => 'Destructive Test Co '.$counter,
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 5,
    ], $overrides));
}

test('company destroy is forbidden for a non-superadmin', function () {
    $company = createTestCompany();

    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => $company->id, 'is_active' => true]);
    $user = createStaffUserForRole($role, ['company_id' => $company->id]);
    Sanctum::actingAs($user);

    $this->deleteJson('/api/companies/'.$company->id)
        ->assertForbidden();

    expect(Company::query()->find($company->id))->not->toBeNull();
});

test('company destroy deletes the company for a superadmin', function () {
    $company = createTestCompany();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->deleteJson('/api/companies/'.$company->id)
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Deleted');

    expect(Company::query()->find($company->id))->toBeNull();
});

test('company bulk_delete removes the given companies', function () {
    $companyOne = createTestCompany();
    $companyTwo = createTestCompany();

    $superadmin = User::query()->findOrFail(1);
    $role = Role::query()->findOrFail((int) $superadmin->role_id);
    grantMenuPermission((int) $role->id, '/company/delete');
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/companies/bulk_delete', [$companyOne->id, $companyTwo->id])
        ->assertSuccessful();

    expect(Company::query()->find($companyOne->id))->toBeNull()
        ->and(Company::query()->find($companyTwo->id))->toBeNull();
});

test('company updatestatus toggles active state', function () {
    $company = createTestCompany(['is_active' => true]);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/companies/statusupdate', ['ids' => [$company->id]])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Saved');

    expect((bool) $company->fresh()->is_active)->toBeFalse();
});

test('company updatestatus is forbidden for a non-superadmin', function () {
    $company = createTestCompany();

    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => $company->id, 'is_active' => true]);
    $user = createStaffUserForRole($role, ['company_id' => $company->id]);
    Sanctum::actingAs($user);

    $this->postJson('/api/companies/statusupdate', ['ids' => [$company->id]])
        ->assertForbidden();
});

test('company duplicate creates a named copy and 404s for a missing company', function () {
    $company = createTestCompany(['name' => 'Original Co']);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/companies/duplicate', ['id' => $company->id])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Duplicated');

    $duplicate = Company::query()->where('name', 'Original Co Copy')->first();

    expect($duplicate)->not->toBeNull()
        ->and($duplicate->code)->not->toBe($company->code);

    $this->postJson('/api/companies/duplicate', ['id' => 999999])
        ->assertNotFound();
});

test('company trash lists only soft-deleted companies', function () {
    $active = createTestCompany();
    $deleted = createTestCompany();
    $deleted->delete();

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/companies/trash');

    $response->assertSuccessful();
    $names = collect($response->json('data.data'))->pluck('name');

    expect($names)->toContain($deleted->name)
        ->and($names)->not->toContain($active->name);
});
