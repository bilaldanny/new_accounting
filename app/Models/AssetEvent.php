<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a fixed asset's history (Accounts & Finance Phase 3): its acquisition, CWIP costs, capitalisation,
 * transfers, revaluations, impairments and disposal. Acquisition, CWIP and transfer entries are `posted` the moment
 * they are made; revaluation, impairment and disposal are `pending` until someone with the approve permission
 * approves them, and only then do they post their voucher and change the asset. Depreciation runs are kept in
 * `fixed_asset_depreciations`; the history screen merges both.
 */
class AssetEvent extends Model
{
    use Auditable;

    public const TYPE_ACQUISITION = 'acquisition';

    public const TYPE_CWIP_COST = 'cwip_cost';

    public const TYPE_CAPITALISATION = 'capitalisation';

    public const TYPE_TRANSFER = 'transfer';

    public const TYPE_REVALUATION = 'revaluation';

    public const TYPE_IMPAIRMENT = 'impairment';

    public const TYPE_DISPOSAL = 'disposal';

    /**
     * The types that wait for approval before they touch the ledger or the asset.
     *
     * @var list<string>
     */
    public const APPROVAL_TYPES = [self::TYPE_REVALUATION, self::TYPE_IMPAIRMENT, self::TYPE_DISPOSAL];

    public const STATUS_POSTED = 'posted';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'amount' => 'float',
            'payload' => 'array',
            'approved_at' => 'datetime',
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

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'fixed_asset_id' => $this->fixed_asset_id,
            'type' => $this->type,
            'status' => $this->status,
            'event_date' => $this->event_date?->toDateString(),
            'amount' => $this->amount,
            'description' => $this->description,
            'voucher_no' => $this->voucher?->voucher_no,
            'voucher_status' => $this->voucher?->status,
            'payload' => $this->payload,
            'rejected_reason' => $this->rejected_reason,
            'approved_at' => $this->approved_at?->format('Y-m-d H:i'),
        ];
    }
}
