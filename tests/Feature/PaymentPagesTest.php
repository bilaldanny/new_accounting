<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests cannot access the payment add page', function () {
    $this->get(route('payment.add'))
        ->assertRedirect();
});

test('authenticated users can open the payment pages', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('payment'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('payment/index'));

    $this->actingAs($superadmin)
        ->get(route('payment.add'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('payment/add'));

    $this->actingAs($superadmin)
        ->get(route('payment.edit', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('payment/edit')
            ->where('id', '12'));

    $this->actingAs($superadmin)
        ->get(route('payment.view', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('payment/view')
            ->where('id', '12'));
});
