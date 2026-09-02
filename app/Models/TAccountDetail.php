<?php

namespace App\Models;

use Database\Factories\TAccountDetailFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TAccountDetail extends Model
{
    /** @use HasFactory<TAccountDetailFactory> */
    use HasFactory;

    protected $fillable = [
        't_account_id',
        'branch_id',
        'coa_id',
        'contact_id',
        'account_code',
        'description',
        'acc_nature',
        'credit',
        'debit',
        'highlight',
        'amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credit' => 'decimal:2',
            'debit' => 'decimal:2',
            'amount' => 'decimal:2',
            'highlight' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<TAccount, $this>
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(TAccount::class, 't_account_id');
    }

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'coa_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
