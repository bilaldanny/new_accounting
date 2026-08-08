<?php

test('guests cannot access the menus api', function () {
    $this->getJson('/api/menus')
        ->assertUnauthorized();
});

test('api routes use sanctum stateful middleware for spa session auth', function () {
    $middleware = app(\Illuminate\Contracts\Http\Kernel::class)->getMiddlewareGroups()['api'];

    expect($middleware)->toContain(
        \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
    );
});
