<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests cannot access the issue note add page', function () {
    $this->get(route('issuenote.add'))
        ->assertRedirect();
});

test('guests cannot access the issue note edit page', function () {
    $this->get(route('issuenote.edit', 1))
        ->assertRedirect();
});

test('authenticated users can open the issue note add page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('issuenote.add'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('sell/issuenote/add'));
});

test('authenticated users can open the issue note edit page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('issuenote.edit', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('sell/issuenote/edit')
            ->where('id', '12'));
});

test('authenticated users can open the issue note view page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('issuenote.view', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('sell/issuenote/view')
            ->where('id', '12'));
});

test('authenticated users can open the issue note list page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('issuenote'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('sell/issuenote/index'));
});

test('authenticated users can open the issue note trash page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('issuenote.trash'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('sell/issuenote/trash'));
});
