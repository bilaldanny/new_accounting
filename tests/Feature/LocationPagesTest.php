<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests cannot access location pages', function (string $route) {
    $this->get(route($route))
        ->assertRedirect();
})->with([
    'country',
    'country.trash',
    'state',
    'state.trash',
    'city',
    'city.trash',
]);

test('authenticated users can open location pages', function (string $route, string $component) {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route($route))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component($component));
})->with([
    ['country', 'country/index'],
    ['country.trash', 'country/trash'],
    ['state', 'state/index'],
    ['state.trash', 'state/trash'],
    ['city', 'city/index'],
    ['city.trash', 'city/trash'],
]);
