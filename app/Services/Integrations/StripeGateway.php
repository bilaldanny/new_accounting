<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Stripe Checkout, from Stripe's published API: `POST /v1/checkout/sessions` (form encoded, secret key as bearer token, amounts in
 * the currency's minor unit) returns a hosted `url`; the webhook carries `Stripe-Signature: t=<time>,v1=<hmac>` where the HMAC is
 * SHA-256 of `<time>.<payload>` with the endpoint's signing secret, valid for five minutes. `checkout.session.completed` with
 * `payment_status` paid is the paid event. Not exercised against a live Stripe account here; recorded fixtures only.
 */
class StripeGateway implements PaymentGateway
{
    public const TOLERANCE = 300;

    public function createCheckout(array $credentials, float $amount, string $currency, string $reference, string $description, string $successUrl, string $cancelUrl): array
    {
        $key = (string) ($credentials['secret_key'] ?? '');

        if ($key === '') {
            return ['ok' => false, 'external_id' => null, 'checkout_url' => null, 'error' => 'Enter the Stripe secret key.'];
        }

        try {
            $response = Http::withToken($key)->acceptJson()->asForm()->timeout(20)->post('https://api.stripe.com/v1/checkout/sessions', [
                'mode' => 'payment', 'success_url' => $successUrl, 'cancel_url' => $cancelUrl, 'client_reference_id' => $reference,
                'line_items' => [['quantity' => 1, 'price_data' => ['currency' => strtolower($currency), 'unit_amount' => (int) round($amount * 100), 'product_data' => ['name' => $description]]]],
            ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'external_id' => null, 'checkout_url' => null, 'error' => mb_substr($e->getMessage(), 0, 300)];
        }

        if ($response->successful() && $response->json('url') !== null) {
            return ['ok' => true, 'external_id' => (string) $response->json('id'), 'checkout_url' => (string) $response->json('url'), 'error' => null];
        }

        return ['ok' => false, 'external_id' => null, 'checkout_url' => null, 'error' => 'Stripe refused the checkout: '.mb_substr((string) ($response->json('error.message') ?? 'HTTP '.$response->status()), 0, 250)];
    }

    public function readWebhook(array $credentials, string $payload, string $signatureHeader, ?int $now = null): ?array
    {
        $secret = (string) ($credentials['webhook_secret'] ?? '');
        $parts = [];

        foreach (explode(',', $signatureHeader) as $piece) {
            [$key, $value] = array_pad(explode('=', trim($piece), 2), 2, '');
            $parts[$key][] = $value;
        }

        $time = (int) ($parts['t'][0] ?? 0);

        if ($secret === '' || $time === 0 || abs(($now ?? time()) - $time) > self::TOLERANCE) {
            return null;
        }

        $expected = hash_hmac('sha256', $time.'.'.$payload, $secret);

        if (! collect($parts['v1'] ?? [])->contains(fn (string $candidate): bool => hash_equals($expected, $candidate))) {
            return null;
        }

        $event = json_decode($payload, true) ?: [];
        $object = (array) ($event['data']['object'] ?? []);

        return [
            'type' => (string) ($event['type'] ?? ''), 'external_id' => (string) ($object['id'] ?? ''),
            'paid' => ($event['type'] ?? '') === 'checkout.session.completed' && ($object['payment_status'] ?? '') === 'paid',
            'reference' => $object['client_reference_id'] ?? null, 'amount' => isset($object['amount_total']) ? round($object['amount_total'] / 100, 2) : null,
        ];
    }
}
