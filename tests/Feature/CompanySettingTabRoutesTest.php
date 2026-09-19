<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

test('guests cannot open the tax and financial year pages', function () {
    $this->get(route('tax'))->assertRedirect();
    $this->get(route('financialyear'))->assertRedirect();
});

test('the tax and financial year sidebar paths resolve to web routes', function () {
    $routes = app('router')->getRoutes();

    foreach (['/tax' => 'tax', '/financialyear' => 'financialyear'] as $path => $name) {
        expect($routes->match(Request::create($path, 'GET'))->getName())->toBe($name)
            ->and(route($name, absolute: false))->toBe($path);
    }
});

test('the tax page opens company setting on the tax tab', function () {
    $this->actingAs(User::query()->findOrFail(1))
        ->get(route('tax'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('company/setting')
            ->where('tab', 'tax'));
});

test('the financial year page opens company setting on the financial year tab', function () {
    $this->actingAs(User::query()->findOrFail(1))
        ->get(route('financialyear'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('company/setting')
            ->where('tab', 'financialYear'));
});

test('the existing company setting routes still open without a preselected tab', function () {
    $superadmin = User::query()->findOrFail(1);

    foreach (['company.setting', 'business.settings', 'setting'] as $name) {
        $this->actingAs($superadmin)
            ->get(route($name))
            ->assertSuccessful()
            ->assertInertia(fn ($page) => $page
                ->component('company/setting')
                ->missing('tab'));
    }
});
