<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated users can open software catalog pages', function (string $routeName, string $component) {
    $this->actingAs(User::query()->findOrFail(1))
        ->get(route($routeName))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component($component));
})->with([
    'currency' => ['currency', 'software/currency/index'],
    'currency trash' => ['currency.trash', 'software/currency/trash'],
    'timezone' => ['timezone', 'software/timezone/index'],
    'timezone trash' => ['timezone.trash', 'software/timezone/trash'],
]);
