<?php

namespace App\Http\Middleware;

use App\Models\CompanySetting;
use App\Models\Menu;
use App\Services\System\BusinessSettings;
use App\Support\PublicBranding;
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

        // A signed-out visitor gets the name and the login logo only: no settings row (SMTP host, username, address, email), no IP lookup.
        if ($user === null) {
            return [
                ...parent::share($request),
                'csrfToken' => csrf_token(),
                'routeName' => Route::currentRouteName(),
                ...PublicBranding::forGuest(),
                'url' => config('app.url'),
                'auth' => ['user' => null],
                'sidebarOpen' => true,
            ];
        }

        if ($user) {
            $user->loadMissing('company');
        }

        $setting = getSetting();
        $permissions = $user ? $user->getPermissions() : collect();

        return [
            ...parent::share($request),
            'csrfToken' => csrf_token(),
            'routeName' => Route::currentRouteName(),
            'dailCode' => getUserDialCode(),
            'ipAddress' => getUserIpAddress(),
            'name' => data_get($setting, 'name') ?: config('app.name'),
            'url' => config('app.url'),
            'timezone' => config('app.timezone'),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'email_verified_at' => $user->email_verified_at,
                    'last_name' => $user->last_name,
                    'name' => $user->full_name,
                    'fullname' => $user->fullname,
                    'rolename' => $user->rolename,
                    'company_id' => $user->company_id,
                    'company_name' => $user->company?->name,
                    'search_type' => $user->company_id
                        ? (CompanySetting::query()->where('company_id', $user->company_id)->value('search_type') ?: 'searchbox')
                        : 'searchbox',
                    'branch_id' => $user->branch_id,
                    'created_at' => $user->created_at,
                    'email' => $user->email,
                    'profile_image' => $user->profile_photo_url ?? $user->profile_photo_path,
                    'permissions' => Menu::slimSidebarTree($permissions),
                    'permission_paths' => $user->getPermissionPaths(),
                    'notification_sound' => (bool) ($user->notification_sound ?? true),
                ] : null,
            ],
            'setting' => $setting,
            'numberFormat' => $user->company_id ? app(BusinessSettings::class)->all((int) $user->company_id)['number_format'] : BusinessSettings::DEFAULTS['number_format'],
            'locale' => $user->company_id ? app(BusinessSettings::class)->all((int) $user->company_id)['language']['default'] : 'en',
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
