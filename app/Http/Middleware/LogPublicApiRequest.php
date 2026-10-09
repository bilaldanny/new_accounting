<?php

namespace App\Http\Middleware;

use App\Models\ApiLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs every request to the new public API (`api/v1/*`) only — not a retrofit onto the app's much
 * larger existing internal SPA API, which this middleware is never attached to.
 */
class LogPublicApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        $response = $next($request);

        $user = $request->user();

        ApiLog::query()->create([
            'company_id' => $user?->company_id,
            'user_id' => $user?->id,
            'method' => $request->method(),
            'path' => $request->path(),
            'status_code' => $response->getStatusCode(),
            'ip' => $request->ip(),
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'created_at' => now(),
        ]);

        return $response;
    }
}
