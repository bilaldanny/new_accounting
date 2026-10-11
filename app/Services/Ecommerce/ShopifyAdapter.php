<?php

namespace App\Services\Ecommerce;

use App\Models\EcommerceConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Shopify Admin REST API (`orders.json`), read-only. Needs the shop domain (store_url) and an Admin API access token in the
 * connection's credentials (`access_token`). It is not exercised against a live shop in the tests; the HTTP layer is faked there.
 */
class ShopifyAdapter implements EcommerceAdapter
{
    public function fetchOrders(EcommerceConnection $connection, ?Carbon $since): array
    {
        $token = (string) ($connection->credentials['access_token'] ?? '');

        if ($token === '' || blank($connection->store_url)) {
            throw new EcommerceNotConfigured('Enter the Shopify store address and its Admin API access token.');
        }

        $response = Http::withHeaders(['X-Shopify-Access-Token' => $token])->acceptJson()->timeout(20)->get(
            rtrim((string) $connection->store_url, '/').'/admin/api/2024-01/orders.json',
            array_filter(['status' => 'any', 'limit' => 100, 'created_at_min' => $since?->toIso8601String()]),
        );

        if (! $response->successful()) {
            throw new EcommerceNotConfigured('Shopify answered '.$response->status().'.');
        }

        return collect($response->json('orders', []))->map(fn (array $order): array => [
            'external_id' => (string) ($order['id'] ?? ''),
            'placed_at' => substr((string) ($order['created_at'] ?? now()->toDateString()), 0, 10),
            'customer' => [
                'name' => trim((string) ($order['customer']['first_name'] ?? '').' '.(string) ($order['customer']['last_name'] ?? '')),
                'email' => $order['email'] ?? null, 'phone' => $order['phone'] ?? null,
            ],
            'lines' => collect($order['line_items'] ?? [])->map(fn (array $line): array => [
                'sku' => (string) ($line['sku'] ?? ''), 'name' => $line['name'] ?? null, 'quantity' => (float) ($line['quantity'] ?? 0), 'unit_price' => isset($line['price']) ? (float) $line['price'] : null,
            ])->all(),
            'shipping' => (float) collect($order['shipping_lines'] ?? [])->sum(fn (array $line): float => (float) ($line['price'] ?? 0)),
            'total' => (float) ($order['total_price'] ?? 0),
        ])->filter(fn (array $order): bool => $order['external_id'] !== '')->values()->all();
    }
}
