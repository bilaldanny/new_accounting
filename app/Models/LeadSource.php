<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * A configurable lead source (Website, Referral, Walk-in, ...), company-scoped master data. Replaces
 * the free-text `leads.source` column from Step 1 as the preferred way to record where a lead came
 * from; `source` itself is kept on `Lead` for backward compatibility.
 */
class LeadSource extends Model
{
    use SoftDeletes;

    /**
     * Columns the list may be sorted by.
     *
     * @var list<string>
     */
    public const SORTABLE = ['name', 'is_active', 'created_at'];

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

    /**
     * The company a record is saved under: the superadmin picks one, everyone else is always their own
     * company whatever the request says.
     */
    private static function scopedCompanyId(object $request, ?int $current = null): ?int
    {
        $user = Auth::user();

        if ($user?->hasRole('superadmin')) {
            return self::resolveScopedId($request->company_id) ?? $current;
        }

        return $user?->company_id ? (int) $user->company_id : $current;
    }

    public static function createLeadSource(object $request): self
    {
        $leadSource = new self;
        $leadSource->company_id = self::scopedCompanyId($request);
        $leadSource->name = trim((string) $request->name);
        $leadSource->is_active = $request->has('is_active') ? $request->boolean('is_active') : true;
        $leadSource->save();

        return $leadSource;
    }

    public static function updateLeadSource(object $request, int|string $id): self
    {
        $leadSource = self::query()->visibleToCurrentUser()->findOrFail($id);
        $leadSource->company_id = self::scopedCompanyId($request, (int) $leadSource->company_id) ?? $leadSource->company_id;
        $leadSource->name = trim((string) $request->name);
        $leadSource->is_active = $request->has('is_active') ? $request->boolean('is_active') : $leadSource->is_active;
        $leadSource->save();

        return $leadSource;
    }

    public static function deleteLeadSource(int|string $id): void
    {
        self::query()->visibleToCurrentUser()->find($id)?->delete();
    }
}
