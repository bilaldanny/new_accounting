<?php

use App\Models\Brand;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function seedMenuPermissionBrandCompany(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'PERM001',
        'name' => 'Permission Test Company',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['company_id' => $companyId];
}

test('api delete without a sidebar delete path returns 406 for non-superadmin', function () {
    $scope = seedMenuPermissionBrandCompany();
    $brand = Brand::query()->create([
        'name' => 'Denied Brand',
        'company_id' => $scope['company_id'],
        'active' => true,
    ]);

    $role = Role::query()->create([
        'name' => 'clerk',
        'is_active' => true,
    ]);
    $user = createStaffUserForRole($role, [
        'company_id' => $scope['company_id'],
    ]);

    Sanctum::actingAs($user);

    $response = $this->deleteJson('/api/brands/'.$brand->id);

    $response->assertSuccessful();
    expect($response->json())->toBe('406');
    $this->assertDatabaseHas('brands', ['id' => $brand->id, 'deleted_at' => null]);
});

test('api delete succeeds for a non-superadmin who has the sidebar /brand/delete path', function () {
    $scope = seedMenuPermissionBrandCompany();
    $brand = Brand::query()->create([
        'name' => 'Permitted Brand',
        'company_id' => $scope['company_id'],
        'active' => true,
    ]);

    $role = Role::query()->create([
        'name' => 'clerk',
        'is_active' => true,
    ]);
    grantMenuPermission((int) $role->id, '/brand/delete');
    $user = createStaffUserForRole($role, [
        'company_id' => $scope['company_id'],
    ]);

    Sanctum::actingAs($user);

    $this->deleteJson('/api/brands/'.$brand->id)
        ->assertSuccessful()
        ->assertJson(['message' => 'Successfully Deleted']);

    $this->assertSoftDeleted('brands', ['id' => $brand->id]);
});

test('api store is forbidden without the sidebar /brand/add path', function () {
    $scope = seedMenuPermissionBrandCompany();
    $role = Role::query()->create([
        'name' => 'clerk',
        'is_active' => true,
    ]);
    $user = createStaffUserForRole($role, [
        'company_id' => $scope['company_id'],
    ]);

    Sanctum::actingAs($user);

    $this->postJson('/api/brands', [
        'name' => 'Samsung',
        'company_id' => $scope['company_id'],
        'active' => true,
    ])->assertForbidden();
});

test('purchase approve is forbidden without the sidebar /purchase/approval path', function () {
    $scope = seedPurchaseScope();
    $purchase = createPurchaseRecord($scope);

    $role = Role::query()->create([
        'name' => 'clerk',
        'is_active' => true,
    ]);
    $user = createStaffUserForRole($role, [
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
    ]);

    Sanctum::actingAs($user);

    $this->postJson('/api/purchase-approvals/'.$purchase->id.'/approve')
        ->assertForbidden();
});

test('purchase approve succeeds when the role has /purchase/approval', function () {
    $scope = seedPurchaseScope();
    $purchase = createPurchaseRecord($scope);

    $role = Role::query()->create([
        'name' => 'approver',
        'is_active' => true,
    ]);
    grantMenuPermission((int) $role->id, '/purchase/approval', 'purchase.approval');
    $user = createStaffUserForRole($role, [
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
    ]);

    Sanctum::actingAs($user);

    $this->postJson('/api/purchase-approvals/'.$purchase->id.'/approve')
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Approved');
});
