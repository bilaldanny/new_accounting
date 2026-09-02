<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport;
use Throwable;

class Setting extends Model
{
    /**
     * -----------------------------------------------------------
     * Fillable Fields — Safe for Mass Assignment
     * -----------------------------------------------------------
     */
    protected $fillable = [
        'name',
        'email',
        'contact_no',
        'address',
        'smtp_host',
        'smtp_port',
        'smtp_username',
        'smtp_password',
        'smtp_encryption',
        'smtp_scheme',
        'smtp_from_address',
        'smtp_from_name',
        'system_logo',
        'email_logo',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'system_logo' => 'assets/images/logo-light.png',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'smtp_password' => 'encrypted',
            'smtp_port' => 'integer',
        ];
    }

    /**
     * @var list<string>
     */
    protected $hidden = [
        'smtp_password',
    ];

    protected $appends = ['system_logo_url', 'email_logo_url'];

    public function getSystemLogoUrlAttribute(): ?string
    {
        return self::logoUrl($this->system_logo);
    }

    public function getEmailLogoUrlAttribute(): ?string
    {
        return self::logoUrl($this->email_logo);
    }

    public static function instance(): self
    {
        return static::query()->firstOrCreate(
            ['id' => 1],
            [
                'name' => config('app.name'),
                'email' => config('mail.from.address'),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function formatForResponse(self $setting): array
    {
        return [
            'id' => $setting->id,
            'name' => $setting->name,
            'email' => $setting->email,
            'contact_no' => $setting->contact_no,
            'address' => $setting->address,
            'system_logo' => $setting->system_logo,
            'system_logo_url' => self::logoUrl($setting->system_logo),
            'email_logo' => $setting->email_logo,
            'email_logo_url' => self::logoUrl($setting->email_logo),
            'smtp_host' => $setting->smtp_host,
            'smtp_port' => $setting->smtp_port,
            'smtp_username' => $setting->smtp_username,
            'smtp_encryption' => $setting->smtp_encryption ?: '',
            'smtp_scheme' => $setting->smtp_scheme ?: 'smtp',
            'smtp_from_address' => $setting->smtp_from_address,
            'smtp_from_name' => $setting->smtp_from_name,
            'has_smtp_password' => filled($setting->smtp_password),
            'smtp_password' => '',
        ];
    }

    public static function logoUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (str_starts_with($path, '/')) {
            return asset(ltrim($path, '/'));
        }

        return asset($path);
    }

    public static function updateFromRequest(Request $request, self $setting): self
    {
        $attributes = $request->only([
            'name',
            'email',
            'contact_no',
            'address',
            'system_logo',
            'email_logo',
            'smtp_host',
            'smtp_port',
            'smtp_username',
            'smtp_encryption',
            'smtp_scheme',
            'smtp_from_address',
            'smtp_from_name',
            'smtp_password',
        ]);

        if (! filled($attributes['smtp_password'] ?? null)) {
            unset($attributes['smtp_password']);
        }

        if (array_key_exists('smtp_port', $attributes) && ($attributes['smtp_port'] === '' || $attributes['smtp_port'] === null)) {
            $attributes['smtp_port'] = null;
        }

        if (array_key_exists('smtp_encryption', $attributes) && in_array($attributes['smtp_encryption'], ['', 'null', 'none'], true)) {
            $attributes['smtp_encryption'] = null;
        }

        if (array_key_exists('smtp_scheme', $attributes) && in_array($attributes['smtp_scheme'], ['', 'null'], true)) {
            $attributes['smtp_scheme'] = 'smtp';
        }

        $setting->fill($attributes);
        $setting->save();

        return $setting;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{host: mixed, port: int, username: mixed, password: mixed, encryption: mixed, scheme: string, from_address: mixed, from_name: mixed}
     */
    public static function smtpParameters(?self $setting = null, array $overrides = []): array
    {
        $setting ??= static::query()->first();

        $encryption = self::firstFilled(
            $overrides['smtp_encryption'] ?? null,
            $setting?->smtp_encryption,
        );
        if (in_array($encryption, ['null', 'none', ''], true)) {
            $encryption = null;
        }

        $scheme = self::firstFilled(
            $overrides['smtp_scheme'] ?? null,
            $setting?->smtp_scheme,
        );
        if (in_array($scheme, [null, '', 'null'], true)) {
            $scheme = $encryption === 'ssl' ? 'smtps' : 'smtp';
        }

        $password = $overrides['smtp_password'] ?? null;
        if (! filled($password)) {
            $password = $setting?->smtp_password;
        }

        return [
            'host' => self::firstFilled($overrides['smtp_host'] ?? null, $setting?->smtp_host),
            'port' => (int) self::firstFilled($overrides['smtp_port'] ?? null, $setting?->smtp_port, 587),
            'username' => self::firstFilled($overrides['smtp_username'] ?? null, $setting?->smtp_username),
            'password' => $password,
            'encryption' => $encryption,
            'scheme' => (string) $scheme,
            'from_address' => self::firstFilled(
                $overrides['smtp_from_address'] ?? null,
                $setting?->smtp_from_address,
                $setting?->email,
            ),
            'from_name' => self::firstFilled(
                $overrides['smtp_from_name'] ?? null,
                $setting?->smtp_from_name,
                $setting?->name,
            ),
        ];
    }

    public static function applyAppConfig(?self $setting = null): void
    {
        $setting ??= static::query()->first();
        $name = trim((string) ($setting?->name ?? ''));

        if ($name === '') {
            return;
        }

        config(['app.name' => $name]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function applyMailConfig(?self $setting = null, array $overrides = []): void
    {
        $setting ??= static::query()->first();
        $host = $overrides['smtp_host'] ?? $setting?->smtp_host;

        if (! filled($host)) {
            return;
        }

        $smtp = self::smtpParameters($setting, $overrides);

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.scheme' => $smtp['scheme'],
            'mail.mailers.smtp.host' => $smtp['host'],
            'mail.mailers.smtp.port' => $smtp['port'],
            'mail.mailers.smtp.username' => $smtp['username'],
            'mail.mailers.smtp.password' => $smtp['password'],
            'mail.mailers.smtp.encryption' => $smtp['encryption'],
            'mail.from.address' => $smtp['from_address'] ?: config('mail.from.address'),
            'mail.from.name' => $smtp['from_name'] ?: config('mail.from.name'),
        ]);

        Mail::purge('smtp');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{ok: bool, message: string}
     */
    public static function testSmtpConnection(?self $setting = null, array $overrides = []): array
    {
        $smtp = self::smtpParameters($setting, $overrides);

        if (! filled($smtp['host']) || ! filled($smtp['username']) || $smtp['password'] === null || $smtp['password'] === '') {
            return [
                'ok' => false,
                'message' => 'SMTP settings are not configured.',
            ];
        }

        try {
            $dsn = sprintf(
                '%s://%s:%s@%s:%d',
                $smtp['scheme'] === 'smtps' ? 'smtps' : 'smtp',
                urlencode((string) $smtp['username']),
                urlencode((string) $smtp['password']),
                $smtp['host'],
                $smtp['port'],
            );

            if (in_array($smtp['encryption'], ['tls', 'ssl'], true)) {
                $dsn .= '?encryption='.$smtp['encryption'];
            }

            $transport = Transport::fromDsn($dsn);
            $transport->start();

            return [
                'ok' => true,
                'message' => 'SMTP connection successful!',
            ];
        } catch (TransportExceptionInterface $e) {
            return [
                'ok' => false,
                'message' => 'SMTP transport error: '.$e->getMessage(),
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'message' => 'General error: '.$e->getMessage(),
            ];
        }
    }

    /**
     * -----------------------------------------------------------
     * Static Factory: Create or Update System Settings
     * -----------------------------------------------------------
     */
    public static function createOrUpdateFromRequest($request): self
    {
        $setting = static::instance();

        return self::updateFromRequest($request, $setting);
    }

    protected static function booted(): void
    {
        static::saved(function (self $setting): void {
            forgetSettingCache();
            self::applyAppConfig($setting);
            self::applyMailConfig($setting);
        });
        static::deleted(fn () => forgetSettingCache());
    }

    private static function firstFilled(mixed ...$values): mixed
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
