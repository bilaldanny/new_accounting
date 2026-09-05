<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests cannot access the journal entry add page', function () {
    $this->get(route('journalentry.add'))
        ->assertRedirect();
});

test('authenticated users can open the journal entry pages', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('journalentry'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('journalentry/index'));

    $this->actingAs($superadmin)
        ->get(route('journalentry.add'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('journalentry/add'));

    $this->actingAs($superadmin)
        ->get(route('journalentry.edit', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('journalentry/edit')
            ->where('id', '12'));

    $this->actingAs($superadmin)
        ->get(route('journalentry.view', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('journalentry/view')
            ->where('id', '12'));
});
