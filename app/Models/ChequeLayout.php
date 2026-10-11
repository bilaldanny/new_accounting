<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Where the payee, the amount in words, the amount and the date sit on a cheque leaf, in millimetres from its top left corner.
 */
class ChequeLayout extends Model
{
    use Auditable;
    use ScopedToCompany;

    protected $fillable = ['company_id', 'name', 'width_mm', 'height_mm', 'payee_x', 'payee_y', 'words_x', 'words_y', 'amount_x', 'amount_y', 'date_x', 'date_y', 'font_size', 'account_payee', 'is_default'];

    protected function casts(): array
    {
        return ['account_payee' => 'boolean', 'is_default' => 'boolean'];
    }
}
