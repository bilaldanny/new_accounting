<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests cannot access the deposit add page', function () {
    $this->get(route('deposit.add'))
        ->assertRedirect();
});

test('authenticated users can open the deposit pages', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('deposit'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('deposit/index'));

    $this->actingAs($superadmin)
        ->get(route('deposit.add'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('deposit/add'));

    $this->actingAs($superadmin)
        ->get(route('deposit.edit', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('deposit/edit')
            ->where('id', '12'));

    $this->actingAs($superadmin)
        ->get(route('deposit.view', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('deposit/view')
            ->where('id', '12'));
});
