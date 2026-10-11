<?php

namespace App\Models;

use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * The WhatsApp / SMS provider of a company. `webhook` posts each message as JSON to the gateway endpoint (with the
 * credentials as a bearer token); `none` means no provider is configured and nothing is ever sent.
 */
class MessagingSetting extends Model
{
    use ScopedToCompany;

    public const PROVIDERS = ['none', 'webhook'];

    public const CHANNELS = ['whatsapp', 'sms'];

    protected $hidden = ['credentials'];

    protected $fillable = ['company_id', 'channel', 'provider', 'endpoint', 'credentials', 'sender', 'is_active'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted', 'is_active' => 'boolean'];
    }
}
