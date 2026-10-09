<?php

namespace App\Services;

use App\Jobs\DispatchWebhook;
use App\Models\Webhook;

/**
 * Fires a basic event trigger to every active webhook a company has registered for it. Kept as the one
 * place that knows how to fan an event out to a company's webhooks, so a new event trigger is one call
 * site, not a repeated webhook-lookup-and-dispatch block wherever an event happens.
 */
class WebhookDispatcher
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fire(int $companyId, string $event, array $payload): void
    {
        Webhook::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->get()
            ->filter(fn (Webhook $webhook) => $webhook->subscribesTo($event))
            ->each(function (Webhook $webhook) use ($event, $payload): void {
                DispatchWebhook::dispatch($webhook->id, $event, $payload);
            });
    }
}
