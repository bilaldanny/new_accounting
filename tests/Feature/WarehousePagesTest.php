<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests cannot access the warehouse add page', function () {
    $this->get(route('warehouse.add'))
        ->assertRedirect();
});

test('authenticated users can open the warehouse pages', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('warehouse'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('warehouse/index'));

    $this->actingAs($superadmin)
        ->get(route('warehouse.add'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('warehouse/add'));

    $this->actingAs($superadmin)
        ->get(route('warehouse.edit', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('warehouse/edit')
            ->where('id', '12'));

    $this->actingAs($superadmin)
        ->get(route('warehouse.trash'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('warehouse/trash'));
});
