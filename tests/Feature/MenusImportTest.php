<?php

use App\Models\Menu;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function createMenuForImport(array $attributes = []): Menu
{
    return Menu::query()->create(array_merge([
        'name' => 'Existing Menu',
        'icon' => 'Grid',
        'route_name' => 'existing',
        'route_path' => '/existing',
        'menu_color' => '#6a0dad',
        'sort_order' => 1,
        'type' => 1,
        'is_active' => 1,
        'is_hidden' => 0,
        'is_admin' => 0,
        'is_permission' => 1,
    ], $attributes));
}

test('menus import creates records when id is empty', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/menus/import', [
        'rows' => [
            [
                'id' => '',
                'parent_id' => '',
                'type' => 1,
                'name' => 'Imported Menu',
                'route_path' => '/imported',
                'route_name' => 'imported',
                'icon' => 'Grid',
                'sort_order' => 2,
                'is_active' => 1,
                'is_hidden' => 0,
                'is_admin' => 1,
                'is_permission' => 1,
            ],
        ],
    ])->assertSuccessful()
        ->assertJsonPath('message', 'Successfully imported 1 new and updated 0 menu records.');

    $menu = Menu::query()->where('name', 'Imported Menu')->first();

    expect($menu)->not->toBeNull()
        ->and($menu->route_path)->toBe('/imported')
        ->and($menu->route_name)->toBe('imported')
        ->and($menu->getAttributes()['is_admin'])->toBe(1);
});

test('menus import updates records when id is provided', function () {
    $menu = createMenuForImport();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/menus/import', [
        'rows' => [
            [
                'id' => $menu->id,
                'parent_id' => '',
                'type' => 1,
                'name' => 'Updated Menu Name',
                'route_path' => '/updated',
                'route_name' => 'updatedmenu',
                'icon' => 'Grid',
                'sort_order' => 5,
                'is_active' => 1,
                'is_hidden' => 0,
                'is_admin' => 1,
                'is_permission' => 1,
            ],
        ],
    ])->assertSuccessful()
        ->assertJsonPath('message', 'Successfully imported 0 new and updated 1 menu records.');

    $menu->refresh();

    expect($menu->name)->toBe('Updated Menu Name')
        ->and($menu->route_path)->toBe('/updated')
        ->and($menu->route_name)->toBe('updatedmenu')
        ->and($menu->sort_order)->toBe(5);
});

test('menus import rejects unknown ids', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/menus/import', [
        'rows' => [
            [
                'id' => 999999,
                'parent_id' => '',
                'type' => 1,
                'name' => 'Missing Menu',
                'route_path' => '/missing',
                'route_name' => 'missing',
                'icon' => 'Grid',
                'sort_order' => 1,
                'is_active' => 1,
                'is_hidden' => 0,
                'is_admin' => 1,
                'is_permission' => 1,
            ],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['rows']);
});

test('guests cannot import menus', function () {
    $this->postJson('/api/menus/import', [
        'rows' => [],
    ])->assertUnauthorized();
});
