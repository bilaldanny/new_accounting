<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class Tax extends Model
{
    public const KIND_SALES = 'sales';

    public const KIND_WITHHOLDING = 'withholding';

    protected $fillable = [
        'company_id',
        'name',
        'percentage',
        'sub_tax',
        'type',
        'compound',
        'kind',
        'applies_on',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'percentage' => 'float',
            'type' => 'integer',
            'compound' => 'boolean',
            'status' => 'boolean',
            'sub_tax' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeVisibleToCurrentUser(Builder $query): Builder
    {
        $user = Auth::user();

        if ($user?->hasRole('superadmin')) {
            return $query;
        }

        if (! $user?->company_id) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where('company_id', $user->company_id);
    }

    /**
     * The one rate a group of taxes amounts to: the rates added, or - for a compound group - each tax charged on the amount
     * plus the taxes before it, so 5% then 10% is 15.5%.
     *
     * @param  list<float|int|string>  $rates  in the order of the group
     */
    public static function combinedRate(array $rates, bool $compound): float
    {
        if (! $compound) {
            return round(array_sum(array_map('floatval', $rates)), 4);
        }

        $factor = 1.0;

        foreach ($rates as $rate) {
            $factor *= 1 + ((float) $rate / 100);
        }

        return round(($factor - 1) * 100, 4);
    }

    /**
     * @return list<int>
     */
    public function subTaxIds(): array
    {
        return array_values(array_map('intval', (array) ($this->sub_tax ?? [])));
    }

    /**
     * Whether a sale, purchase or one of their lines points at this tax; such a tax cannot be deleted.
     */
    public function isUsed(): bool
    {
        $inGroup = self::query()->where('type', 1)->where('company_id', $this->company_id)->get()
            ->contains(fn (self $group): bool => in_array((int) $this->id, $group->subTaxIds(), true));

        return $inGroup
            || DB::table('transactions')->where(fn ($q) => $q->where('tax_id', $this->id)->orWhere('withholding_tax_id', $this->id))->exists()
            || DB::table('sell_lines')->where('tax_id', $this->id)->exists()
            || DB::table('purchase_lines')->where('tax_id', $this->id)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private static function payloadFromRequest(Request $request, ?self $existing = null): array
    {
        $isGroup = (int) $request->input('type', $existing->type ?? 0) === 1;
        $base = [
            'company_id' => $request->integer('company_id') ?: null,
            'name' => $request->string('name')->toString(),
            'type' => $isGroup ? 1 : 0,
            'status' => $request->boolean('status', $existing === null ? true : (bool) $existing->status),
        ];

        if ($isGroup) {
            $ids = array_values(array_unique(array_map('intval', (array) $request->input('sub_tax', []))));
            $rates = array_map(fn (int $id): float => (float) (self::query()->find($id)?->percentage ?? 0), $ids);
            $compound = $request->boolean('compound', $existing === null ? false : (bool) $existing->compound);

            return $base + [
                'percentage' => self::combinedRate($rates, $compound),
                'sub_tax' => $ids,
                'compound' => $compound,
                'kind' => self::KIND_SALES,
                'applies_on' => 'net',
            ];
        }

        $kind = $request->input('kind', $existing->kind ?? self::KIND_SALES) === self::KIND_WITHHOLDING
            ? self::KIND_WITHHOLDING
            : self::KIND_SALES;

        return $base + [
            'percentage' => (float) $request->input('percentage'),
            'sub_tax' => null,
            'compound' => false,
            'kind' => $kind,
            'applies_on' => $kind === self::KIND_WITHHOLDING && $request->input('applies_on', $existing->applies_on ?? 'net') === 'gross' ? 'gross' : 'net',
        ];
    }

    public static function storeFromRequest(Request $request): self
    {
        return self::query()->create(self::payloadFromRequest($request));
    }

    public function updateFromRequest(Request $request): self
    {
        $this->update(self::payloadFromRequest($request, $this));
        $this->refreshGroups();

        return $this;
    }

    /**
     * A group keeps the rate it amounts to, so it is worked out again when one of its taxes changes its rate.
     */
    public function refreshGroups(): void
    {
        if ((int) $this->type !== 0) {
            return;
        }

        self::query()
            ->where('type', 1)
            ->where('company_id', $this->company_id)
            ->get()
            ->filter(fn (self $group): bool => in_array((int) $this->id, $group->subTaxIds(), true))
            ->each(function (self $group): void {
                $rates = array_map(fn (int $id): float => (float) (self::query()->find($id)?->percentage ?? 0), $group->subTaxIds());
                $group->update(['percentage' => self::combinedRate($rates, (bool) $group->compound)]);
            });
    }
}
