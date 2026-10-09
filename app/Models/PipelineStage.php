<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * A configurable pipeline stage (New, Contacted, Proposal Sent, Negotiation, Won, Lost, ...),
 * company-scoped master data ordered by `sort_order`. `is_won`/`is_lost` mark the stage(s) an
 * Opportunity's `status` follows automatically when moved onto them (see `Opportunity::moveToStage()`).
 */
class PipelineStage extends Model
{
    use SoftDeletes;

    /**
     * Columns the list may be sorted by.
     *
     * @var list<string>
     */
    public const SORTABLE = ['name', 'sort_order', 'is_active', 'created_at'];

    protected $fillable = [
        'company_id',
        'name',
        'sort_order',
        'color',
        'is_won',
        'is_lost',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_won' => 'boolean',
            'is_lost' => 'boolean',
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

    public static function createPipelineStage(object $request): self
    {
        $stage = new self;
        $stage->company_id = self::scopedCompanyId($request);
        $stage->fillDetails($request);

        if (! $request->filled('sort_order')) {
            $stage->sort_order = ((int) self::query()->visibleToCurrentUser()->max('sort_order')) + 1;
        }

        $stage->save();

        return $stage;
    }

    public static function updatePipelineStage(object $request, int|string $id): self
    {
        $stage = self::query()->visibleToCurrentUser()->findOrFail($id);
        $stage->company_id = self::scopedCompanyId($request, (int) $stage->company_id) ?? $stage->company_id;
        $stage->fillDetails($request);
        $stage->save();

        return $stage;
    }

    public static function deletePipelineStage(int|string $id): void
    {
        self::query()->visibleToCurrentUser()->find($id)?->delete();
    }

    private function fillDetails(object $request): void
    {
        $this->name = trim((string) $request->name);
        $this->color = self::blankToNull($request->color);
        $this->is_won = $request->has('is_won') ? $request->boolean('is_won') : ($this->is_won ?? false);
        $this->is_lost = $request->has('is_lost') ? $request->boolean('is_lost') : ($this->is_lost ?? false);
        $this->is_active = $request->has('is_active') ? $request->boolean('is_active') : ($this->is_active ?? true);

        if ($request->filled('sort_order')) {
            $this->sort_order = (int) $request->sort_order;
        }
    }

    private static function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
