<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountBalance extends Model
{
    protected $fillable = [
        'company_id',
        'branch_id',
        'financial_id',
        'opening_balance',
        'acc_nature',
        'coa_id',
    ];

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'coa_id');
    }

    public static function createForContact(object $request, int $financialId, int $coaId, string $accNature): self
    {
        return self::query()->create([
            'company_id' => $request->company_id,
            'branch_id' => $request->branch_id,
            'financial_id' => $financialId,
            'opening_balance' => $request->opening_balance ?? 0,
            'acc_nature' => $accNature,
            'coa_id' => $coaId,
        ]);
    }
}
