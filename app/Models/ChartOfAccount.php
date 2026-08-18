<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChartOfAccount extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'branch_id',
        'parent_id',
        'code',
        'name',
        'acc_type',
        'acc_nature',
        'pl',
        'bs',
        'active',
        'branches',
    ];

    protected function casts(): array
    {
        return [
            'pl' => 'boolean',
            'bs' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public static function createSubAccount(
        object $request,
        string $code,
        string $name,
        int $parentId,
        self $parentAccount,
    ): self {
        return self::query()->create([
            'parent_id' => $parentId,
            'company_id' => $request->company_id,
            'branch_id' => $request->branch_id,
            'name' => $name,
            'code' => $code,
            'acc_type' => 't',
            'acc_nature' => $parentAccount->acc_nature,
            'active' => true,
            'pl' => $parentAccount->pl,
            'bs' => $parentAccount->bs,
        ]);
    }
}
