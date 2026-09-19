<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests cannot access the expense add page', function () {
    $this->get(route('expense.add'))
        ->assertRedirect();
});

test('authenticated users can open the expense pages', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('expense'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('expense/index'));

    $this->actingAs($superadmin)
        ->get(route('expense.add'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('expense/add'));

    $this->actingAs($superadmin)
        ->get(route('expense.edit', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('expense/edit')
            ->where('id', '12'));

    $this->actingAs($superadmin)
        ->get(route('expense.view', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('expense/view')
            ->where('id', '12'));
});
