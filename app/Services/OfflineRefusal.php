<?php

namespace App\Services;

use RuntimeException;

/**
 * The server declined an offline entry as it was sent: `conflict` names why (insufficient_stock, period_locked, credit_limit,
 * product_missing, contact_blocked, no_permission, rejected ...), the message is the server's own words.
 */
class OfflineRefusal extends RuntimeException
{
    public function __construct(public readonly string $conflict, string $message, public readonly int $http = 422)
    {
        parent::__construct($message);
    }
}
