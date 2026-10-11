<?php

namespace App\Models;

use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * An early-payment discount that was taken on a sale: the amount written off against the customer's invoice and its voucher.
 */
class EarlyPaymentDiscount extends Model
{
    use ScopedToCompany;

    protected $fillable = ['company_id', 'transaction_id', 'payment_term_id', 'amount', 'applied_on', 't_account_id', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'applied_on' => 'date'];
    }
}
