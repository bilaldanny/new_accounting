<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A bank guarantee or letter of credit register entry. A register only: nothing is posted to the ledger by it.
 */
class BankInstrument extends Model
{
    use Auditable;
    use ScopedToCompany;
    use SoftDeletes;

    protected $fillable = ['company_id', 'branch_id', 'kind', 'reference_no', 'bank_name', 'contact_id', 'amount', 'currency', 'margin_percent', 'charges', 'issued_on', 'expires_on', 'status', 'purpose', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'charges' => 'float', 'margin_percent' => 'float', 'issued_on' => 'date', 'expires_on' => 'date'];
    }

    public const KINDS = ['guarantee', 'lc'];

    public const STATUSES = ['open', 'utilised', 'released', 'expired', 'cancelled'];
}
