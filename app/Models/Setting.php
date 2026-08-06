<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

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

        // Starting IDs
        'start_course_id',
        'start_program_id',
        'start_student_id',
        'start_invoice_id',
        'start_order_id',
        'shipping_charges',

        // Currency
        'currency',
        'currency_symbol',

        // SMTP Settings
        'smtp_host',
        'smtp_port',
        'smtp_username',
        'smtp_password',
        'smtp_encryption',
        'smtp_from_address',
        'smtp_from_name',

        // System Logos
        'system_logo',
        'invoice_logo',
        'invoice_paid_stamp',
        'signature',

        // Authorize.net
        'authorize_api_login_id',
        'authorize_transaction_key',
        'authorize_signature_key',
        'authorize_environment',

        // Static Content
        'agreement',
        'welcome',
        'student_registration',
        'email_validation',
        'exam_introduction',
        'certify_exam',
        'support_content',
        'support_heading',
        'support_videos_heading',
        'support_videos',
    ];

    /**
     * -----------------------------------------------------------
     * Default Attribute Values
     * -----------------------------------------------------------
     */
    protected $attributes = [
        'currency' => 'USD',
        'currency_symbol' => '$',
        'system_logo' => 'assets/images/logo-light.png',
        'invoice_logo' => 'assets/images/logo-light.png',
        'invoice_paid_stamp' => 'assets/images/logo-light.png',
        'authorize_environment' => 'sandbox',
        'shipping_charges' => 0,
        'support_content' => '',
        'support_heading' => '',
        'support_videos_heading' => '',
        'support_videos' => '[]',
    ];

    /**
     * -----------------------------------------------------------
     * Type Casting and Automatic Encryption
     * -----------------------------------------------------------
     */
    protected $casts = [
        // Encrypted fields
        'smtp_password' => 'encrypted',
        'authorize_api_login_id' => 'encrypted',
        'authorize_transaction_key' => 'encrypted',
        'authorize_signature_key' => 'encrypted',

        // Integer IDs
        // 'start_course_id' => 'integer',
        // 'start_program_id' => 'integer',
        // 'start_student_id' => 'integer',
        // 'start_invoice_id' => 'integer',
        // 'start_order_id' => 'integer',
        'shipping_charges' => 'decimal:2',

        // JSON fields
        'support_videos' => 'array',
    ];

    protected $appends = ['email_templates', 'system_logo_url'];

    protected function emailTemplates(): Attribute
    {
        return Attribute::make(
            get: fn () => EmailTemplate::all()
        );
    }

    /**
     * -----------------------------------------------------------
     * Accessors for Logo URLs
     * -----------------------------------------------------------
     */
    public function getSystemLogoUrlAttribute(): string
    {
        return $this->system_logo
            ? $this->resolveStorageAssetUrl($this->system_logo)
            : asset('images/default-logo.png');
    }

    public function getInvoiceLogoUrlAttribute(): string
    {
        return $this->invoice_logo
            ? $this->resolveStorageAssetUrl($this->invoice_logo)
            : asset('images/default-invoice-logo.png');
    }

    public function getInvoicePaidStampUrlAttribute(): string
    {
        return $this->invoice_paid_stamp
            ? $this->resolveStorageAssetUrl($this->invoice_paid_stamp)
            : asset('images/default-paid.png');
    }

    private function resolveStorageAssetUrl(?string $path): string
    {
        $normalizedPath = ltrim((string) $path, '/');
        $normalizedPath = (string) preg_replace('#^(storage/)+#i', '', $normalizedPath);

        return Storage::url($normalizedPath);
    }

    /**
     * -----------------------------------------------------------
     * Helper: Format Currency
     * -----------------------------------------------------------
     */
    public function formatCurrency($amount): string
    {
        return $this->currency_symbol.number_format($amount, 2);
    }

    /**
     * -----------------------------------------------------------
     * Static Factory: Create or Update System Settings
     * -----------------------------------------------------------
     */
    public static function createOrUpdateFromRequest($request): self
    {
        $setting = static::firstOrNew(['id' => 1]);

        // Mass-assign only fillable fields
        $setting->fill($request->only($setting->getFillable()));

        $setting->save();

        forgetSettingCache();

        return $setting;
    }

    protected static function booted(): void
    {
        static::saved(fn () => forgetSettingCache());
        static::deleted(fn () => forgetSettingCache());
    }
}
