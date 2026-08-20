<?php

use App\Models\CustomerGroup;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function createCustomerGroup(string $name, array $attributes = []): CustomerGroup
{
    return CustomerGroup::query()->create(array_merge([
        'name' => $name,
        'price_calculation_type' => 'percentage',
        'calculation_percentage' => 10,
        'active' => true,
    ], $attributes));
}

function seedCustomerGroupScope(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'CGC001',
        'name' => 'Customer Group Company',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $branchOneId = DB::table('branches')->insertGetId([
        'code' => 'CGB001',
        'company_id' => $companyId,
        'name' => 'Branch One',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $branchTwoId = DB::table('branches')->insertGetId([
        'code' => 'CGB002',
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

function validCustomerGroupPayload(array $scope, array $overrides = []): array
{
    return array_merge([
        'name' => 'Retail Customers',
        'price_calculation_type' => 'percentage',
        'calculation_percentage' => 5,
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_one_id'],
        'active' => true,
    ], $overrides);
}

function createUserForCustomerGroupRole(Role $role): User
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

test('customer groups api creates a customer group with required fields', function () {
    $scope = seedCustomerGroupScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/customer-groups', validCustomerGroupPayload($scope))
        ->assertSuccessful();

    $this->assertDatabaseHas('customer_groups', [
        'name' => 'Retail Customers',
        'price_calculation_type' => 'percentage',
        'calculation_percentage' => 5,
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_one_id'],
    ]);
});

test('customer groups api rejects duplicate names within the same company and branch', function () {
    $scope = seedCustomerGroupScope();
    createCustomerGroup('wholesale', ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_one_id']]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/customer-groups', validCustomerGroupPayload($scope, [
        'name' => 'Wholesale',
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('customer groups api allows the same name for a different branch', function () {
    $scope = seedCustomerGroupScope();
    createCustomerGroup('wholesale', ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_one_id']]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/customer-groups', validCustomerGroupPayload($scope, [
        'name' => 'Wholesale',
        'branch_id' => $scope['branch_two_id'],
    ]))->assertSuccessful();
});

test('customer groups check-name api reports when a name already exists', function () {
    $scope = seedCustomerGroupScope();
    createCustomerGroup('wholesale', ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_one_id']]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/customer-groups/check-name', [
        'name' => 'Wholesale',
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_one_id'],
    ])->assertSuccessful()
        ->assertJson(['name_taken' => true]);
});

test('customer groups fetch api returns active groups for company and branch', function () {
    $scope = seedCustomerGroupScope();
    $activeGroup = createCustomerGroup('active group', [
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_one_id'],
        'active' => true,
    ]);
    createCustomerGroup('inactive group', [
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_one_id'],
        'active' => false,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/fetchcustomergroups?company_id='.$scope['company_id'].'&branch_id='.$scope['branch_one_id']);

    $response->assertSuccessful();
    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.id'))->toBe($activeGroup->id)
        ->and($response->json('0.text'))->toBe('active group');
});

test('customer groups index returns company and branch names', function () {
    $scope = seedCustomerGroupScope();
    createCustomerGroup('retail', ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_one_id']]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/customer-groups');

    $response->assertSuccessful();
    expect($response->json('data.data.0.company_name'))->toBe('Customer Group Company')
        ->and($response->json('data.data.0.branch_name'))->toBe('Branch One');
});

test('companyadmin must provide branch_id when creating a customer group', function () {
    $scope = seedCustomerGroupScope();
    $companyAdminRole = Role::query()->create([
        'name' => 'companyadmin',
        'company_id' => $scope['company_id'],
        'is_active' => true,
        'is_admin' => false,
    ]);
    $companyAdmin = createUserForCustomerGroupRole($companyAdminRole);
    $companyAdmin->company_id = $scope['company_id'];
    $companyAdmin->branch_id = $scope['branch_one_id'];
    $companyAdmin->save();

    Sanctum::actingAs($companyAdmin);

    $this->postJson('/api/customer-groups', validCustomerGroupPayload($scope, [
        'branch_id' => null,
    ]))->assertStatus(422)
        ->assertJsonValidationErrors(['branch_id']);
});
