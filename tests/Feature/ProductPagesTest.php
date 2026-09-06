<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests cannot access the product add page', function () {
    $this->get(route('product.add'))
        ->assertRedirect();
});

test('guests cannot access the product edit page', function () {
    $this->get(route('product.edit', 1))
        ->assertRedirect();
});

test('authenticated users can open the product add page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('product.add'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('product/add'));
});

test('authenticated users can open the product edit page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('product.edit', 12))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('product/edit')
            ->where('id', '12'));
});

test('authenticated users can open product catalog pages', function (string $routeName, string $component) {
    $this->actingAs(User::query()->findOrFail(1))
        ->get(route($routeName))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component($component));
})->with([
    'brand' => ['brand', 'product/brand/index'],
    'brand trash' => ['brand.trash', 'product/brand/trash'],
    'category' => ['category', 'product/category/index'],
    'category trash' => ['category.trash', 'product/category/trash'],
    'item type' => ['itemtype', 'product/itemtype/index'],
    'item type trash' => ['itemtype.trash', 'product/itemtype/trash'],
    'unit' => ['unit', 'product/unit/index'],
    'unit trash' => ['unit.trash', 'product/unit/trash'],
    'variation' => ['variation', 'product/variation/index'],
    'variation trash' => ['variation.trash', 'product/variation/trash'],
    'warranty' => ['warranty', 'product/warranty/index'],
    'warranty trash' => ['warranty.trash', 'product/warranty/trash'],
]);
