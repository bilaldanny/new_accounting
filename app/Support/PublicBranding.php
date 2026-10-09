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
     * The header logo, then the footer logo, each falling back (footer to header, header to the software logo). A logo uploaded
     * for the website itself is drawn as it is; the software logo is drawn on a light chip, being made for light screens.
     *
     * @return array{name: string, logoUrl: string|null, headerLogoUrl: string|null, footerLogoUrl: string|null, headerChip: bool, footerChip: bool}
     */
    public static function get(): array
    {
        $setting = Setting::query()->first();
        $system = self::logoUrl($setting?->system_logo);
        $header = self::logoUrl($setting?->header_logo);
        $footer = self::logoUrl($setting?->footer_logo);

        return [
            'name' => self::name($setting),
            'logoUrl' => $system,
            'headerLogoUrl' => $header ?? $system,
            'footerLogoUrl' => $footer ?? $header ?? $system,
            'headerChip' => $header === null,
            'footerChip' => $footer === null && $header === null,
        ];
    }

    /**
     * What every signed-out page may receive as shared data: the name, and the login logo the sign-in screens show.
     *
     * @return array{name: string, setting: array{login_logo_url: string|null}}
     */
    public static function forGuest(): array
    {
        $setting = Setting::query()->first();

        return [
            'name' => self::name($setting),
            'setting' => ['login_logo_url' => Setting::logoUrl($setting?->login_logo)],
        ];
    }

    /**
     * The public URL of the logo, or null when none is set or a stored file is gone, so the page falls back to the name.
     */
    public static function logoUrl(?string $path): ?string
    {
        $path = trim((string) $path);

        if (Setting::isPlaceholderLogo($path)) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return is_file(public_path(ltrim($path, '/'))) ? Setting::logoUrl($path) : null;
    }

    private static function name(?Setting $setting): string
    {
        $name = trim((string) ($setting?->name ?? ''));

        return $name !== '' ? $name : (string) (config('landing.brand') ?: config('app.name'));
    }
}
