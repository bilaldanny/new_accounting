<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company's loyalty programme rules. Off by default: `forCompany()` hands back an unsaved switched-off
 * default for a company that has never saved settings, so nothing earns or redeems until an admin turns it on.
 *
 * Earning: every `amount_per_point` spent earns one whole point (a 250 sale at 100 per point earns 2).
 * Redeeming: one point is worth `point_value` of currency, and at least `min_redeem_points` must be redeemed at once.
 */
class LoyaltySetting extends Model
{
    public const DEFAULT_AMOUNT_PER_POINT = 100.0;

    public const DEFAULT_POINT_VALUE = 1.0;

    protected $fillable = [
        'company_id',
        'is_enabled',
        'amount_per_point',
        'point_value',
        'min_redeem_points',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'amount_per_point' => 'float',
            'point_value' => 'float',
            'min_redeem_points' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function forCompany(int $companyId): self
    {
        return self::query()->where('company_id', $companyId)->first() ?? new self([
            'company_id' => $companyId,
            'is_enabled' => false,
            'amount_per_point' => self::DEFAULT_AMOUNT_PER_POINT,
            'point_value' => self::DEFAULT_POINT_VALUE,
            'min_redeem_points' => 0,
        ]);
    }

    /**
     * The whole points a sale of $amount earns: the amount divided by `amount_per_point`, rounded down.
     */
    public function pointsFor(float $amount): int
    {
        if ($amount <= 0 || $this->amount_per_point <= 0) {
            return 0;
        }

        return (int) floor(round($amount / $this->amount_per_point, 6));
    }

    /**
     * The currency value of $points when redeemed, to 2 decimals.
     */
    public function valueOf(int $points): float
    {
        return round($points * $this->point_value, 2);
    }
}
