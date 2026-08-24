<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests cannot access the purchase return add page', function () {
    $this->get(route('purchase.return.add'))
        ->assertRedirect();
});

test('guests cannot access the purchase return edit page', function () {
    $this->get(route('purchase.return.edit', 1))
        ->assertRedirect();
});

test('authenticated users can open the purchase return add page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('purchase.return.add'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('purchase/purchasereturn/add'));
});

test('authenticated users can open the purchase return edit page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('purchase.return.edit', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('purchase/purchasereturn/edit')
            ->where('id', '12'));
});

test('authenticated users can open the purchase return view page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('purchase.return.view', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('purchase/purchasereturn/view')
            ->where('id', '12'));
});

test('authenticated users can open the purchase return list page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('purchase.return'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('purchase/purchasereturn/index'));
});

test('authenticated users can open the purchase return trash page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('purchase.return.trash'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('purchase/purchasereturn/trash'));
});
