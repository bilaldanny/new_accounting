<?php

use App\Models\BankIssuer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * @return array{company_id: int}
 */
function seedBankIssuerScope(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'BKI001',
        'name' => 'Bank Issuer Test Company',
        'address' => '1 Finance Street',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'company_id' => $companyId,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validBankIssuerPayload(array $scope, array $overrides = []): array
{
    return array_merge([
        'company_id' => $scope['company_id'],
        'name' => 'Habib Bank Limited',
        'is_active' => true,
    ], $overrides);
}

test('bank issuers api creates a bank issuer with the given fields', function () {
    $scope = seedBankIssuerScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/bank-issuers', validBankIssuerPayload($scope))
        ->assertSuccessful();

    $bankIssuer = BankIssuer::query()->where('company_id', $scope['company_id'])->first();

    expect($bankIssuer)->not->toBeNull()
        ->and($bankIssuer->name)->toBe('Habib Bank Limited')
        ->and($bankIssuer->is_active)->toBeTrue();
});

test('bank issuers api rejects a bank issuer without a name', function () {
    $scope = seedBankIssuerScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/bank-issuers', validBankIssuerPayload($scope, [
        'name' => '',
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('bank issuers index lists bank issuers with company names', function () {
    $scope = seedBankIssuerScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/bank-issuers', validBankIssuerPayload($scope))->assertSuccessful();

    $response = $this->getJson('/api/bank-issuers');

    $response->assertSuccessful();
    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.company_name'))->toBe('Bank Issuer Test Company')
        ->and($response->json('data.data.0.name'))->toBe('Habib Bank Limited');
});

test('bank issuers api updates a bank issuer and can soft delete it', function () {
    $scope = seedBankIssuerScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/bank-issuers', validBankIssuerPayload($scope))->assertSuccessful();

    $bankIssuer = BankIssuer::query()->firstOrFail();

    $this->putJson('/api/bank-issuers/'.$bankIssuer->id, validBankIssuerPayload($scope, [
        'name' => 'United Bank Limited',
    ]))->assertSuccessful();

    $bankIssuer->refresh();

    expect($bankIssuer->name)->toBe('United Bank Limited');

    $this->postJson('/api/bank-issuers/bulk_delete', [$bankIssuer->id])->assertSuccessful();

    expect(BankIssuer::query()->find($bankIssuer->id))->toBeNull()
        ->and(BankIssuer::onlyTrashed()->find($bankIssuer->id))->not->toBeNull();
});

test('bank issuers trash lists soft deleted bank issuers and restore brings them back', function () {
    $scope = seedBankIssuerScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/bank-issuers', validBankIssuerPayload($scope))->assertSuccessful();
    $bankIssuer = BankIssuer::query()->firstOrFail();

    $this->postJson('/api/bank-issuers/bulk_delete', [$bankIssuer->id])->assertSuccessful();

    $this->getJson('/api/bank-issuers/trash')
        ->assertSuccessful()
        ->assertJsonPath('data.data.0.name', 'Habib Bank Limited');

    $this->postJson('/api/bank-issuers/restore_records', [$bankIssuer->id])->assertSuccessful();

    expect(BankIssuer::query()->find($bankIssuer->id))->not->toBeNull();
});

test('bank issuers api permanently deletes a bank issuer from trash', function () {
    $scope = seedBankIssuerScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/bank-issuers', validBankIssuerPayload($scope))->assertSuccessful();
    $bankIssuer = BankIssuer::query()->firstOrFail();

    $this->postJson('/api/bank-issuers/bulk_delete', [$bankIssuer->id])->assertSuccessful();
    $this->postJson('/api/bank-issuers/bulk_delete_per', [$bankIssuer->id])->assertSuccessful();

    expect(BankIssuer::onlyTrashed()->find($bankIssuer->id))->toBeNull();
});

test('bank issuers api toggles status via statusupdate', function () {
    $scope = seedBankIssuerScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/bank-issuers', validBankIssuerPayload($scope))->assertSuccessful();
    $bankIssuer = BankIssuer::query()->firstOrFail();

    $this->postJson('/api/bank-issuers/statusupdate', [
        'ids' => [$bankIssuer->id],
        'status' => 0,
    ])->assertSuccessful();

    expect($bankIssuer->refresh()->is_active)->toBeFalse();
});

test('bank issuers store is forbidden without menu permission', function () {
    $scope = seedBankIssuerScope();

    $role = Role::query()->create([
        'name' => 'companyadmin',
        'company_id' => $scope['company_id'],
        'is_active' => true,
    ]);

    $user = createStaffUserForRole($role, [
        'company_id' => $scope['company_id'],
    ]);

    Sanctum::actingAs($user);

    $this->postJson('/api/bank-issuers', validBankIssuerPayload($scope))
        ->assertForbidden();

    expect(BankIssuer::query()->count())->toBe(0);
});
