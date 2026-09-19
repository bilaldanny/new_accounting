<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class BankIssuer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected function companyId(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? '' : $value,
        );
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function resolveScopedId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'undefined') {
            return null;
        }

        return (int) $value;
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

    public static function createBankIssuer($request): self
    {
        $companyId = self::resolveScopedId($request->company_id);

        if ($companyId === null && Auth::user()?->company_id) {
            $companyId = (int) Auth::user()->company_id;
        }

        $bankIssuer = new self;
        $bankIssuer->company_id = $companyId;
        $bankIssuer->name = $request->name;
        $bankIssuer->is_active = $request->has('is_active') ? $request->boolean('is_active') : true;
        $bankIssuer->save();

        return $bankIssuer;
    }

    public static function updateBankIssuer($request, $id): self
    {
        $bankIssuer = self::query()->visibleToCurrentUser()->findOrFail($id);
        $companyId = self::resolveScopedId($request->company_id);

        if ($companyId === null && Auth::user()?->company_id) {
            $companyId = (int) Auth::user()->company_id;
        }

        $bankIssuer->company_id = $companyId ?? $bankIssuer->company_id;
        $bankIssuer->name = $request->name;
        $bankIssuer->is_active = $request->has('is_active') ? $request->boolean('is_active') : $bankIssuer->is_active;
        $bankIssuer->save();

        return $bankIssuer;
    }

    public static function deleteBankIssuer($id): void
    {
        self::query()->visibleToCurrentUser()->find($id)?->delete();
    }
}
