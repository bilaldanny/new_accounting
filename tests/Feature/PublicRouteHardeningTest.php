<?php

use Illuminate\Support\Facades\Route;

// middleware() is the declared (group + route) middleware; gatherMiddleware() would also instantiate every controller, and the file
// manager's raises memory_limit in its constructor, which fails once a full suite run is past 256 MB.
it('rate limits every public route that has no sign-in', function () {
    $unprotected = [];

    foreach (Route::getRoutes() as $route) {
        $middleware = array_map(fn ($m) => is_string($m) ? $m : 'closure', $route->middleware());
        $uri = $route->uri();

        $isPublicTarget = preg_match('#^(api/(fetch(countries|states|cities|currencies|timezones)|branding|leadcapture)|api/storefront/|blog(/|$)|capture/|shop/|r/)#', $uri) === 1;

        if (! $isPublicTarget) {
            continue;
        }

        $throttled = collect($middleware)->contains(fn ($m) => str_starts_with($m, 'throttle'));

        if (! $throttled) {
            $unprotected[] = implode('|', $route->methods()).' '.$uri;
        }
    }

    expect($unprotected)->toBe([]);
});

it('keeps every public API write route behind a signature, token or throttle', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(function ($route) {
        $uri = $route->uri();

        return in_array($uri, ['api/leadcapture', 'api/hrm/device/punch', 'api/storefront/{code}/orders'], true)
            || str_starts_with($uri, 'api/gateway/');
    });

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        $mw = $route->middleware();
        expect(collect($mw)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle')))->toBeTrue($route->uri().' is not throttled');
    }
});
