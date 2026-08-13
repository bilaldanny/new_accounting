<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

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

function seedPermissionScope(): array
{
    $scope = seedCompanyAndBranches();

    $departmentId = DB::table('departments')->insertGetId([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_one_id'],
        'name' => 'Sales',
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $menuId = DB::table('menus')->insertGetId([
        'parent_id' => null,
        'name' => 'Dashboard',
        'icon' => 'bx-home',
        'route_name' => 'dashboard',
        'route_path' => '/dashboard',
        'menu_color' => '#000000',
        'sort_order' => 1,
        'is_hidden' => 0,
        'is_active' => 1,
        'is_permission' => 0,
        'type' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $targetRole = Role::query()->create([
        'name' => 'manager',
        'is_active' => true,
        'is_admin' => false,
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_one_id'],
    ]);

    return [
        ...$scope,
        'department_id' => $departmentId,
        'menu_id' => $menuId,
        'role_id' => $targetRole->id,
    ];
}

test('fetchpermissions returns menu ids for role without scope filters', function () {
    $scope = seedPermissionScope();

    DB::table('permissions')->insert([
        'company_id' => null,
        'branch_id' => null,
        'department_id' => null,
        'role_id' => $scope['role_id'],
        'menu_id' => $scope['menu_id'],
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/fetchpermissions?role_id='.$scope['role_id']);

    $response->assertSuccessful();
    expect($response->json())->toBe([$scope['menu_id']]);
});

test('fetchdepartments returns active departments for company and branch', function () {
    $scope = seedPermissionScope();

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/fetchdepartments?company_id='.$scope['company_id'].'&branch_id='.$scope['branch_one_id']);

    $response->assertSuccessful();
    expect(collect($response->json())->pluck('name'))->toContain('Sales');
});

test('fetchpermissions returns menu ids scoped by company branch department and role', function () {
    $scope = seedPermissionScope();

    DB::table('permissions')->insert([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_one_id'],
        'department_id' => $scope['department_id'],
        'role_id' => $scope['role_id'],
        'menu_id' => $scope['menu_id'],
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('permissions')->insert([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_two_id'],
        'department_id' => $scope['department_id'],
        'role_id' => $scope['role_id'],
        'menu_id' => $scope['menu_id'],
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/fetchpermissions?company_id='.$scope['company_id']
        .'&branch_id='.$scope['branch_one_id']
        .'&department_id='.$scope['department_id']
        .'&role_id='.$scope['role_id']);

    $response->assertSuccessful();
    expect($response->json())->toBe([$scope['menu_id']]);
});

test('permissions store saves scoped permission records', function () {
    $scope = seedPermissionScope();

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->postJson('/api/permissions', [
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_one_id'],
        'department_id' => $scope['department_id'],
        'role_id' => $scope['role_id'],
        'menuid' => $scope['menu_id'],
        'status' => 1,
    ]);

    $response->assertSuccessful();

    expect(Permission::query()->where([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_one_id'],
        'department_id' => $scope['department_id'],
        'role_id' => $scope['role_id'],
        'menu_id' => $scope['menu_id'],
        'status' => 1,
    ])->exists())->toBeTrue();
});

test('fetchpermenus returns permission menus', function () {
    $scope = seedPermissionScope();

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/fetchpermenus');

    $response->assertSuccessful();
    expect(collect($response->json())->pluck('name'))->toContain('Dashboard');
});
