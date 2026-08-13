<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function createRole(string $name, array $attributes = []): Role
{
    return Role::query()->create(array_merge([
        'name' => $name,
        'is_active' => true,
        'is_admin' => false,
    ], $attributes));
}

function seedCompanyAndBranches(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'CMP001',
        'name' => 'Test Company',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $branchOneId = DB::table('branches')->insertGetId([
        'code' => 'BR001',
        'company_id' => $companyId,
        'name' => 'Branch One',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $branchTwoId = DB::table('branches')->insertGetId([
        'code' => 'BR002',
        'company_id' => $companyId,
        'name' => 'Branch Two',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'company_id' => $companyId,
        'branch_one_id' => $branchOneId,
        'branch_two_id' => $branchTwoId,
    ];
}

function createUserForRole(Role $role): User
{
    return User::query()->create([
        'role_id' => $role->id,
        'first_name' => 'Test',
        'last_name' => 'User',
        'username' => 'testuser_'.$role->id,
        'email' => 'testuser_'.$role->id.'@example.com',
        'password' => Hash::make('password'),
        'pass' => 'password',
        'is_active' => true,
    ]);
}

test('superadmin can see companyadmin roles in the roles api', function () {
    createRole('companyadmin');
    createRole('manager');

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/roles');

    $response->assertSuccessful();
    expect(collect($response->json('data.data'))->pluck('name'))
        ->toContain('companyadmin', 'manager');
});

test('non superadmin users cannot see companyadmin roles in the roles api', function () {
    createRole('companyadmin');
    $managerRole = createRole('manager');
    $user = createUserForRole($managerRole);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/roles');

    $response->assertSuccessful();
    expect(collect($response->json('data.data'))->pluck('name'))
        ->toContain('manager')
        ->not->toContain('companyadmin');
});

test('roles api rejects duplicate role names within the same company and branch', function () {
    $scope = seedCompanyAndBranches();
    createRole('manager', ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_one_id']]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/roles', [
        'name' => 'Manager',
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_one_id'],
        'is_active' => true,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('roles api allows the same role name for a different branch', function () {
    $scope = seedCompanyAndBranches();
    createRole('manager', ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_one_id']]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/roles', [
        'name' => 'Manager',
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_two_id'],
        'is_active' => true,
    ])->assertSuccessful();
});

test('roles api rejects duplicate role names', function () {
    createRole('manager');

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/roles', [
        'name' => 'Manager',
        'is_active' => true,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('roles api rejects renaming a role to an existing name', function () {
    createRole('manager');
    $editorRole = createRole('editor');

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->putJson('/api/roles/'.$editorRole->id, [
        'name' => ' manager ',
        'is_active' => true,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('roles check-name api reports when a role name already exists', function () {
    $scope = seedCompanyAndBranches();
    createRole('manager', ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_one_id']]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/roles/check-name', [
        'name' => 'Manager',
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_one_id'],
    ])->assertSuccessful()
        ->assertJson(['name_taken' => true]);

    $this->postJson('/api/roles/check-name', [
        'name' => 'Editor',
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_one_id'],
    ])->assertSuccessful()
        ->assertJson(['name_taken' => false]);
});

test('roles check-name api ignores the current record when editing', function () {
    $scope = seedCompanyAndBranches();
    $managerRole = createRole('manager', ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_one_id']]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/roles/check-name', [
        'name' => 'manager',
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_one_id'],
        'except_id' => $managerRole->id,
    ])->assertSuccessful()
        ->assertJson(['name_taken' => false]);
});

test('non superadmin users cannot view companyadmin role details', function () {
    $companyAdminRole = createRole('companyadmin');
    $managerRole = createRole('manager');
    $user = createUserForRole($managerRole);

    Sanctum::actingAs($user);

    $this->getJson('/api/roles/'.$companyAdminRole->id)->assertNotFound();
    $this->getJson('/api/roles/'.$managerRole->id)->assertSuccessful();
});
