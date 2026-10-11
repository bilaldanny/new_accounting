<?php

namespace App\Services\Ecommerce;

use App\Models\EcommerceConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * WooCommerce REST API (`wc/v3/orders`), read-only, with the consumer key and secret in the connection's credentials. Like the
 * Shopify adapter it is not exercised against a live shop in the tests.
 */
class WooCommerceAdapter implements EcommerceAdapter
{
    public function fetchOrders(EcommerceConnection $connection, ?Carbon $since): array
    {
        $key = (string) ($connection->credentials['consumer_key'] ?? '');
        $secret = (string) ($connection->credentials['consumer_secret'] ?? '');

        if ($key === '' || $secret === '' || blank($connection->store_url)) {
            throw new EcommerceNotConfigured('Enter the WooCommerce store address, consumer key and consumer secret.');
        }

        $response = Http::withBasicAuth($key, $secret)->acceptJson()->timeout(20)->get(
            rtrim((string) $connection->store_url, '/').'/wp-json/wc/v3/orders',
            array_filter(['per_page' => 100, 'after' => $since?->toIso8601String()]),
        );

        if (! $response->successful()) {
            throw new EcommerceNotConfigured('WooCommerce answered '.$response->status().'.');
        }

        return collect($response->json() ?? [])->map(fn (array $order): array => [
            'external_id' => (string) ($order['id'] ?? ''),
            'placed_at' => substr((string) ($order['date_created'] ?? now()->toDateString()), 0, 10),
            'customer' => [
                'name' => trim((string) ($order['billing']['first_name'] ?? '').' '.(string) ($order['billing']['last_name'] ?? '')),
                'email' => $order['billing']['email'] ?? null, 'phone' => $order['billing']['phone'] ?? null,
            ],
            'lines' => collect($order['line_items'] ?? [])->map(fn (array $line): array => [
                'sku' => (string) ($line['sku'] ?? ''), 'name' => $line['name'] ?? null, 'quantity' => (float) ($line['quantity'] ?? 0),
                'unit_price' => ($line['quantity'] ?? 0) > 0 ? round((float) ($line['subtotal'] ?? 0) / (float) $line['quantity'], 4) : null,
            ])->all(),
            'shipping' => (float) ($order['shipping_total'] ?? 0),
            'total' => (float) ($order['total'] ?? 0),
        ])->filter(fn (array $order): bool => $order['external_id'] !== '')->values()->all();
    }
}
