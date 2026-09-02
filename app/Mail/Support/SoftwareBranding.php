<?php

namespace App\Mail\Support;

use App\Models\Setting;
use Throwable;

final class SoftwareBranding
{
    /**
     * @return array{
     *     companyName: string,
     *     companyLogo: string|null,
     *     supportEmail: string,
     *     companyEmail: string|null,
     *     privacyUrl: string|null,
     *     termsUrl: string|null,
     *     accentColor: string
     * }
     */
    public static function resolve(): array
    {
        $setting = self::setting();
        $softwareName = trim((string) ($setting?->name ?: config('app.name')));
        $supportEmail = trim((string) (
            $setting?->email
            ?: $setting?->smtp_from_address
            ?: config('mail.from.address')
        ));

        return [
            'companyName' => $softwareName !== '' ? $softwareName : (string) config('app.name'),
            'companyLogo' => Setting::logoUrl($setting?->email_logo ?: $setting?->system_logo),
            'supportEmail' => $supportEmail !== '' ? $supportEmail : (string) config('mail.from.address'),
            'companyEmail' => $setting?->email ? (string) $setting->email : null,
            'privacyUrl' => self::nullableUrl(config('mail.privacy_url')),
            'termsUrl' => self::nullableUrl(config('mail.terms_url')),
            'accentColor' => (string) config('mail.accent_color', '#199683'),
        ];
    }

    private static function setting(): ?Setting
    {
        try {
            return Setting::query()->first();
        } catch (Throwable) {
            return null;
        }
    }

    private static function nullableUrl(mixed $url): ?string
    {
        $value = is_string($url) ? trim($url) : '';

        return $value !== '' ? $value : null;
    }
}
