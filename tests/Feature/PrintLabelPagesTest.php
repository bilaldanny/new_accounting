<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests cannot access the print label page', function () {
    $this->get(route('printlabel'))
        ->assertRedirect();
});

test('authenticated users can open the print label page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('printlabel'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('printlabel/index'));
});
