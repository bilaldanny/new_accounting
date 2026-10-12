<?php

namespace App\Models;

use App\Models\Concerns\ScopedToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * A sale rung up at an offline POS terminal and replayed to the server (see Services\PosOfflineSync).
 */
class PosOfflineSale extends Model
{
    use ScopedToCompany;

    protected bool $branchScoped = true;

    protected $fillable = [
        'company_id', 'branch_id', 'client_uuid', 'device_id', 'offline_receipt_no', 'sold_at', 'catalog_version', 'total', 'payload', 'status',
        'conflict_code', 'conflict_detail', 'oversold', 'transaction_id', 'invoice_no', 'error', 'created_by', 'resolved_by', 'resolved_at', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array', 'conflict_detail' => 'array', 'oversold' => 'array', 'total' => 'float', 'sold_at' => 'datetime',
            'resolved_at' => 'datetime', 'synced_at' => 'datetime',
        ];
    }
}
