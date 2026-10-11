<?php

namespace App\Services;

use App\Models\MessagingSetting;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends a WhatsApp or SMS text through the provider the company configured. Only a `webhook` provider is built: the message is
 * posted as JSON {channel, to, from, text} to the gateway endpoint with the credentials as a bearer token, so any gateway that
 * accepts a webhook (an SMS aggregator, a WhatsApp Business gateway) can be connected without code. With no provider configured
 * nothing is sent and the result says so: a message is never reported as sent when it was not.
 */
class MessageDispatcher
{
    /**
     * @return array{status: string, error: ?string}
     */
    public function send(int $companyId, string $channel, string $recipient, string $text): array
    {
        $setting = MessagingSetting::query()->where('company_id', $companyId)->where('channel', $channel)->first();

        if ($setting === null || ! $setting->is_active || $setting->provider === 'none' || blank($setting->endpoint)) {
            return ['status' => 'not_configured', 'error' => 'No '.strtoupper($channel).' provider is configured. Set one up in Messaging Settings.'];
        }

        try {
            $request = Http::acceptJson()->timeout(15);

            if (filled($setting->credentials)) {
                $request = $request->withToken((string) $setting->credentials);
            }

            $response = $request->post((string) $setting->endpoint, ['channel' => $channel, 'to' => $recipient, 'from' => $setting->sender, 'text' => $text]);

            return $response->successful()
                ? ['status' => 'sent', 'error' => null]
                : ['status' => 'failed', 'error' => 'The gateway answered '.$response->status().'.'];
        } catch (Throwable $e) {
            return ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 400)];
        }
    }
}
