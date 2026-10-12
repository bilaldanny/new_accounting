<?php

namespace App\Services\Ecommerce;

use App\Models\EcommerceConnection;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;

/**
 * WooCommerce REST API v3, orders (`GET /wp-json/wc/v3/orders`), read-only.
 *
 * Follows the documented behaviour: the consumer key and secret go as HTTP basic auth over HTTPS (over plain HTTP WooCommerce
 * wants OAuth 1.0a, which is not offered here, so an http:// store is refused unless it is localhost); `per_page` up to 100 and
 * `page`, the number of pages being in the `X-WP-TotalPages` header; `after` limits to orders created after an ISO 8601 date;
 * `orderby=date&order=asc`. Orders that are cancelled, failed, refunded, trashed or only a checkout draft are flagged and not
 * imported. A line's unit price is its `total` (after coupons, before tax) over its quantity.
 *
 * Needs the store address, a key pair with read permission (WooCommerce > Settings > Advanced > REST API).
 */
class WooCommerceAdapter implements EcommerceAdapter
{
    private const MAX_PAGES = 50;

    private const NOT_IMPORTED = ['cancelled', 'failed', 'refunded', 'trash', 'checkout-draft'];

    public function fetchOrders(EcommerceConnection $connection, ?CarbonInterface $since): array
    {
        $key = (string) ($connection->credentials['consumer_key'] ?? '');
        $secret = (string) ($connection->credentials['consumer_secret'] ?? '');
        $base = $this->base((string) $connection->store_url);

        if ($key === '' || $secret === '' || $base === null) {
            throw new EcommerceNotConfigured('Enter the WooCommerce store address (https), consumer key and consumer secret.');
        }

        $orders = [];
        $pages = 1;

        for ($page = 1; $page <= $pages && $page <= self::MAX_PAGES; $page++) {
            $response = Http::withBasicAuth($key, $secret)->acceptJson()->timeout(20)->get($base.'/wp-json/wc/v3/orders', array_filter([
                'per_page' => 100, 'page' => $page, 'orderby' => 'date', 'order' => 'asc', 'after' => $since?->toIso8601String(),
            ]));

            if (in_array($response->status(), [401, 403], true)) {
                throw new EcommerceNotConfigured('WooCommerce refused the consumer key (it needs read permission).');
            }

            if (! $response->successful()) {
                throw new EcommerceNotConfigured('WooCommerce answered '.$response->status().'.');
            }

            $pages = max(1, (int) $response->header('X-WP-TotalPages'));

            foreach ((array) ($response->json() ?? []) as $order) {
                $orders[] = $this->present((array) $order);
            }
        }

        return array_values(array_filter($orders, fn (array $order): bool => $order['external_id'] !== ''));
    }

    private function base(string $storeUrl): ?string
    {
        $storeUrl = trim($storeUrl);

        if ($storeUrl === '') {
            return null;
        }

        if (! preg_match('#^https?://#i', $storeUrl)) {
            $storeUrl = 'https://'.$storeUrl;
        }

        $parts = parse_url($storeUrl);
        $host = (string) ($parts['host'] ?? '');

        if ($host === '' || (strtolower((string) ($parts['scheme'] ?? '')) !== 'https' && ! in_array($host, ['localhost', '127.0.0.1'], true))) {
            return null;
        }

        return strtolower((string) $parts['scheme']).'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '').rtrim((string) ($parts['path'] ?? ''), '/');
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function present(array $order): array
    {
        $billing = (array) ($order['billing'] ?? []);

        return [
            'external_id' => (string) ($order['id'] ?? ''),
            'number' => (string) ($order['number'] ?? ''),
            'placed_at' => substr((string) ($order['date_created_gmt'] ?? $order['date_created'] ?? now()->toDateString()), 0, 10),
            'cancelled' => in_array((string) ($order['status'] ?? ''), self::NOT_IMPORTED, true),
            'payment_status' => (string) ($order['status'] ?? ''),
            'currency' => (string) ($order['currency'] ?? ''),
            'customer' => [
                'name' => trim((string) ($billing['first_name'] ?? '').' '.(string) ($billing['last_name'] ?? '')),
                'email' => ($billing['email'] ?? null) ?: null, 'phone' => ($billing['phone'] ?? null) ?: null,
            ],
            'lines' => collect((array) ($order['line_items'] ?? []))->map(function (array $line): array {
                $quantity = (float) ($line['quantity'] ?? 0);

                return [
                    'sku' => (string) ($line['sku'] ?? ''), 'name' => $line['name'] ?? null, 'quantity' => $quantity,
                    'unit_price' => $quantity > 0 ? round((float) ($line['total'] ?? $line['subtotal'] ?? 0) / $quantity, 4) : null,
                ];
            })->all(),
            'shipping' => (float) ($order['shipping_total'] ?? 0),
            'tax' => (float) ($order['total_tax'] ?? 0),
            'total' => (float) ($order['total'] ?? 0),
        ];
    }
}
