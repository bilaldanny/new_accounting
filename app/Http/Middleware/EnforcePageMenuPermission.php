<?php

namespace App\Http\Middleware;

use App\Models\Menu;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a staff user off a page whose menu they were not given. The page routes only render a shell and the data comes from
 * the API (which has always checked the same menus), so without this a role could open any page by its URL and see an empty
 * screen instead of a 403.
 *
 * Only a page that has a menu row is checked (`/fixedasset/:id/edit` for `fixedasset/{id}/edit`); a page with no menu, such as
 * the dashboard or the profile, is open to every signed-in user as before. Superadmin passes in `hasMenuPermission()` and
 * a company admin is let through here, because the company admin's access to company-level pages is decided by role, not by
 * ticked menus.
 */
class EnforcePageMenuPermission
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $uri = $request->route()?->uri();

        if ($user === null || $uri === null || ! $request->isMethod('GET') || $user->hasRole('companyadmin')) {
            return $next($request);
        }

        $path = '/'.preg_replace('/\{[^}]+\}/', ':id', $uri);

        if ($path !== '/' && Menu::query()->where('route_path', $path)->where('is_active', 1)->exists()) {
            abortUnlessMenuPermission($path);
        }

        return $next($request);
    }
}
