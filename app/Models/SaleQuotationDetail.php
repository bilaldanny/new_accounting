<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What a quotation (a sale in the `quotation` status) carries beyond the sale itself: how long the offer is valid, whether it is
 * printed as a proforma invoice, and what it was converted into.
 */
class SaleQuotationDetail extends Model
{
    protected $fillable = ['company_id', 'transaction_id', 'valid_until', 'is_proforma', 'converted_to_type', 'converted_to_id'];

    protected function casts(): array
    {
        return ['valid_until' => 'date:Y-m-d', 'is_proforma' => 'boolean'];
    }
}
