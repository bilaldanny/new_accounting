<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Letter of credit, customs clearing and foreign currency details of an import purchase. They are information kept with the
 * purchase: the purchase itself still posts in the local currency.
 */
class ImportPurchaseDetail extends Model
{
    use Auditable;
    use ScopedToCompany;

    protected $fillable = [
        'company_id', 'transaction_id', 'lc_no', 'lc_bank', 'lc_amount', 'lc_opened_on', 'lc_expires_on', 'bl_no', 'customs_ref',
        'customs_cleared_on', 'clearing_agent', 'currency_code', 'exchange_rate', 'foreign_total', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'lc_amount' => 'float', 'exchange_rate' => 'float', 'foreign_total' => 'float',
            'lc_opened_on' => 'date:Y-m-d', 'lc_expires_on' => 'date:Y-m-d', 'customs_cleared_on' => 'date:Y-m-d',
        ];
    }
}
