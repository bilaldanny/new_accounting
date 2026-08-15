<?php

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function createDepartment(string $name, array $attributes = []): Department
{
    return Department::query()->create(array_merge([
        'name' => $name,
        'active' => true,
    ], $attributes));
}

function seedDepartmentScope(): array
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

function createUserForDepartmentRole(Role $role): User
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

test('departments api rejects duplicate department names within the same company and branch', function () {
    $scope = seedDepartmentScope();
    createDepartment('sales', ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_one_id']]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/departments', [
        'name' => 'Sales',
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_one_id'],
        'active' => true,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('departments api allows the same department name for a different branch', function () {
    $scope = seedDepartmentScope();
    createDepartment('sales', ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_one_id']]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/departments', [
        'name' => 'Sales',
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_two_id'],
        'active' => true,
    ])->assertSuccessful();
});

test('departments api rejects duplicate department names', function () {
    createDepartment('sales');

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/departments', [
        'name' => 'Sales',
        'active' => true,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('departments api rejects renaming a department to an existing name', function () {
    createDepartment('sales');
    $hrDepartment = createDepartment('hr');

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->putJson('/api/departments/'.$hrDepartment->id, [
        'name' => ' sales ',
        'active' => true,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('departments check-name api reports when a department name already exists', function () {
    $scope = seedDepartmentScope();
    createDepartment('sales', ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_one_id']]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/departments/check-name', [
        'name' => 'Sales',
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_one_id'],
    ])->assertSuccessful()
        ->assertJson(['name_taken' => true]);

    $this->postJson('/api/departments/check-name', [
        'name' => 'Finance',
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_one_id'],
    ])->assertSuccessful()
        ->assertJson(['name_taken' => false]);
});

test('departments check-name api ignores the current record when editing', function () {
    $scope = seedDepartmentScope();
    $salesDepartment = createDepartment('sales', ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_one_id']]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/departments/check-name', [
        'name' => 'sales',
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_one_id'],
        'except_id' => $salesDepartment->id,
    ])->assertSuccessful()
        ->assertJson(['name_taken' => false]);
});

test('companyadmin must provide branch_id when creating a department', function () {
    $scope = seedDepartmentScope();
    $companyAdminRole = Role::query()->create([
        'name' => 'companyadmin',
        'company_id' => $scope['company_id'],
        'is_active' => true,
        'is_admin' => false,
    ]);
    $companyAdmin = createUserForDepartmentRole($companyAdminRole);
    $companyAdmin->company_id = $scope['company_id'];
    $companyAdmin->branch_id = $scope['branch_one_id'];
    $companyAdmin->save();

    Sanctum::actingAs($companyAdmin);

    $this->postJson('/api/departments', [
        'name' => 'Branch Sales',
        'company_id' => $scope['company_id'],
        'active' => true,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['branch_id']);

    $this->postJson('/api/departments', [
        'name' => 'Branch Sales',
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_two_id'],
        'active' => true,
    ])->assertSuccessful();
});

test('departments index returns company and branch names', function () {
    $scope = seedDepartmentScope();
    createDepartment('sales', ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_one_id']]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/departments');

    $response->assertSuccessful();
    expect($response->json('data.data.0.company_name'))->toBe('Test Company')
        ->and($response->json('data.data.0.branch_name'))->toBe('Branch One');
});
