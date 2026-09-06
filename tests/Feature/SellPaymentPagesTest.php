<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests cannot access the sell payment page', function () {
    $this->get(route('sell.payment'))
        ->assertRedirect();
});

test('authenticated users can open the sell payment list page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('sell.payment'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('sell/payment/index'));
});

test('authenticated users can open the sell payment page for a transaction', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('sell.payment', ['transaction_id' => 12, 'add' => 1]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('sell/payment/index'));
});
