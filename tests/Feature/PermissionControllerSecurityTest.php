<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Confirmed in the 2026-09-22 audit and fixed here: `PermissionController` checked only whether the ACTOR's own
 * role may manage a given menu (Menu::assignerCanManageMenu), never whether the TARGET role_id the request wants
 * to change belongs to the actor's own company — so any user holding the general `/role/:id/permission` menu
 * permission could grant/revoke/toggle/read permissions for a role in a completely different company.
 * `fetch()` additionally had no authorization gate at all.
 */
function pcsCompanyWithGrantedActor(string $grantedPath = '/role/:id/permission'): array
{
    $companyId = DB::table('companies')->insertGetId(['code' => 'PCS-'.uniqid(), 'name' => 'Actor Co', 'address' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    $actorRole = Role::query()->create(['name' => 'actorrole'.uniqid(), 'company_id' => $companyId, 'is_active' => true]);
    grantMenuPermission($actorRole->id, $grantedPath, 'roleid-permission-'.uniqid());

    $actor = createStaffUserForRole($actorRole, ['company_id' => $companyId]);

    return ['company_id' => $companyId, 'actor' => $actor, 'actor_role_id' => $actorRole->id];
}

function pcsForeignRole(): array
{
    $foreignCompanyId = DB::table('companies')->insertGetId(['code' => 'PCS-FOREIGN-'.uniqid(), 'name' => 'Foreign Co', 'address' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $foreignRole = Role::query()->create(['name' => 'foreignrole'.uniqid(), 'company_id' => $foreignCompanyId, 'is_active' => true]);

    return ['company_id' => $foreignCompanyId, 'role_id' => $foreignRole->id];
}

function pcsMenu(string $routePath = '/dashboard'): int
{
    return DB::table('menus')->insertGetId([
        'parent_id' => null, 'name' => $routePath, 'icon' => 'Grid', 'route_name' => ltrim($routePath, '/').uniqid(),
        'route_path' => $routePath, 'menu_color' => '#000000', 'sort_order' => 1, 'is_hidden' => 0, 'is_active' => 1,
        'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

// grants the actor's role permission on this exact menu id (needed for Menu::assignerCanManageMenu, which is
// keyed by menu id, unlike grantMenuPermission() which creates its own new menu row for a route path)
function pcsGrantMenuIdPermission(int $roleId, int $menuId): void
{
    Permission::query()->create(['role_id' => $roleId, 'menu_id' => $menuId, 'status' => 1]);
    Cache::forget("user_permission_paths:{$roleId}");
    Cache::forget("user_menu_permissions:{$roleId}");
    Cache::forget("user_menu_permissions_tree:{$roleId}");
}

test('permissions store refuses to grant a permission on a role from another company', function () {
    $actorScope = pcsCompanyWithGrantedActor();
    $foreign = pcsForeignRole();
    $menuId = pcsMenu();
    // the actor is deliberately given permission to manage this exact menu, so the only thing that can still
    // block the request is the role-ownership check under test (not the pre-existing menu-tree gate)
    pcsGrantMenuIdPermission($actorScope['actor_role_id'], $menuId);
    Sanctum::actingAs($actorScope['actor']);

    $response = $this->postJson('/api/permissions', [
        'role_id' => $foreign['role_id'],
        'menuid' => $menuId,
        'status' => 1,
    ]);

    $response->assertForbidden();
    expect(Permission::query()->where('role_id', $foreign['role_id'])->where('menu_id', $menuId)->exists())->toBeFalse();
});

test('permissions store still works for a role in the actor\'s own company', function () {
    $actorScope = pcsCompanyWithGrantedActor();
    $ownRole = Role::query()->create(['name' => 'ownrole'.uniqid(), 'company_id' => $actorScope['company_id'], 'is_active' => true]);
    $menuId = pcsMenu();
    pcsGrantMenuIdPermission($actorScope['actor_role_id'], $menuId);
    Sanctum::actingAs($actorScope['actor']);

    $response = $this->postJson('/api/permissions', [
        'role_id' => $ownRole->id,
        'menuid' => $menuId,
        'status' => 1,
    ]);

    $response->assertSuccessful();
    expect(Permission::query()->where('role_id', $ownRole->id)->where('menu_id', $menuId)->where('status', 1)->exists())->toBeTrue();
});

test('permissions store still lets a superadmin grant permissions on any company\'s role', function () {
    $foreign = pcsForeignRole();
    $menuId = pcsMenu();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->postJson('/api/permissions', [
        'role_id' => $foreign['role_id'],
        'menuid' => $menuId,
        'status' => 1,
    ]);

    $response->assertSuccessful();
    expect(Permission::query()->where('role_id', $foreign['role_id'])->where('menu_id', $menuId)->where('status', 1)->exists())->toBeTrue();
});

test('fetchpermissions requires the /role/:id/permission menu permission', function () {
    $companyId = DB::table('companies')->insertGetId(['code' => 'PCS-NOPERM-'.uniqid(), 'name' => 'No Perm Co', 'address' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $role = Role::query()->create(['name' => 'noperm'.uniqid(), 'company_id' => $companyId, 'is_active' => true]);
    $user = createStaffUserForRole($role, ['company_id' => $companyId]);
    Sanctum::actingAs($user);

    $this->getJson('/api/fetchpermissions?role_id='.$role->id)->assertForbidden();
});

test('fetchpermissions refuses to read another company\'s role permissions', function () {
    $actorScope = pcsCompanyWithGrantedActor();
    $foreign = pcsForeignRole();
    $menuId = pcsMenu();
    DB::table('permissions')->insert(['role_id' => $foreign['role_id'], 'menu_id' => $menuId, 'status' => 1, 'company_id' => null, 'branch_id' => null, 'department_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    Sanctum::actingAs($actorScope['actor']);

    $this->getJson('/api/fetchpermissions?role_id='.$foreign['role_id'])->assertForbidden();
});

test('fetchpermissions still works for a role in the actor\'s own company', function () {
    $actorScope = pcsCompanyWithGrantedActor();
    $ownRole = Role::query()->create(['name' => 'ownrole'.uniqid(), 'company_id' => $actorScope['company_id'], 'is_active' => true]);
    $menuId = pcsMenu();
    DB::table('permissions')->insert(['role_id' => $ownRole->id, 'menu_id' => $menuId, 'status' => 1, 'company_id' => null, 'branch_id' => null, 'department_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    Sanctum::actingAs($actorScope['actor']);

    $response = $this->getJson('/api/fetchpermissions?role_id='.$ownRole->id);

    $response->assertSuccessful();
    expect($response->json())->toBe([$menuId]);
});

test('permissions statusupdate cannot toggle a permission row belonging to another company\'s role', function () {
    $actorScope = pcsCompanyWithGrantedActor();
    $foreign = pcsForeignRole();
    $menuId = pcsMenu();
    $permissionId = DB::table('permissions')->insertGetId(['role_id' => $foreign['role_id'], 'menu_id' => $menuId, 'status' => 0, 'company_id' => null, 'branch_id' => null, 'department_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    Sanctum::actingAs($actorScope['actor']);

    $this->postJson('/api/permissions/statusupdate', ['ids' => [$permissionId], 'status' => 1])->assertSuccessful();

    expect(Permission::query()->find($permissionId)->status)->toBe(0);
});

test('permissions statusupdate still toggles a permission row belonging to the actor\'s own company', function () {
    $actorScope = pcsCompanyWithGrantedActor();
    $ownRole = Role::query()->create(['name' => 'ownrole'.uniqid(), 'company_id' => $actorScope['company_id'], 'is_active' => true]);
    $menuId = pcsMenu();
    $permissionId = DB::table('permissions')->insertGetId(['role_id' => $ownRole->id, 'menu_id' => $menuId, 'status' => 0, 'company_id' => null, 'branch_id' => null, 'department_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    Sanctum::actingAs($actorScope['actor']);

    $this->postJson('/api/permissions/statusupdate', ['ids' => [$permissionId], 'status' => 1])->assertSuccessful();

    expect(Permission::query()->find($permissionId)->status)->toBe(1);
});

test('saving a permission flushes all three of the role\'s permission-cache keys', function () {
    $actorScope = pcsCompanyWithGrantedActor();
    $ownRole = Role::query()->create(['name' => 'ownrole'.uniqid(), 'company_id' => $actorScope['company_id'], 'is_active' => true]);
    $menuId = pcsMenu();
    pcsGrantMenuIdPermission($actorScope['actor_role_id'], $menuId);
    Cache::put("user_menu_permissions_tree:{$ownRole->id}", ['stale-tree'], now()->addMinutes(15));
    Cache::put("user_permission_paths:{$ownRole->id}", ['stale-paths'], now()->addMinutes(15));
    Sanctum::actingAs($actorScope['actor']);

    $this->postJson('/api/permissions', ['role_id' => $ownRole->id, 'menuid' => $menuId, 'status' => 1])->assertSuccessful();

    expect(Cache::has("user_menu_permissions_tree:{$ownRole->id}"))->toBeFalse()
        ->and(Cache::has("user_permission_paths:{$ownRole->id}"))->toBeFalse();
});
