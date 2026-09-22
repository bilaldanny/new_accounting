<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a portal user (a user tied to one customer or supplier, `users.contact_id`) inside the portal. They may
 * open the portal page and its data, and sign out; everything else is refused, whatever an endpoint checks for
 * itself: many list endpoints only scope by company, and a customer must never be able to read the company's
 * other customers, so the portal account is confined here rather than trusted to every controller. A browser
 * request outside the portal is sent to it, an API request gets a 403.
 */
class RestrictPortalUsers
{
    /**
     * Paths a portal user may use (a trailing * matches anything below).
     *
     * @var list<string>
     */
    private const ALLOWED = ['portal', 'api/portal', 'api/user', 'logout', 'idle-timeout-alert/*', 'up'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('sanctum') ?? $request->user();

        if ($user === null || $user->contact_id === null || $request->is(...self::ALLOWED)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => 'A portal account can only use the portal.'], 403);
        }

        return redirect('/portal');
    }
}
