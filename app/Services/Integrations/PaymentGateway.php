<?php

namespace App\Services\Integrations;

/**
 * A card / online payment gateway: create a hosted checkout for an amount, and read the signed notification the gateway sends
 * when it is paid. The application never holds card data; a paid notification only marks the invoice paid (manual-payment records).
 */
interface PaymentGateway
{
    /**
     * @param  array<string, mixed>  $credentials
     * @return array{ok: bool, external_id: ?string, checkout_url: ?string, error: ?string}
     */
    public function createCheckout(array $credentials, float $amount, string $currency, string $reference, string $description, string $successUrl, string $cancelUrl): array;

    /**
     * Verifies a webhook and returns its meaning, or null when the signature is not valid.
     *
     * @param  array<string, mixed>  $credentials
     * @return array{type: string, external_id: string, paid: bool, reference: ?string, amount: ?float}|null
     */
    public function readWebhook(array $credentials, string $payload, string $signatureHeader, ?int $now = null): ?array;
}
