<?php

use App\Models\Consumer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * @return array{company_id: int, branch_id: int}
 */
function seedConsumerScope(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'CON001',
        'name' => 'Consumer Test Company',
        'address' => '1 Retail Street',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $branchId = DB::table('branches')->insertGetId([
        'code' => 'CNB001',
        'company_id' => $companyId,
        'name' => 'Consumer Branch',
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
function validConsumerPayload(array $scope, array $overrides = []): array
{
    return array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'name' => 'Walk-in Buyer',
        'consumer_type' => 'individual',
        'contact_person' => 'Ahmed Khan',
        'phone_res' => '+92 42 1112222',
        'phone_off' => '+92 42 3334444',
        'fax_no' => '+92 42 5556666',
        'email' => 'buyer@example.com',
        'ntn_no' => 'NTN-123456',
        'cnic_no' => '35202-1234567-1',
        'sales_tax_no' => 'STN-987654',
        'city' => 'Lahore',
        'address' => '12 Mall Road',
        'store_address' => '14 Mall Road',
        'is_active' => true,
    ], $overrides);
}

test('consumers api creates a consumer with the given fields', function () {
    $scope = seedConsumerScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/consumers', validConsumerPayload($scope))
        ->assertSuccessful();

    $consumer = Consumer::query()->where('company_id', $scope['company_id'])->first();

    expect($consumer)->not->toBeNull()
        ->and($consumer->branch_id)->toBe($scope['branch_id'])
        ->and($consumer->name)->toBe('Walk-in Buyer')
        ->and($consumer->consumer_type)->toBe('individual')
        ->and($consumer->contact_person)->toBe('Ahmed Khan')
        ->and($consumer->ntn_no)->toBe('NTN-123456')
        ->and($consumer->cnic_no)->toBe('35202-1234567-1')
        ->and($consumer->sales_tax_no)->toBe('STN-987654')
        ->and($consumer->city)->toBe('Lahore')
        ->and($consumer->is_active)->toBeTrue();
});

test('consumers api rejects a consumer without a name or branch', function () {
    $scope = seedConsumerScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/consumers', validConsumerPayload($scope, [
        'name' => '',
        'branch_id' => null,
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'branch_id']);
});

test('consumers api rejects an invalid consumer type', function () {
    $scope = seedConsumerScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/consumers', validConsumerPayload($scope, [
        'consumer_type' => 'not-a-real-type',
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['consumer_type']);
});

test('consumers index lists consumers with company and branch names', function () {
    $scope = seedConsumerScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/consumers', validConsumerPayload($scope))->assertSuccessful();

    $response = $this->getJson('/api/consumers');

    $response->assertSuccessful();
    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.company_name'))->toBe('Consumer Test Company')
        ->and($response->json('data.data.0.branch_name'))->toBe('Consumer Branch')
        ->and($response->json('data.data.0.name'))->toBe('Walk-in Buyer');
});

test('consumers api updates a consumer and can soft delete it', function () {
    $scope = seedConsumerScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/consumers', validConsumerPayload($scope))->assertSuccessful();

    $consumer = Consumer::query()->firstOrFail();

    $this->putJson('/api/consumers/'.$consumer->id, validConsumerPayload($scope, [
        'name' => 'Updated Buyer Name',
    ]))->assertSuccessful();

    $consumer->refresh();

    expect($consumer->name)->toBe('Updated Buyer Name');

    $this->postJson('/api/consumers/bulk_delete', [$consumer->id])->assertSuccessful();

    expect(Consumer::query()->find($consumer->id))->toBeNull()
        ->and(Consumer::onlyTrashed()->find($consumer->id))->not->toBeNull();
});

test('consumers trash lists soft deleted consumers and restore brings them back', function () {
    $scope = seedConsumerScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/consumers', validConsumerPayload($scope))->assertSuccessful();
    $consumer = Consumer::query()->firstOrFail();

    $this->postJson('/api/consumers/bulk_delete', [$consumer->id])->assertSuccessful();

    $this->getJson('/api/consumers/trash')
        ->assertSuccessful()
        ->assertJsonPath('data.data.0.name', 'Walk-in Buyer');

    $this->postJson('/api/consumers/restore_records', [$consumer->id])->assertSuccessful();

    expect(Consumer::query()->find($consumer->id))->not->toBeNull();
});

test('consumers api permanently deletes a consumer from trash', function () {
    $scope = seedConsumerScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/consumers', validConsumerPayload($scope))->assertSuccessful();
    $consumer = Consumer::query()->firstOrFail();

    $this->postJson('/api/consumers/bulk_delete', [$consumer->id])->assertSuccessful();
    $this->postJson('/api/consumers/bulk_delete_per', [$consumer->id])->assertSuccessful();

    expect(Consumer::onlyTrashed()->find($consumer->id))->toBeNull();
});

test('consumers api toggles status via statusupdate', function () {
    $scope = seedConsumerScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/consumers', validConsumerPayload($scope))->assertSuccessful();
    $consumer = Consumer::query()->firstOrFail();

    $this->postJson('/api/consumers/statusupdate', [
        'ids' => [$consumer->id],
        'status' => 0,
    ])->assertSuccessful();

    expect($consumer->refresh()->is_active)->toBeFalse();
});

test('consumers store is forbidden without menu permission', function () {
    $scope = seedConsumerScope();

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

    $this->postJson('/api/consumers', validConsumerPayload($scope))
        ->assertForbidden();

    expect(Consumer::query()->count())->toBe(0);
});
