<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests cannot access the purchase approval list page', function () {
    $this->get(route('purchase.approval'))
        ->assertRedirect();
});

test('guests cannot access the purchase approval view page', function () {
    $this->get(route('purchase.approval.view', 1))
        ->assertRedirect();
});

test('authenticated users can open the purchase approval list page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('purchase.approval'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('approval/purchase/index'));
});

test('authenticated users can open the purchase approval view page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('purchase.approval.view', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('approval/purchase/view')
            ->where('id', '12')
            ->where('returnTo', '/purchase/approval')
            ->where('listTitle', 'Purchase Approval'));
});
