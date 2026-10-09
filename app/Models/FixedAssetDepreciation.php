<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One month of depreciation booked on one asset (see Services\FixedAssetDepreciation). Unique per asset and month.
 */
class FixedAssetDepreciation extends Model
{
    use Auditable;

    protected $table = 'fixed_asset_depreciations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_end' => 'date',
            'amount' => 'float',
            'opening_book_value' => 'float',
            'closing_book_value' => 'float',
        ];
    }

    /**
     * @return BelongsTo<FixedAsset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id')->withTrashed();
    }

    /**
     * @return BelongsTo<TAccount, $this>
     */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(TAccount::class, 't_account_id');
    }
}
