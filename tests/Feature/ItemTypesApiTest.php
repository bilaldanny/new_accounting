<?php

use App\Models\ItemType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function createItemType(string $name, array $attributes = []): ItemType
{
    return ItemType::query()->create(array_merge([
        'name' => $name,
        'active' => true,
    ], $attributes));
}

function seedItemTypeScope(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'ITMT001',
        'name' => 'Item Type Test Company',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'company_id' => $companyId,
    ];
}

function validItemTypePayload(array $scope, array $overrides = []): array
{
    return array_merge([
        'name' => 'Finished Goods',
        'company_id' => $scope['company_id'],
        'active' => true,
    ], $overrides);
}

test('item types api creates an item type with required fields', function () {
    $scope = seedItemTypeScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/item-types', validItemTypePayload($scope))
        ->assertSuccessful();

    $this->assertDatabaseHas('item_types', [
        'name' => 'Finished Goods',
        'company_id' => $scope['company_id'],
    ]);
});

test('item types api rejects duplicate names within the same company', function () {
    $scope = seedItemTypeScope();
    createItemType('finished goods', ['company_id' => $scope['company_id']]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/item-types', validItemTypePayload($scope, [
        'name' => 'Finished Goods',
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('item types check-name api reports when a name already exists', function () {
    $scope = seedItemTypeScope();
    createItemType('finished goods', ['company_id' => $scope['company_id']]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/item-types/check-name', [
        'name' => 'Finished Goods',
        'company_id' => $scope['company_id'],
    ])->assertSuccessful()
        ->assertJson(['name_taken' => true]);
});

test('item types fetch api returns active item types for a company', function () {
    $scope = seedItemTypeScope();
    $activeItemType = createItemType('finished goods', [
        'company_id' => $scope['company_id'],
        'active' => true,
    ]);
    createItemType('raw material', [
        'company_id' => $scope['company_id'],
        'active' => false,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/fetchitemtypes?company_id='.$scope['company_id']);

    $response->assertSuccessful();
    expect($response->json())->toHaveCount(1)
        ->and($response->json('0.id'))->toBe($activeItemType->id)
        ->and($response->json('0.text'))->toBe('finished goods');
});

test('item types index returns company name', function () {
    $scope = seedItemTypeScope();
    createItemType('finished goods', ['company_id' => $scope['company_id']]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/item-types');

    $response->assertSuccessful();
    expect($response->json('data.data.0.company_name'))->toBe('Item Type Test Company')
        ->and($response->json('data.data.0.name'))->toBe('finished goods');
});

test('item types api soft deletes an item type', function () {
    $scope = seedItemTypeScope();
    $itemType = createItemType('finished goods', ['company_id' => $scope['company_id']]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/item-types/bulk_delete', [$itemType->id])
        ->assertSuccessful();

    $this->assertSoftDeleted('item_types', ['id' => $itemType->id]);
});
