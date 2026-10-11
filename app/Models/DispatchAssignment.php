<?php

namespace App\Models;

use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A sale handed to a rider: assigned, out for delivery, delivered or failed. Moving it also moves the sale's own
 * shipping status (shipped / delivered), a delivery step that does not touch the books.
 */
class DispatchAssignment extends Model
{
    use ScopedToCompany;

    public const STATUSES = ['assigned', 'out', 'delivered', 'failed'];

    protected $fillable = ['company_id', 'transaction_id', 'rider_id', 'status', 'assigned_at', 'out_at', 'delivered_at', 'note', 'assigned_by'];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'out_at' => 'datetime', 'delivered_at' => 'datetime'];
    }
}
