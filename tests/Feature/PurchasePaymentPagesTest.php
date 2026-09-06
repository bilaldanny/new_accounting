<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests cannot access the purchase payment page', function () {
    $this->get(route('purchase.payment'))
        ->assertRedirect();
});

test('authenticated users can open the purchase payment list page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('purchase.payment'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('purchase/payment/index'));
});

test('authenticated users can open the purchase payment page for a transaction', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('purchase.payment', ['transaction_id' => 12, 'add' => 1]))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('purchase/payment/index'));
});
