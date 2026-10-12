<?php

namespace App\Services;

use App\Models\MessagingSetting;
use App\Services\Messaging\MessagingAdapter;
use App\Services\Messaging\TwilioSmsAdapter;
use App\Services\Messaging\WebhookAdapter;
use App\Services\Messaging\WhatsAppCloudAdapter;

/**
 * Sends a WhatsApp or SMS text through the provider the company configured: a generic `webhook`, the Meta WhatsApp Cloud API
 * (`whatsapp_cloud`) or Twilio SMS (`twilio`). With no provider configured nothing is sent and the result says so: a message is
 * never reported as sent when it was not.
 */
class MessageDispatcher
{
    public static function adapterFor(string $provider): ?MessagingAdapter
    {
        return match ($provider) {
            'webhook' => new WebhookAdapter,
            'whatsapp_cloud' => new WhatsAppCloudAdapter,
            'twilio' => new TwilioSmsAdapter,
            default => null,
        };
    }

    /**
     * @return array{status: string, error: ?string, provider_id?: ?string}
     */
    public function send(int $companyId, string $channel, string $recipient, string $text): array
    {
        $setting = MessagingSetting::query()->where('company_id', $companyId)->where('channel', $channel)->first();

        if ($setting === null || ! $setting->is_active || $setting->provider === 'none') {
            return ['status' => 'not_configured', 'error' => 'No '.strtoupper($channel).' provider is configured. Set one up in Messaging Settings.'];
        }

        $adapter = self::adapterFor((string) $setting->provider);

        if ($adapter === null || ! $adapter->isConfigured($setting)) {
            return ['status' => 'not_configured', 'error' => 'No '.strtoupper($channel).' provider is configured. Set one up in Messaging Settings.'];
        }

        return $adapter->send($setting, $recipient, $text);
    }
}
