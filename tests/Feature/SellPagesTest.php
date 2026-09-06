<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests cannot access the sell add page', function () {
    $this->get(route('sell.add'))
        ->assertRedirect();
});

test('guests cannot access the sell edit page', function () {
    $this->get(route('sell.edit', 1))
        ->assertRedirect();
});

test('authenticated users can open the sell add page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('sell.add'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('sell/add'));
});

test('authenticated users can open the sell edit page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('sell.edit', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('sell/edit')
            ->where('id', '12'));
});

test('authenticated users can open the sell view page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('sell.view', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('approval/sell/view')
            ->where('id', '12')
            ->where('returnTo', '/sell')
            ->where('listTitle', 'Sell Management'));
});

test('authenticated users can open the sell list page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('sell'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('sell/index'));
});

test('authenticated users can open the sell trash page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('sell.trash'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('sell/trash'));
});

test('authenticated users can open sell draft quotation shipment pos and invoice pages', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('sell.draft'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('sell/draft'));

    $this->actingAs($superadmin)
        ->get(route('sell.quotation'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('sell/quotation'));

    $this->actingAs($superadmin)
        ->get(route('sell.shipment'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('sell/shipment'));

    $this->actingAs($superadmin)
        ->get(route('sell.pos.add'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('sell/addpos'));

    $this->actingAs($superadmin)
        ->get(route('sell.invoice', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('sell/invoice')
            ->where('id', '12'));
});
