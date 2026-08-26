<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests cannot access the sell approval list page', function () {
    $this->get(route('sell.approval'))
        ->assertRedirect();
});

test('guests cannot access the sell approval view page', function () {
    $this->get(route('sell.approval.view', 1))
        ->assertRedirect();
});

test('authenticated users can open the sell approval list page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('sell.approval'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('approval/sell/index'));
});

test('authenticated users can open the sell approval view page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('sell.approval.view', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('approval/sell/view')
            ->where('id', '12')
            ->where('returnTo', '/sell/approval')
            ->where('listTitle', 'Sell Approval'));
});
