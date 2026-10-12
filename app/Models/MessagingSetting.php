<?php

namespace App\Models;

use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * The WhatsApp / SMS provider of a company. `webhook` posts each message as JSON to the gateway endpoint (with the
 * credentials as a bearer token); `whatsapp_cloud` is the Meta WhatsApp Business Cloud API and `twilio` Twilio SMS (see
 * Services\Messaging); `none` means no provider is configured and nothing is ever sent.
 */
class MessagingSetting extends Model
{
    use ScopedToCompany;

    public const PROVIDERS = ['none', 'webhook', 'whatsapp_cloud', 'twilio'];

    public const CHANNELS = ['whatsapp', 'sms'];

    protected $hidden = ['credentials'];

    protected $fillable = ['company_id', 'channel', 'provider', 'endpoint', 'credentials', 'sender', 'is_active', 'options'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted', 'is_active' => 'boolean', 'options' => 'array'];
    }
}
