<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests cannot access the product add page', function () {
    $this->get(route('product.add'))
        ->assertRedirect();
});

test('guests cannot access the product edit page', function () {
    $this->get(route('product.edit', 1))
        ->assertRedirect();
});

test('authenticated users can open the product add page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('product.add'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('product/add'));
});

test('authenticated users can open the product edit page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('product.edit', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('product/edit')
            ->where('id', '12'));
});
