<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function seedBranchDestructiveScope(): array
{
    static $counter = 0;
    $counter++;

    $company = Company::query()->create([
        'code' => 'BRD'.str_pad((string) $counter, 3, '0', STR_PAD_LEFT),
        'name' => 'Branch Destructive Co '.$counter,
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 10,
    ]);

    return ['company_id' => $company->id];
}

function branchCompanyAdmin(array $scope, array $permissionPaths = []): User
{
    $role = Role::query()->create([
        'name' => 'companyadmin',
        'company_id' => $scope['company_id'],
        'is_active' => true,
    ]);

    foreach ($permissionPaths as $path) {
        grantMenuPermission((int) $role->id, $path);
    }

    return createStaffUserForRole($role, ['company_id' => $scope['company_id']]);
}

test('branch destroy returns 406 without delete permission', function () {
    $scope = seedBranchDestructiveScope();
    $branch = Branch::query()->create([
        'code' => 'BR-D0001',
        'company_id' => $scope['company_id'],
        'name' => 'Denied Branch',
        'is_active' => true,
    ]);

    $role = Role::query()->create(['name' => 'clerk', 'is_active' => true]);
    $user = createStaffUserForRole($role, ['company_id' => $scope['company_id']]);
    Sanctum::actingAs($user);

    $response = $this->deleteJson('/api/branches/'.$branch->id);

    $response->assertSuccessful();
    expect($response->json())->toBe('406');
    expect(Branch::query()->find($branch->id))->not->toBeNull();
});

test('branch destroy promotes the next branch to default', function () {
    $scope = seedBranchDestructiveScope();

    $default = Branch::query()->create([
        'code' => 'BR-D0002',
        'company_id' => $scope['company_id'],
        'name' => 'Default Branch',
        'is_active' => true,
        'is_default' => true,
    ]);

    $other = Branch::query()->create([
        'code' => 'BR-D0003',
        'company_id' => $scope['company_id'],
        'name' => 'Other Branch',
        'is_active' => true,
        'is_default' => false,
    ]);

    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => $scope['company_id'], 'is_active' => true]);
    grantMenuPermission((int) $role->id, '/branch/delete');
    $user = createStaffUserForRole($role, ['company_id' => $scope['company_id']]);
    Sanctum::actingAs($user);

    $this->deleteJson('/api/branches/'.$default->id)
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Deleted');

    expect(Branch::query()->find($default->id))->toBeNull()
        ->and($other->fresh()->is_default)->toBeTrue();
});

test('branch bulk_delete only removes branches visible to the current company', function () {
    $own = seedBranchDestructiveScope();
    $other = seedBranchDestructiveScope();

    $ownBranch = Branch::query()->create([
        'code' => 'BR-D0004',
        'company_id' => $own['company_id'],
        'name' => 'Own Branch',
        'is_active' => true,
    ]);

    $otherBranch = Branch::query()->create([
        'code' => 'BR-D0005',
        'company_id' => $other['company_id'],
        'name' => 'Other Company Branch',
        'is_active' => true,
    ]);

    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => $own['company_id'], 'is_active' => true]);
    grantMenuPermission((int) $role->id, '/branch/delete');
    $user = createStaffUserForRole($role, ['company_id' => $own['company_id']]);
    Sanctum::actingAs($user);

    $this->postJson('/api/branches/bulk_delete', [$ownBranch->id, $otherBranch->id])
        ->assertSuccessful();

    expect(Branch::query()->find($ownBranch->id))->toBeNull()
        ->and(Branch::query()->find($otherBranch->id))->not->toBeNull();
});

test('branch updatestatus toggles active state and is forbidden without menu permission', function () {
    $scope = seedBranchDestructiveScope();
    $branch = Branch::query()->create([
        'code' => 'BR-D0006',
        'company_id' => $scope['company_id'],
        'name' => 'Status Branch',
        'is_active' => true,
    ]);

    $clerkRole = Role::query()->create(['name' => 'clerk', 'is_active' => true]);
    $clerk = createStaffUserForRole($clerkRole, ['company_id' => $scope['company_id']]);
    Sanctum::actingAs($clerk);

    $this->postJson('/api/branches/statusupdate', ['ids' => [$branch->id]])
        ->assertForbidden();

    expect($branch->fresh()->is_active)->toBeTrue();

    Sanctum::actingAs(branchCompanyAdmin($scope, ['/branch/:id/edit']));

    $this->postJson('/api/branches/statusupdate', ['ids' => [$branch->id]])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Saved');

    expect($branch->fresh()->is_active)->toBeFalse();
});

test('branch duplicate creates a copy with a new code and returns 404 for a missing branch', function () {
    $scope = seedBranchDestructiveScope();
    $branch = Branch::query()->create([
        'code' => 'BR-D0007',
        'company_id' => $scope['company_id'],
        'name' => 'Source Branch',
        'is_active' => true,
        'is_default' => true,
    ]);

    Sanctum::actingAs(branchCompanyAdmin($scope, ['/branch/add']));

    $this->postJson('/api/branches/duplicate', ['id' => $branch->id])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Duplicated');

    $duplicate = Branch::query()->where('name', 'Source Branch Copy')->first();

    expect($duplicate)->not->toBeNull()
        ->and($duplicate->code)->not->toBe($branch->code)
        ->and($duplicate->is_default)->toBeFalse();

    $this->postJson('/api/branches/duplicate', ['id' => 999999])
        ->assertNotFound();
});

test('branch trash lists only soft-deleted branches for the current company', function () {
    $own = seedBranchDestructiveScope();
    $other = seedBranchDestructiveScope();

    $ownDeleted = Branch::query()->create([
        'code' => 'BR-D0008',
        'company_id' => $own['company_id'],
        'name' => 'Deleted Own Branch',
        'is_active' => true,
    ]);
    $ownDeleted->delete();

    $otherDeleted = Branch::query()->create([
        'code' => 'BR-D0009',
        'company_id' => $other['company_id'],
        'name' => 'Deleted Other Branch',
        'is_active' => true,
    ]);
    $otherDeleted->delete();

    Sanctum::actingAs(branchCompanyAdmin($own));

    $response = $this->getJson('/api/branches/trash');

    $response->assertSuccessful();
    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.name'))->toBe('Deleted Own Branch');
});
