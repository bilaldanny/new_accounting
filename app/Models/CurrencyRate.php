<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company's display exchange rate: how many units of `currency_id` one unit of the company's base currency
 * is worth. Used only to show an invoice total in the customer's currency (App\Services\DisplayCurrency); no
 * amount is ever stored or posted in it.
 */
class CurrencyRate extends Model
{
    protected $fillable = [
        'company_id',
        'currency_id',
        'rate',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
