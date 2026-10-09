<?php

namespace App\Support;

use App\Models\Setting;

/**
 * What a signed-out visitor may learn about the platform: its name and its logo, nothing else from the settings row. The
 * logo is the platform ("system") logo set under Software Settings; a company's own logo is not used on public pages.
 */
final class PublicBranding
{
    /**
     * The path a fresh install carries until someone uploads a logo; it is a different brand's placeholder, so it counts as unset.
     */
    private const PLACEHOLDER_LOGO = 'assets/images/logo-img.png';

    /**
     * @return array{name: string, logoUrl: string|null}
     */
    public static function get(): array
    {
        $setting = Setting::query()->first();
        $name = trim((string) ($setting?->name ?? ''));

        return [
            'name' => $name !== '' ? $name : (string) (config('landing.brand') ?: config('app.name')),
            'logoUrl' => self::logoUrl($setting?->system_logo),
        ];
    }

    /**
     * The public URL of the logo, or null when none is set or a stored file is gone, so the page falls back to the name.
     */
    public static function logoUrl(?string $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '' || ltrim($path, '/') === self::PLACEHOLDER_LOGO) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return is_file(public_path(ltrim($path, '/'))) ? Setting::logoUrl($path) : null;
    }
}
