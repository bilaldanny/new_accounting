<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per sale handed to the FBR gateway: what was sent, what came back and the status. `stub` means the stub gateway
 * answered (nothing reached FBR); only `submitted` would be a real FBR acceptance.
 */
class FbrSubmission extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_FAILED = 'failed';

    public const STATUS_STUB = 'stub';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_SUBMITTED, self::STATUS_FAILED, self::STATUS_STUB];

    protected $fillable = [
        'company_id',
        'transaction_id',
        'status',
        'fbr_invoice_number',
        'payload',
        'response',
        'error',
        'attempts',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'response' => 'array',
            'attempts' => 'integer',
            'submitted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
