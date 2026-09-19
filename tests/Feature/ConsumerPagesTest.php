<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests cannot access the consumer add page', function () {
    $this->get(route('consumer.add'))
        ->assertRedirect();
});

test('authenticated users can open the consumer pages', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('consumer'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('consumer/index'));

    $this->actingAs($superadmin)
        ->get(route('consumer.add'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('consumer/add'));

    $this->actingAs($superadmin)
        ->get(route('consumer.edit', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('consumer/edit')
            ->where('id', '12'));

    $this->actingAs($superadmin)
        ->get(route('consumer.trash'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('consumer/trash'));
});
