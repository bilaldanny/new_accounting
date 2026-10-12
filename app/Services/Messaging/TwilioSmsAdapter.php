<?php

namespace App\Services\Messaging;

use App\Models\MessagingSetting;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Twilio Programmable Messaging: POST https://api.twilio.com/2010-04-01/Accounts/{AccountSid}/Messages.json with HTTP basic auth
 * (Account SID and Auth Token) and form-encoded To, From (or MessagingServiceSid) and Body. The setting's credentials are
 * `AccountSid:AuthToken`; `sender` is the sending number (E.164) or a Messaging Service SID (MG...).
 */
class TwilioSmsAdapter implements MessagingAdapter
{
    public function isConfigured(MessagingSetting $setting): bool
    {
        return filled($setting->sender) && str_contains((string) $setting->credentials, ':');
    }

    public function send(MessagingSetting $setting, string $recipient, string $text): array
    {
        if (! $this->isConfigured($setting)) {
            return ['status' => 'not_configured', 'error' => 'Enter the Twilio Account SID and Auth Token as SID:token, and the sending number.'];
        }

        [$sid, $token] = explode(':', (string) $setting->credentials, 2);
        $options = (array) ($setting->options ?? []);
        $digits = PhoneNumber::digits($recipient, (string) ($options['country_code'] ?? '92'));

        if ($digits === null) {
            return ['status' => 'failed', 'error' => 'The phone number is not valid.'];
        }

        $sender = (string) $setting->sender;
        $form = ['To' => '+'.$digits, 'Body' => mb_substr($text, 0, 1600)] + (str_starts_with($sender, 'MG') ? ['MessagingServiceSid' => $sender] : ['From' => $sender]);
        $base = rtrim((string) ($setting->endpoint ?: 'https://api.twilio.com'), '/');

        try {
            $response = Http::withBasicAuth($sid, $token)->acceptJson()->asForm()->timeout(20)->post("{$base}/2010-04-01/Accounts/{$sid}/Messages.json", $form);
        } catch (Throwable $e) {
            return ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 400)];
        }

        if ($response->successful()) {
            return ['status' => 'sent', 'error' => null, 'provider_id' => $response->json('sid')];
        }

        return ['status' => 'failed', 'error' => 'Twilio refused the message'.($response->json('code') ? ' (code '.$response->json('code').')' : '').': '.mb_substr((string) ($response->json('message') ?? 'HTTP '.$response->status()), 0, 300)];
    }
}
