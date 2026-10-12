<?php

namespace App\Services\Ecommerce;

use App\Models\EcommerceConnection;
use Carbon\CarbonInterface;

/**
 * What the sync needs from a web-shop platform: its orders since a moment, each reduced to one common shape:
 *
 *   ['external_id' => string, 'placed_at' => 'Y-m-d', 'customer' => ['name' => ?, 'email' => ?, 'phone' => ?],
 *    'lines' => [['sku' => string, 'name' => ?, 'quantity' => float, 'unit_price' => ?float]], 'shipping' => float, 'total' => float]
 */
interface EcommerceAdapter
{
    /**
     * @return list<array<string, mixed>>
     *
     * @throws EcommerceNotConfigured
     */
    public function fetchOrders(EcommerceConnection $connection, ?CarbonInterface $since): array;
}
