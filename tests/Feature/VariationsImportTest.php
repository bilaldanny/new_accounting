<?php

use App\Models\User;
use App\Models\Variation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('variations import creates records when id is empty', function () {
    $scope = seedVariationScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/variations/import', [
        'rows' => [
            [
                'id' => '',
                'company_id' => $scope['company_id'],
                'category' => 'Apparel',
                'subcategory' => 'Shirts',
                'item_type' => 'Finished Goods',
                'values' => 'Red|Blue',
                'active' => 1,
            ],
        ],
    ])->assertSuccessful()
        ->assertJsonPath('message', 'Successfully imported 1 new and updated 0 variation records.');

    $variation = Variation::query()->latest('id')->first();

    expect($variation)->not->toBeNull()
        ->and($variation->category_id)->toBe($scope['category_id'])
        ->and($variation->subcategory_id)->toBe($scope['subcategory_id'])
        ->and($variation->itemtype_id)->toBe($scope['itemtype_id'])
        ->and($variation->values)->toBe([
            ['name' => 'Red', 'active' => true],
            ['name' => 'Blue', 'active' => true],
        ]);
});

test('variations import updates records when id is provided', function () {
    $scope = seedVariationScope();
    $variation = Variation::query()->create([
        'company_id' => $scope['company_id'],
        'category_id' => $scope['category_id'],
        'subcategory_id' => $scope['subcategory_id'],
        'itemtype_id' => $scope['itemtype_id'],
        'values' => [['name' => 'Small', 'active' => true]],
        'priority' => 0,
        'active' => true,
    ]);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/variations/import', [
        'rows' => [
            [
                'id' => $variation->id,
                'category' => 'Apparel',
                'subcategory' => 'Shirts',
                'item_type' => 'Finished Goods',
                'values' => 'XL|XXL',
                'active' => 0,
            ],
        ],
    ])->assertSuccessful()
        ->assertJsonPath('message', 'Successfully imported 0 new and updated 1 variation records.');

    $variation->refresh();

    expect($variation->values)->toBe([
        ['name' => 'XL', 'active' => true],
        ['name' => 'XXL', 'active' => true],
    ])->and($variation->active)->toBeFalse();
});

test('variations import rejects unknown ids', function () {
    $scope = seedVariationScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/variations/import', [
        'rows' => [
            [
                'id' => 999999,
                'category' => 'Apparel',
                'item_type' => 'Finished Goods',
                'values' => 'Small',
                'active' => 1,
            ],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['rows']);
});

test('variations import rejects empty values string', function () {
    $scope = seedVariationScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/variations/import', [
        'rows' => [
            [
                'id' => '',
                'company_id' => $scope['company_id'],
                'category' => 'Apparel',
                'item_type' => 'Finished Goods',
                'values' => '',
                'active' => 1,
            ],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['rows.0.values']);
});

test('variations import rejects blank variation names', function () {
    $scope = seedVariationScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/variations/import', [
        'rows' => [
            [
                'id' => '',
                'company_id' => $scope['company_id'],
                'category' => 'Apparel',
                'item_type' => 'Finished Goods',
                'values' => '|||',
                'active' => 1,
            ],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['values']);
});

test('guests cannot import variations', function () {
    $this->postJson('/api/variations/import', [
        'rows' => [],
    ])->assertUnauthorized();
});
