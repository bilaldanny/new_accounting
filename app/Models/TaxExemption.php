<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * A rule that takes a customer (or supplier) or an item out of a tax: while it is active and in its dates, a sale or purchase
 * line it matches is charged no tax and keeps the rule it was exempted by. `tax_id` empty means every tax.
 */
class TaxExemption extends Model
{
    public const SCOPE_CUSTOMER = 'customer';

    public const SCOPE_ITEM = 'item';

    protected $fillable = [
        'company_id',
        'name',
        'scope',
        'contact_id',
        'product_id',
        'tax_id',
        'certificate_no',
        'reason',
        'valid_from',
        'valid_to',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'valid_from' => 'date:Y-m-d',
            'valid_to' => 'date:Y-m-d',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Tax, $this>
     */
    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
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
     * The rules of a company that are on and cover the date (today when none is given).
     *
     * @return Collection<int, self>
     */
    public static function activeRules(int $companyId, ?string $date = null): Collection
    {
        $day = ($date !== null && $date !== '' ? substr($date, 0, 10) : now()->toDateString());

        return self::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $day))
            ->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $day))
            ->get();
    }

    /**
     * The first rule that exempts this customer or item from this tax. An item rule is looked at before a customer rule.
     *
     * @param  Collection<int, self>  $rules
     */
    public static function matching(Collection $rules, ?int $contactId, ?int $productId, int $taxId): ?self
    {
        $covers = fn (self $rule): bool => $rule->tax_id === null || (int) $rule->tax_id === $taxId;

        return $rules->first(fn (self $rule): bool => $rule->scope === self::SCOPE_ITEM && $productId !== null && (int) $rule->product_id === $productId && $covers($rule))
            ?? $rules->first(fn (self $rule): bool => $rule->scope === self::SCOPE_CUSTOMER && $contactId !== null && (int) $rule->contact_id === $contactId && $covers($rule));
    }
}
