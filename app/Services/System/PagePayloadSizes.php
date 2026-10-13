<?php

namespace App\Services\System;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * Measures what every parameterless page of the application sends to the browser: the size of the Inertia response (the page's props as
 * JSON, which the first full page load embeds as well), how much of it is the props shared with every page, and which single prop is the
 * largest. Used by `php artisan pages:payload-sizes` and by the size budget test.
 */
class PagePayloadSizes
{
    /**
     * @return array{pages: list<array{uri: string, bytes: int, shared_bytes: int, own_bytes: int, largest_prop: string, largest_bytes: int}>, shared: array<string, int>, skipped: int}
     */
    public function measure(User $user, ?int $only = null): array
    {
        Auth::shouldUse('web');
        Auth::guard('web')->setUser($user);

        $kernel = app(Kernel::class);
        $version = (string) app(HandleInertiaRequests::class)->version(Request::create('/'));
        $pages = [];
        $shared = [];
        $skipped = 0;

        foreach ($this->pageUris() as $uri) {
            if ($only !== null && count($pages) >= $only) {
                break;
            }

            $request = Request::create('/'.$uri, 'GET', [], [], [], ['HTTP_X_INERTIA' => 'true', 'HTTP_X_INERTIA_VERSION' => $version, 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml']);
            $response = $kernel->handle($request);
            $page = json_decode((string) $response->getContent(), true);

            if ($response->getStatusCode() !== 200 || ! is_array($page) || ! isset($page['props'])) {
                $skipped++;

                continue;
            }

            $props = $page['props'];
            $sizes = array_map(fn ($value): int => strlen((string) json_encode($value)), $props);

            if ($shared === []) {
                $names = array_keys(app(HandleInertiaRequests::class)->share($request));
                $shared = array_intersect_key($sizes, array_flip($names));
            }

            $sharedBytes = array_sum(array_intersect_key($sizes, $shared));
            $own = array_diff_key($sizes, $shared);
            arsort($own);

            $pages[] = [
                'uri' => $uri, 'bytes' => strlen((string) $response->getContent()), 'shared_bytes' => $sharedBytes, 'own_bytes' => array_sum($own),
                'largest_prop' => (string) (array_key_first($own) ?? '-'), 'largest_bytes' => (int) (reset($own) ?: 0),
            ];
        }

        usort($pages, fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);
        arsort($shared);

        return ['pages' => $pages, 'shared' => $shared, 'skipped' => $skipped];
    }

    /**
     * @return list<string>
     */
    private function pageUris(): array
    {
        $uris = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            if (! in_array('GET', $route->methods(), true) || str_contains($uri, '{') || $uri === '/' || preg_match('#^(api|_|sanctum|storage|filemanager|laravel-filemanager|artisan|logout|up$)#', $uri) === 1) {
                continue;
            }

            $uris[] = $uri;
        }

        return array_values(array_unique($uris));
    }
}
