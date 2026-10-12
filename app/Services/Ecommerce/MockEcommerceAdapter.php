<?php

namespace App\Services\Ecommerce;

use App\Models\EcommerceConnection;
use Carbon\CarbonInterface;

/**
 * The adapter for tests and demos: its orders are the `orders` list kept in the connection's credentials, already in the common
 * shape. No network, no keys.
 */
class MockEcommerceAdapter implements EcommerceAdapter
{
    public function fetchOrders(EcommerceConnection $connection, ?CarbonInterface $since): array
    {
        return array_values((array) ($connection->credentials['orders'] ?? []));
    }
}
