<?php

namespace App\Mail\Support;

use App\Models\Company;
use App\Models\User;

final class CompanyBranding
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
    public static function resolve(?Company $company = null, ?User $user = null): array
    {
        $company ??= $user?->company;

        if ($company !== null) {
            $company->loadMissing('companySetting');
        }

        $setting = $company?->companySetting;
        $companyName = trim((string) ($setting?->business_name ?: $company?->name ?: config('app.name')));
        $supportEmail = trim((string) ($company?->email ?: config('mail.from.address')));

        return [
            'companyName' => $companyName !== '' ? $companyName : (string) config('app.name'),
            'companyLogo' => Company::logoUrl($setting?->logo ?? $company?->logo),
            'supportEmail' => $supportEmail !== '' ? $supportEmail : (string) config('mail.from.address'),
            'companyEmail' => $company?->email ? (string) $company->email : null,
            'privacyUrl' => self::nullableUrl(config('mail.privacy_url')),
            'termsUrl' => self::nullableUrl(config('mail.terms_url')),
            'accentColor' => (string) config('mail.accent_color', '#199683'),
        ];
    }

    private static function nullableUrl(mixed $url): ?string
    {
        $value = is_string($url) ? trim($url) : '';

        return $value !== '' ? $value : null;
    }
}
