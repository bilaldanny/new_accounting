<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests cannot access the receiving note add page', function () {
    $this->get(route('receivingnote.add'))
        ->assertRedirect();
});

test('guests cannot access the receiving note edit page', function () {
    $this->get(route('receivingnote.edit', 1))
        ->assertRedirect();
});

test('authenticated users can open the receiving note add page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('receivingnote.add'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('purchase/receivingnote/add'));
});

test('authenticated users can open the receiving note edit page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('receivingnote.edit', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('purchase/receivingnote/edit')
            ->where('id', '12'));
});

test('authenticated users can open the receiving note view page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('receivingnote.view', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('purchase/receivingnote/view')
            ->where('id', '12'));
});

test('authenticated users can open the receiving note list page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('receivingnote'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('purchase/receivingnote/index'));
});

test('authenticated users can open the receiving note trash page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('receivingnote.trash'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('purchase/receivingnote/trash'));
});
