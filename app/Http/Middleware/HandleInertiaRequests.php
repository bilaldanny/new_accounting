<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $setting = getSetting();
        $permissions = $user ? $user->getPermissions() : collect();

        return [
            ...parent::share($request),
            'csrfToken' => csrf_token(),
            'routeName' => Route::currentRouteName(),
            'dailCode' => getUserDialCode(),
            'ipAddress' => getUserIpAddress(),
            'name' => data_get($setting, 'name', config('app.name')),
            'url' => config('app.url'),
            'timezone' => config('app.timezone'),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'email_verified_at' => $user->email_verified_at,
                    'last_name' => $user->last_name,
                    'fullname' => $user->fullname,
                    'rolename' => $user->rolename,
                    'created_at' => $user->created_at,
                    'email' => $user->email,
                    'profile_image' => $user->profile_photo_url ?? $user->profile_photo_path,
                    'permissions' => $permissions,
                    'permission_paths' => $permissions->isNotEmpty() ? $permissions->pluck('route_path') : [],
                ] : null,
            ],
            'setting' => $setting,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
