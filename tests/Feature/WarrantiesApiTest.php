<?php

use App\Models\User;
use App\Models\Warranty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function createWarranty(string $name, array $attributes = []): Warranty
{
    return Warranty::query()->create(array_merge([
        'name' => $name,
        'duration' => '12',
        'type' => 'month',
        'active' => true,
    ], $attributes));
}

function seedWarrantyScope(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'WRTC001',
        'name' => 'Warranty Test Company',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'company_id' => $companyId,
    ];
}

function validWarrantyPayload(array $scope, array $overrides = []): array
{
    return array_merge([
        'name' => 'Standard Warranty',
        'duration' => 12,
        'type' => 'month',
        'company_id' => $scope['company_id'],
        'active' => true,
    ], $overrides);
}

test('warranties api creates a warranty with required fields', function () {
    $scope = seedWarrantyScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/warranties', validWarrantyPayload($scope))
        ->assertSuccessful();

    $this->assertDatabaseHas('warranties', [
        'name' => 'Standard Warranty',
        'duration' => '12',
        'type' => 'month',
        'company_id' => $scope['company_id'],
    ]);
});

test('warranties api rejects duplicate names within the same company', function () {
    $scope = seedWarrantyScope();
    createWarranty('standard warranty', ['company_id' => $scope['company_id']]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/warranties', validWarrantyPayload($scope, [
        'name' => 'Standard  Warranty',
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('warranties check-name api reports when a name already exists', function () {
    $scope = seedWarrantyScope();
    createWarranty('standard warranty', ['company_id' => $scope['company_id']]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/warranties/check-name', [
        'name' => 'Standard Warranty',
        'company_id' => $scope['company_id'],
    ])->assertSuccessful()
        ->assertJson(['name_taken' => true]);
});

test('warranties fetch api returns active warranties for a company', function () {
    $scope = seedWarrantyScope();
    $activeWarranty = createWarranty('standard warranty', [
        'company_id' => $scope['company_id'],
        'active' => true,
    ]);
    createWarranty('extended warranty', [
        'company_id' => $scope['company_id'],
        'active' => false,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/fetchwarranties?company_id='.$scope['company_id']);

    $response->assertSuccessful();
    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.id'))->toBe($activeWarranty->id)
        ->and($response->json('0.text'))->toBe('standard warranty');
});

test('warranties index returns company name and duration label', function () {
    $scope = seedWarrantyScope();
    createWarranty('standard warranty', [
        'company_id' => $scope['company_id'],
        'duration' => '2',
        'type' => 'year',
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/warranties');

    $response->assertSuccessful();
    expect($response->json('data.data.0.company_name'))->toBe('Warranty Test Company')
        ->and($response->json('data.data.0.duration_label'))->toBe('2 Years');
});

test('warranties api requires duration and type', function () {
    $scope = seedWarrantyScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/warranties', validWarrantyPayload($scope, [
        'duration' => '',
        'type' => '',
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['duration', 'type']);
});
