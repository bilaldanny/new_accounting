<?php

use Illuminate\Contracts\Http\Kernel;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

test('guests cannot access the menus api', function () {
    $this->getJson('/api/menus')
        ->assertUnauthorized();
});

test('api routes use sanctum stateful middleware for spa session auth', function () {
    $middleware = app(Kernel::class)->getMiddlewareGroups()['api'];

    expect($middleware)->toContain(
        EnsureFrontendRequestsAreStateful::class,
    );
});
