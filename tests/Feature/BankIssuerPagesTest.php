<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests cannot access the bank issuer add page', function () {
    $this->get(route('bankissuer.add'))
        ->assertRedirect();
});

test('authenticated users can open the bank issuer pages', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('bankissuer'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('bankissuer/index'));

    $this->actingAs($superadmin)
        ->get(route('bankissuer.add'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('bankissuer/add'));

    $this->actingAs($superadmin)
        ->get(route('bankissuer.edit', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('bankissuer/edit')
            ->where('id', '12'));

    $this->actingAs($superadmin)
        ->get(route('bankissuer.trash'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('bankissuer/trash'));
});
