<?php

namespace App\Models;

use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * The collection workflow status of a customer: open, promised (with a date), disputed, escalated or closed.
 */
class CollectionCase extends Model
{
    use ScopedToCompany;

    public const STATUSES = ['open', 'promised', 'disputed', 'escalated', 'closed'];

    protected $fillable = ['company_id', 'contact_id', 'status', 'promised_on', 'note', 'updated_by'];

    protected function casts(): array
    {
        return ['promised_on' => 'date:Y-m-d'];
    }
}
