<?php

namespace App\Services\Messaging;

use App\Models\MessagingSetting;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The generic provider: the message is posted as JSON {channel, to, from, text} to the gateway endpoint with the credentials as a
 * bearer token, so any gateway that accepts a webhook can be connected without code.
 */
class WebhookAdapter implements MessagingAdapter
{
    public function isConfigured(MessagingSetting $setting): bool
    {
        return filled($setting->endpoint);
    }

    public function send(MessagingSetting $setting, string $recipient, string $text): array
    {
        if (! $this->isConfigured($setting)) {
            return ['status' => 'not_configured', 'error' => 'No endpoint is configured for the webhook provider.'];
        }

        try {
            $request = Http::acceptJson()->timeout(15);

            if (filled($setting->credentials)) {
                $request = $request->withToken((string) $setting->credentials);
            }

            $response = $request->post((string) $setting->endpoint, ['channel' => $setting->channel, 'to' => $recipient, 'from' => $setting->sender, 'text' => $text]);

            return $response->successful()
                ? ['status' => 'sent', 'error' => null]
                : ['status' => 'failed', 'error' => 'The gateway answered '.$response->status().'.'];
        } catch (Throwable $e) {
            return ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 400)];
        }
    }
}
