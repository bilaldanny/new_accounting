<?php

namespace App\Services\Messaging;

use App\Models\MessagingSetting;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Meta WhatsApp Business Cloud API: POST https://graph.facebook.com/{version}/{phone-number-id}/messages with the permanent access
 * token as a bearer token. The setting's `sender` is the phone number id and `credentials` the access token. The options may name
 * an approved template (a business may only open a conversation with a template; free text is accepted only inside the 24 hours
 * after the customer last wrote), its language, the Graph API version and the default country calling code.
 *
 * With a template the text is sent as its first body parameter; without one it is a plain text message.
 */
class WhatsAppCloudAdapter implements MessagingAdapter
{
    public const DEFAULT_VERSION = 'v21.0';

    public function isConfigured(MessagingSetting $setting): bool
    {
        return filled($setting->sender) && filled($setting->credentials);
    }

    public function send(MessagingSetting $setting, string $recipient, string $text): array
    {
        if (! $this->isConfigured($setting)) {
            return ['status' => 'not_configured', 'error' => 'Enter the WhatsApp phone number id (Sender) and the access token.'];
        }

        $options = (array) ($setting->options ?? []);
        $to = PhoneNumber::digits($recipient, (string) ($options['country_code'] ?? '92'));

        if ($to === null) {
            return ['status' => 'failed', 'error' => 'The phone number is not valid.'];
        }

        $version = (string) ($options['api_version'] ?? self::DEFAULT_VERSION);
        $base = rtrim((string) ($setting->endpoint ?: 'https://graph.facebook.com'), '/');
        $template = (string) ($options['template'] ?? '');

        $body = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to];

        if ($template !== '') {
            $body += [
                'type' => 'template',
                'template' => [
                    'name' => $template,
                    'language' => ['code' => (string) ($options['language'] ?? 'en')],
                    'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => mb_substr($text, 0, 1024)]]]],
                ],
            ];
        } else {
            $body += ['type' => 'text', 'text' => ['preview_url' => true, 'body' => mb_substr($text, 0, 4096)]];
        }

        try {
            $response = Http::withToken((string) $setting->credentials)->acceptJson()->asJson()->timeout(20)
                ->post("{$base}/{$version}/{$setting->sender}/messages", $body);
        } catch (Throwable $e) {
            return ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 400)];
        }

        if ($response->successful()) {
            return ['status' => 'sent', 'error' => null, 'provider_id' => $response->json('messages.0.id')];
        }

        $error = (array) $response->json('error', []);

        return ['status' => 'failed', 'error' => 'WhatsApp refused the message'.(isset($error['code']) ? " (code {$error['code']})" : '').': '.mb_substr((string) ($error['message'] ?? 'HTTP '.$response->status()), 0, 300)];
    }
}
