<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $setting = Setting::instance();
        $mailer = config('mail.mailers.smtp', []);
        $scheme = $mailer['scheme'] ?? 'smtp';

        if (in_array($scheme, [null, '', 'null'], true)) {
            $scheme = 'smtp';
        }

        $port = (int) ($mailer['port'] ?? 587);
        $encryption = $setting->smtp_encryption;

        if (! filled($encryption)) {
            $encryption = match (true) {
                $scheme === 'smtps', $port === 465 => 'ssl',
                $port === 587 => 'tls',
                default => null,
            };
        }

        if (! filled($setting->smtp_host)) {
            $setting->smtp_host = $mailer['host'] ?? null;
        }

        if (! filled($setting->smtp_port)) {
            $setting->smtp_port = $port > 0 ? $port : null;
        }

        if (! filled($setting->smtp_username)) {
            $setting->smtp_username = $mailer['username'] ?? null;
        }

        if (! filled($setting->smtp_scheme)) {
            $setting->smtp_scheme = $scheme;
        }

        if (! filled($setting->smtp_encryption)) {
            $setting->smtp_encryption = $encryption;
        }

        if (! filled($setting->smtp_from_address)) {
            $setting->smtp_from_address = config('mail.from.address');
        }

        if (! filled($setting->smtp_from_name)) {
            $setting->smtp_from_name = config('mail.from.name');
        }

        if (filled($mailer['password'] ?? null) && ! filled($setting->smtp_password)) {
            $setting->smtp_password = $mailer['password'];
        }

        $setting->save();
    }

    public function down(): void
    {
        // Mail credentials copied from config are left in place.
    }
};
