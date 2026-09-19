<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests cannot access the price list add page', function () {
    $this->get(route('pricelist.add'))
        ->assertRedirect();
});

test('authenticated users can open the price list pages', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('pricelist'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('pricelist/index'));

    $this->actingAs($superadmin)
        ->get(route('pricelist.add'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('pricelist/add'));

    $this->actingAs($superadmin)
        ->get(route('pricelist.edit', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('pricelist/edit')
            ->where('id', '12'));

    $this->actingAs($superadmin)
        ->get(route('pricelist.trash'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('pricelist/trash'));
});
