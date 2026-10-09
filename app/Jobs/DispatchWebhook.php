<?php

namespace App\Jobs;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * POSTs one event payload to one registered webhook URL and records the attempt. The first real
 * job-dispatch feature in this app (QUEUE_CONNECTION is already `database`, but nothing used
 * `app/Jobs/` before this). Deliberately simple per this build's scope ("simple rakho"): one attempt,
 * no retry loop — `failure_count` on the webhook is there for an admin to notice and re-register the
 * endpoint, not for the job itself to keep hammering a dead URL.
 */
class DispatchWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $webhookId,
        public string $event,
        public array $payload,
    ) {}

    public function handle(): void
    {
        $webhook = Webhook::query()->find($this->webhookId);

        if ($webhook === null || ! $webhook->subscribesTo($this->event)) {
            return;
        }

        $body = [
            'event' => $this->event,
            'data' => $this->payload,
            'sent_at' => now()->toIso8601String(),
        ];

        $signature = hash_hmac('sha256', json_encode($body), (string) $webhook->secret);

        $responseCode = null;
        $responseBody = null;
        $success = false;

        try {
            $response = Http::timeout(10)
                ->withHeaders(['X-Webhook-Signature' => $signature])
                ->post($webhook->url, $body);

            $responseCode = $response->status();
            $responseBody = substr($response->body(), 0, 2000);
            $success = $response->successful();
        } catch (Throwable $exception) {
            $responseBody = substr($exception->getMessage(), 0, 2000);
        }

        WebhookDelivery::query()->create([
            'webhook_id' => $webhook->id,
            'event' => $this->event,
            'payload' => $body,
            'response_code' => $responseCode,
            'response_body' => $responseBody,
            'success' => $success,
            'attempted_at' => now(),
        ]);

        $webhook->last_triggered_at = now();
        $webhook->last_response_code = $responseCode;
        $webhook->failure_count = $success ? 0 : $webhook->failure_count + 1;
        $webhook->save();
    }
}
