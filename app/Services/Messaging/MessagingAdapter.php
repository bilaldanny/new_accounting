<?php

namespace App\Services\Messaging;

use App\Models\MessagingSetting;

/**
 * One way of sending a text through a provider. Always answers with a status (sent, failed, not_configured), never throws: a
 * message is never reported as sent when the provider did not accept it.
 */
interface MessagingAdapter
{
    /**
     * @return array{status: string, error: ?string, provider_id?: ?string}
     */
    public function send(MessagingSetting $setting, string $recipient, string $text): array;

    public function isConfigured(MessagingSetting $setting): bool;
}
