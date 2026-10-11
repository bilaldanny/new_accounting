<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierPaymentAllocation extends Model
{
    public const KIND_CASH = 'cash';

    public const KIND_ADVANCE = 'advance';

    protected $fillable = ['supplier_payment_batch_id', 'transaction_id', 'payment_id', 'amount', 'kind'];

    protected function casts(): array
    {
        return ['amount' => 'float'];
    }
}
