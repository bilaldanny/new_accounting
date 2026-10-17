<?php

namespace App\Models;

use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry sent by a device in offline mode (see Services\OfflineSync).
 */
class OfflineSyncEntry extends Model
{
    use ScopedToCompany;

    protected $fillable = [
        'company_id', 'branch_id', 'user_id', 'device_id', 'client_uuid', 'kind', 'provisional_ref', 'status', 'conflict_code', 'error', 'payload',
        'result_type', 'result_id', 'real_ref', 'attempts', 'created_at_client', 'processed_at',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at_client' => 'datetime', 'processed_at' => 'datetime'];
    }
}
