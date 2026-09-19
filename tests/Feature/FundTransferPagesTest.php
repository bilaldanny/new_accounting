<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests cannot access the fund transfer add page', function () {
    $this->get(route('fundtransfer.add'))
        ->assertRedirect();
});

test('authenticated users can open the fund transfer pages', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('fundtransfer'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('fundtransfer/index'));

    $this->actingAs($superadmin)
        ->get(route('fundtransfer.add'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('fundtransfer/add'));

    $this->actingAs($superadmin)
        ->get(route('fundtransfer.edit', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('fundtransfer/edit')
            ->where('id', '12'));

    $this->actingAs($superadmin)
        ->get(route('fundtransfer.view', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('fundtransfer/view')
            ->where('id', '12'));
});
