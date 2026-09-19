<?php

use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * @return array{company_id: int, branch_id: int}
 */
function seedWarehouseScope(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'WH001',
        'name' => 'Warehouse Test Company',
        'address' => '1 Storage Street',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $branchId = DB::table('branches')->insertGetId([
        'code' => 'WHB001',
        'company_id' => $companyId,
        'name' => 'Warehouse Branch',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'company_id' => $companyId,
        'branch_id' => $branchId,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validWarehousePayload(array $scope, array $overrides = []): array
{
    return array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'name' => 'Main Distribution Warehouse',
        'address' => '221B Industrial Estate',
        'zipcode' => '54000',
        'phone' => '+92 300 1234567',
        'fax' => '+92 42 111222333',
        'is_active' => true,
    ], $overrides);
}

test('warehouses api creates a warehouse with the given fields', function () {
    $scope = seedWarehouseScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/warehouses', validWarehousePayload($scope))
        ->assertSuccessful();

    $warehouse = Warehouse::query()->where('company_id', $scope['company_id'])->first();

    expect($warehouse)->not->toBeNull()
        ->and($warehouse->branch_id)->toBe($scope['branch_id'])
        ->and($warehouse->name)->toBe('Main Distribution Warehouse')
        ->and($warehouse->address)->toBe('221B Industrial Estate')
        ->and($warehouse->zipcode)->toBe('54000')
        ->and($warehouse->phone)->toBe('+92 300 1234567')
        ->and($warehouse->fax)->toBe('+92 42 111222333')
        ->and($warehouse->is_active)->toBeTrue();
});

test('warehouses api rejects a warehouse without a name or branch', function () {
    $scope = seedWarehouseScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/warehouses', validWarehousePayload($scope, [
        'name' => '',
        'branch_id' => null,
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'branch_id']);
});

test('warehouses index lists warehouses with company and branch names', function () {
    $scope = seedWarehouseScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/warehouses', validWarehousePayload($scope))->assertSuccessful();

    $response = $this->getJson('/api/warehouses');

    $response->assertSuccessful();
    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.company_name'))->toBe('Warehouse Test Company')
        ->and($response->json('data.data.0.branch_name'))->toBe('Warehouse Branch')
        ->and($response->json('data.data.0.name'))->toBe('Main Distribution Warehouse');
});

test('warehouses api updates a warehouse and can soft delete it', function () {
    $scope = seedWarehouseScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/warehouses', validWarehousePayload($scope))->assertSuccessful();

    $warehouse = Warehouse::query()->firstOrFail();

    $this->putJson('/api/warehouses/'.$warehouse->id, validWarehousePayload($scope, [
        'name' => 'Updated Warehouse Name',
    ]))->assertSuccessful();

    $warehouse->refresh();

    expect($warehouse->name)->toBe('Updated Warehouse Name');

    $this->postJson('/api/warehouses/bulk_delete', [$warehouse->id])->assertSuccessful();

    expect(Warehouse::query()->find($warehouse->id))->toBeNull()
        ->and(Warehouse::onlyTrashed()->find($warehouse->id))->not->toBeNull();
});

test('warehouses trash lists soft deleted warehouses and restore brings them back', function () {
    $scope = seedWarehouseScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/warehouses', validWarehousePayload($scope))->assertSuccessful();
    $warehouse = Warehouse::query()->firstOrFail();

    $this->postJson('/api/warehouses/bulk_delete', [$warehouse->id])->assertSuccessful();

    $this->getJson('/api/warehouses/trash')
        ->assertSuccessful()
        ->assertJsonPath('data.data.0.name', 'Main Distribution Warehouse');

    $this->postJson('/api/warehouses/restore_records', [$warehouse->id])->assertSuccessful();

    expect(Warehouse::query()->find($warehouse->id))->not->toBeNull();
});

test('warehouses api permanently deletes a warehouse from trash', function () {
    $scope = seedWarehouseScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/warehouses', validWarehousePayload($scope))->assertSuccessful();
    $warehouse = Warehouse::query()->firstOrFail();

    $this->postJson('/api/warehouses/bulk_delete', [$warehouse->id])->assertSuccessful();
    $this->postJson('/api/warehouses/bulk_delete_per', [$warehouse->id])->assertSuccessful();

    expect(Warehouse::onlyTrashed()->find($warehouse->id))->toBeNull();
});

test('warehouses api toggles status via statusupdate', function () {
    $scope = seedWarehouseScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/warehouses', validWarehousePayload($scope))->assertSuccessful();
    $warehouse = Warehouse::query()->firstOrFail();

    $this->postJson('/api/warehouses/statusupdate', [
        'ids' => [$warehouse->id],
        'status' => 0,
    ])->assertSuccessful();

    expect($warehouse->refresh()->is_active)->toBeFalse();
});

test('warehouses store is forbidden without menu permission', function () {
    $scope = seedWarehouseScope();

    $role = Role::query()->create([
        'name' => 'companyadmin',
        'company_id' => $scope['company_id'],
        'is_active' => true,
    ]);

    $user = createStaffUserForRole($role, [
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
    ]);

    Sanctum::actingAs($user);

    $this->postJson('/api/warehouses', validWarehousePayload($scope))
        ->assertForbidden();

    expect(Warehouse::query()->count())->toBe(0);
});
