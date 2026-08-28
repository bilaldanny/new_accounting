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

        if (! filled($setting->smtp_scheme)) {
            $setting->smtp_scheme = $scheme;
        }

        if (! filled($setting->smtp_from_address)) {
            $setting->smtp_from_address = config('mail.from.address');
        }

        if (! filled($setting->smtp_from_name)) {
            $setting->smtp_from_name = config('mail.from.name');
        }

        $setting->save();
    }

    public function down(): void
    {
        //
    }
};
