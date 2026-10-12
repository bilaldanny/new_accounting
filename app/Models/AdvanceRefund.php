<?php

namespace App\Models;

use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * A refund of an unused customer advance (cash collection) or supplier advance (supplier payment batch).
 */
class AdvanceRefund extends Model
{
    use ScopedToCompany;

    protected $fillable = [
        'company_id', 'branch_id', 'party', 'contact_id', 'cash_collection_id', 'supplier_payment_batch_id', 'payment_id', 'amount',
        'refunded_on', 'note', 'created_by',
    ];

    /**
     * What has been refunded out of a cash collection (`cash_collection_id`) or a supplier payment batch
     * (`supplier_payment_batch_id`); zero before the table exists.
     */
    public static function refundedFor(string $column, int $id): float
    {
        if (! Schema::hasTable('advance_refunds')) {
            return 0.0;
        }

        return round((float) static::query()->where($column, $id)->sum('amount'), 2);
    }

    protected function casts(): array
    {
        return ['amount' => 'float', 'refunded_on' => 'date:Y-m-d'];
    }
}
