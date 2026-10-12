<?php

namespace App\Models;

use App\Models\Concerns\GuardsLockedPeriods;
use Database\Factories\TAccountDetailFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TAccountDetail extends Model
{
    use GuardsLockedPeriods;

    public static function periodLockDateColumn(): string
    {
        return 'voucher_date';
    }

    /**
     * A line has no day of its own: it belongs to the day of its voucher, so a line cannot be added, changed or removed inside a
     * locked period even when the voucher header itself is left as it is.
     */
    public static function periodLockAppliesDatingRules(): bool
    {
        return false;
    }

    /**
     * @return array{table: string, foreign_key: string, date: string}
     */
    public static function periodLockParent(): array
    {
        return ['table' => 't_accounts', 'foreign_key' => 't_account_id', 'date' => 'voucher_date'];
    }

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
        'cost_center_id',
        'project_id',
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
