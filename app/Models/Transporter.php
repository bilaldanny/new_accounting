<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * A transport company or carrier goods are moved with. Master data only for now: nothing in purchases
 * points at it yet (`transactions.transporter_id` exists but no form fills it).
 */
class Transporter extends Model
{
    use SoftDeletes;

    /**
     * Columns the list may be sorted by.
     *
     * @var list<string>
     */
    public const SORTABLE = ['name', 'phone', 'vehicle_no', 'is_active', 'created_at'];

    protected $fillable = [
        'company_id',
        'name',
        'phone',
        'address',
        'vehicle_no',
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

    public static function createTransporter(object $request): self
    {
        $transporter = new self;
        $transporter->company_id = self::scopedCompanyId($request);
        $transporter->fillDetails($request);
        $transporter->is_active = $request->has('is_active') ? $request->boolean('is_active') : true;
        $transporter->save();

        return $transporter;
    }

    public static function updateTransporter(object $request, int|string $id): self
    {
        $transporter = self::query()->visibleToCurrentUser()->findOrFail($id);
        $transporter->company_id = self::scopedCompanyId($request, (int) $transporter->company_id) ?? $transporter->company_id;
        $transporter->fillDetails($request);
        $transporter->is_active = $request->has('is_active') ? $request->boolean('is_active') : $transporter->is_active;
        $transporter->save();

        return $transporter;
    }

    public static function deleteTransporter(int|string $id): void
    {
        self::query()->visibleToCurrentUser()->find($id)?->delete();
    }

    private function fillDetails(object $request): void
    {
        $this->name = trim((string) $request->name);
        $this->phone = self::blankToNull($request->phone);
        $this->address = self::blankToNull($request->address);
        $this->vehicle_no = self::blankToNull($request->vehicle_no);
    }

    private static function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
