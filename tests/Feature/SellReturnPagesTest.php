<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests cannot access the sell return add page', function () {
    $this->get(route('sell.return.add'))
        ->assertRedirect();
});

test('guests cannot access the sell return edit page', function () {
    $this->get(route('sell.return.edit', 1))
        ->assertRedirect();
});

test('authenticated users can open the sell return add page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('sell.return.add'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('sell/return/add'));
});

test('authenticated users can open the sell return edit page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('sell.return.edit', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('sell/return/edit')
            ->where('id', '12'));
});

test('authenticated users can open the sell return view page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('sell.return.view', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('sell/return/view')
            ->where('id', '12'));
});

test('authenticated users can open the sell return list page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('sell.return'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('sell/return/index'));
});

test('authenticated users can open the sell return trash page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('sell.return.trash'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('sell/return/trash'));
});
