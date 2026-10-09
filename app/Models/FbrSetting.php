<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company's FBR e-invoicing switch and credentials. The structure is ready but there is no live integration: the secrets
 * are stored encrypted, empty by default, and nothing reads them yet except a future live gateway.
 */
class FbrSetting extends Model
{
    public const ENVIRONMENTS = ['sandbox', 'production'];

    protected $fillable = [
        'company_id',
        'enabled',
        'environment',
        'pos_id',
        'api_url',
        'username',
        'password',
        'api_token',
    ];

    protected $hidden = ['password', 'api_token'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'password' => 'encrypted',
            'api_token' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function forCompany(int $companyId): self
    {
        return self::query()->firstOrNew(['company_id' => $companyId], ['enabled' => false, 'environment' => 'sandbox']);
    }

    /**
     * What the settings page shows: everything but the secrets, which only say whether they are set.
     *
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'company_id' => $this->company_id,
            'enabled' => (bool) $this->enabled,
            'environment' => $this->environment ?: 'sandbox',
            'pos_id' => $this->pos_id,
            'api_url' => $this->api_url,
            'username' => $this->username,
            'has_password' => filled($this->password),
            'has_api_token' => filled($this->api_token),
        ];
    }

    /**
     * Whether everything a live connection would need has been entered. Nothing here is verified against FBR: this only says
     * the fields are not empty.
     */
    public function hasCredentials(): bool
    {
        return filled($this->username) && filled($this->password) && filled($this->api_token) && filled($this->pos_id);
    }
}
