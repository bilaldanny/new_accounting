<?php

namespace App\Services\Ecommerce;

use App\Models\EcommerceConnection;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Shopify Admin REST API, orders (`GET /admin/api/{version}/orders.json`), read-only.
 *
 * Follows Shopify's documented behaviour: the access token goes in `X-Shopify-Access-Token`; `status=any` brings closed and
 * cancelled orders too (cancelled ones are flagged, not imported); at most 250 orders a page, the next page being the URL in the
 * `Link: <...>; rel="next"` header (its `page_info` cursor must be sent with no other filter, so the URL is used as given); a 429
 * answer carries `Retry-After` and is retried once. A line's unit price is the line price less the discounts allocated to the
 * line (`discount_allocations`), so the sale is worth what the customer was charged before tax and shipping.
 *
 * Needs the shop address (`store_url`) and an Admin API access token (`access_token` in the credentials) from a custom app with
 * the `read_orders` scope. The REST Admin API is marked legacy by Shopify; the version is a constant to move when it is retired.
 */
class ShopifyAdapter implements EcommerceAdapter
{
    public const API_VERSION = '2024-10';

    private const MAX_PAGES = 20;

    public function fetchOrders(EcommerceConnection $connection, ?CarbonInterface $since): array
    {
        $token = (string) ($connection->credentials['access_token'] ?? '');
        $base = $this->base((string) $connection->store_url);

        if ($token === '' || $base === null) {
            throw new EcommerceNotConfigured('Enter the Shopify store address and its Admin API access token.');
        }

        $url = $base.'/admin/api/'.self::API_VERSION.'/orders.json';
        $query = array_filter(['status' => 'any', 'limit' => 250, 'order' => 'created_at asc', 'created_at_min' => $since?->toIso8601String()]);
        $orders = [];

        for ($page = 0; $url !== null && $page < self::MAX_PAGES; $page++) {
            $response = $this->get($url, $token, $page === 0 ? $query : []);

            if (in_array($response->status(), [401, 403], true)) {
                throw new EcommerceNotConfigured('Shopify refused the access token (the app needs the read_orders scope).');
            }

            if (! $response->successful()) {
                throw new EcommerceNotConfigured('Shopify answered '.$response->status().'.');
            }

            foreach ((array) $response->json('orders', []) as $order) {
                $orders[] = $this->present((array) $order);
            }

            $url = $this->nextLink((string) $response->header('Link'));
        }

        return array_values(array_filter($orders, fn (array $order): bool => $order['external_id'] !== ''));
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function get(string $url, string $token, array $query): Response
    {
        // a next-page URL carries its own cursor: passing a (even empty) query would replace it
        $send = fn (): Response => $query === []
            ? Http::withHeaders(['X-Shopify-Access-Token' => $token])->acceptJson()->timeout(20)->get($url)
            : Http::withHeaders(['X-Shopify-Access-Token' => $token])->acceptJson()->timeout(20)->get($url, $query);
        $response = $send();

        if ($response->status() === 429) {
            sleep(min(max((int) $response->header('Retry-After'), 1), 5));
            $response = $send();
        }

        return $response;
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

        if (($parts['host'] ?? '') === '' || (strtolower((string) ($parts['scheme'] ?? '')) !== 'https' && ! in_array($parts['host'], ['localhost', '127.0.0.1'], true))) {
            return null;
        }

        return strtolower((string) $parts['scheme']).'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private function nextLink(string $header): ?string
    {
        foreach (explode(',', $header) as $part) {
            if (preg_match('/<([^>]+)>;\s*rel="next"/', $part, $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function present(array $order): array
    {
        $customer = (array) ($order['customer'] ?? []);
        $address = (array) ($order['shipping_address'] ?? $order['billing_address'] ?? []);
        $name = trim((string) ($customer['first_name'] ?? '').' '.(string) ($customer['last_name'] ?? ''));

        return [
            'external_id' => (string) ($order['id'] ?? ''),
            'number' => (string) ($order['name'] ?? ''),
            'placed_at' => substr((string) ($order['created_at'] ?? now()->toDateString()), 0, 10),
            'cancelled' => ! empty($order['cancelled_at']),
            'payment_status' => (string) ($order['financial_status'] ?? ''),
            'currency' => (string) ($order['currency'] ?? ''),
            'customer' => [
                'name' => $name !== '' ? $name : trim((string) ($address['name'] ?? '')),
                'email' => ($order['email'] ?? $customer['email'] ?? null) ?: null,
                'phone' => ($order['phone'] ?? $customer['phone'] ?? $address['phone'] ?? null) ?: null,
            ],
            'lines' => collect((array) ($order['line_items'] ?? []))->map(function (array $line): array {
                $quantity = (float) ($line['quantity'] ?? 0);
                $discount = (float) collect((array) ($line['discount_allocations'] ?? []))->sum(fn (array $allocation): float => (float) ($allocation['amount'] ?? 0));
                $price = isset($line['price']) ? (float) $line['price'] : null;

                return [
                    'sku' => (string) ($line['sku'] ?? ''), 'name' => $line['name'] ?? $line['title'] ?? null, 'quantity' => $quantity,
                    'unit_price' => $price === null ? null : ($quantity > 0 ? round($price - $discount / $quantity, 4) : $price),
                ];
            })->all(),
            'shipping' => (float) collect((array) ($order['shipping_lines'] ?? []))->sum(fn (array $line): float => (float) ($line['discounted_price'] ?? $line['price'] ?? 0)),
            'tax' => (float) ($order['total_tax'] ?? 0),
            'total' => (float) ($order['total_price'] ?? 0),
        ];
    }
}
