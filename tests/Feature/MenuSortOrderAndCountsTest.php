<?php

use App\Models\Menu;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function createMenuForSortOrderTest(array $attributes = []): Menu
{
    return Menu::query()->create(array_merge([
        'name' => 'Sort Order Menu',
        'icon' => 'Grid',
        'route_name' => 'sort-order-menu',
        'route_path' => '/sort-order-menu',
        'menu_color' => '#6a0dad',
        'sort_order' => 1,
        'type' => 1,
        'is_active' => 1,
        'is_hidden' => 0,
        'is_admin' => 0,
        'is_permission' => 1,
    ], $attributes));
}

test('menus index returns active and inactive counts alongside trash count', function () {
    createMenuForSortOrderTest(['name' => 'Active One', 'is_active' => 1]);
    createMenuForSortOrderTest(['name' => 'Active Two', 'route_name' => 'active-two', 'route_path' => '/active-two', 'is_active' => 1]);
    createMenuForSortOrderTest(['name' => 'Inactive One', 'route_name' => 'inactive-one', 'route_path' => '/inactive-one', 'is_active' => 'false']);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/menus')->assertSuccessful();

    expect($response->json('active_count'))->toBeGreaterThanOrEqual(2)
        ->and($response->json('inactive_count'))->toBeGreaterThanOrEqual(1)
        ->and($response->json())->toHaveKey('trash_count');
});

test('updating sort order via the inline stepper endpoint persists the new value', function () {
    $menu = createMenuForSortOrderTest(['sort_order' => 3]);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson("/api/menus/{$menu->id}/sort-order", ['sort_order' => 7])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Saved')
        ->assertJsonPath('sort_order', 7);

    $menu->refresh();

    expect($menu->sort_order)->toBe(7);
});

test('sort order update rejects a negative value', function () {
    $menu = createMenuForSortOrderTest(['sort_order' => 3]);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson("/api/menus/{$menu->id}/sort-order", ['sort_order' => -1])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sort_order']);
});

test('guests cannot update menu sort order', function () {
    $menu = createMenuForSortOrderTest();

    $this->postJson("/api/menus/{$menu->id}/sort-order", ['sort_order' => 5])
        ->assertUnauthorized();
});
