<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests cannot access the menu index page', function () {
    $this->get(route('menu'))
        ->assertRedirect();
});

test('authenticated users can open menu management pages', function (string $routeName, string $component) {
    $this->actingAs(User::query()->findOrFail(1))
        ->get(route($routeName))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component($component));
})->with([
    'index' => ['menu', 'menu/index'],
    'trash' => ['menu.trash', 'menu/trash'],
]);

test('menu add and edit forms keep the prototype field set', function () {
    $fields = file_get_contents(resource_path('js/pages/menu/Fields.vue'));
    $add = file_get_contents(resource_path('js/pages/menu/add.vue'));
    $edit = file_get_contents(resource_path('js/pages/menu/edit.vue'));

    expect($fields)
        ->toContain('name="parent_id"')
        ->toContain('name="type"')
        ->toContain('name="name"')
        ->toContain('name="route_path"')
        ->toContain('name="route_name"')
        ->toContain('name="icon"')
        ->toContain('name="sort_order"')
        ->toContain('name="is_active"')
        ->toContain('name="is_hidden"')
        ->toContain('name="is_permission"');

    expect($add)
        ->toContain('title="Add Menu"')
        ->toContain('content-class="menu-form-modal"')
        ->toContain(":show-required=\"['label']\"");

    expect($edit)
        ->toContain('title="Edit Menu"')
        ->toContain('content-class="menu-form-modal"')
        ->toContain(":show-required=\"['label']\"");
});

test('the dark topbar profile control uses initials instead of a light chip', function () {
    $header = file_get_contents(resource_path('js/components/AppSidebarHeader.vue'));

    expect($header)
        ->toContain('class="topbar-avatar"')
        ->toContain('{{ userInitials }}')
        ->toContain('user?.fullname')
        ->toContain('user?.email')
        ->toContain('border-radius: 0.5rem')
        ->not->toContain('topbar-user__chevron')
        ->toContain('topbar-user-menu')
        ->not->toContain('Earnings');
});
