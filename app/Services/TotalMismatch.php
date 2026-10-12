<?php

namespace App\Services;

use RuntimeException;

/**
 * Thrown inside the offline sync transaction when the total the server worked out is not the one the customer was charged; the
 * transaction is rolled back and the sale is parked for review.
 */
class TotalMismatch extends RuntimeException
{
    public function __construct(public readonly float $serverTotal)
    {
        parent::__construct('The server total differs from the total charged at the till.');
    }
}
