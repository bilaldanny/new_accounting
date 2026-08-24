<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests cannot access the purchase add page', function () {
    $this->get(route('purchase.add'))
        ->assertRedirect();
});

test('guests cannot access the purchase edit page', function () {
    $this->get(route('purchase.edit', 1))
        ->assertRedirect();
});

test('authenticated users can open the purchase add page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('purchase.add'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('purchase/add'));
});

test('authenticated users can open the purchase edit page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('purchase.edit', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('purchase/edit')
            ->where('id', '12'));
});

test('authenticated users can open the purchase view page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('purchase.view', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('approval/purchase/view')
            ->where('id', '12')
            ->where('returnTo', '/purchase')
            ->where('listTitle', 'Purchase Management'));
});

test('authenticated users can open the purchase list page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('purchase'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('purchase/index'));
});
