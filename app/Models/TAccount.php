<?php

namespace App\Models;

use Database\Factories\TAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TAccount extends Model
{
    /** @use HasFactory<TAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'company_id',
        'branch_id',
        'coa_id',
        'transaction_id',
        'received_id',
        'created_by',
        'approved_by',
        'cancelled_by',
        'printed_by',
        'issuer_id',
        'account_code',
        'voucher_no',
        'ref_no',
        'cheque_no',
        'cheque_post_date',
        'voucher_date',
        'total_amount',
        'total_tax',
        'net_total',
        'is_print',
        'comments',
        'status',
        'type',
        'printed_at',
        'approved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cheque_post_date' => 'date',
            'voucher_date' => 'date',
            'total_amount' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'net_total' => 'decimal:2',
            'is_print' => 'boolean',
            'printed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'coa_id');
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return HasMany<TAccountDetail, $this>
     */
    public function details(): HasMany
    {
        return $this->hasMany(TAccountDetail::class, 't_account_id');
    }
}
