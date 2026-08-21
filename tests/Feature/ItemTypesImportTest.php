<?php

use App\Models\ItemType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function seedItemTypeImportScope(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'ITPM001',
        'name' => 'Item Type Import Company',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'company_id' => $companyId,
    ];
}

function createItemTypeForImport(array $scope, array $attributes = []): ItemType
{
    return ItemType::query()->create(array_merge([
        'company_id' => $scope['company_id'],
        'name' => 'Existing Item Type',
        'active' => true,
    ], $attributes));
}

test('item types import creates records when id is empty', function () {
    $scope = seedItemTypeImportScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/item-types/import', [
        'rows' => [
            [
                'id' => '',
                'company_id' => $scope['company_id'],
                'name' => 'Imported Item Type',
                'active' => 1,
            ],
        ],
    ])->assertSuccessful()
        ->assertJsonPath('message', 'Successfully imported 1 new and updated 0 item type records.');

    $itemType = ItemType::query()->where('name', 'Imported Item Type')->first();

    expect($itemType)->not->toBeNull()
        ->and($itemType->company_id)->toBe($scope['company_id'])
        ->and($itemType->active)->toBeTrue();
});

test('item types import updates records when id is provided', function () {
    $scope = seedItemTypeImportScope();
    $itemType = createItemTypeForImport($scope);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/item-types/import', [
        'rows' => [
            [
                'id' => $itemType->id,
                'name' => 'Updated Item Type Name',
                'active' => 0,
            ],
        ],
    ])->assertSuccessful()
        ->assertJsonPath('message', 'Successfully imported 0 new and updated 1 item type records.');

    $itemType->refresh();

    expect($itemType->name)->toBe('Updated Item Type Name')
        ->and($itemType->active)->toBeFalse();
});

test('item types import rejects unknown ids', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/item-types/import', [
        'rows' => [
            [
                'id' => 999999,
                'name' => 'Missing Item Type',
                'active' => 1,
            ],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['rows']);
});

test('guests cannot import item types', function () {
    $this->postJson('/api/item-types/import', [
        'rows' => [],
    ])->assertUnauthorized();
});
