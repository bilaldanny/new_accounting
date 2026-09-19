<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class PriceList extends Model
{
    use SoftDeletes;

    public const STATUSES = ['pending', 'approved'];

    protected $fillable = [
        'company_id',
        'branch_id',
        'brand_id',
        'date',
        'discount',
        'status',
        'created_by',
        'updated_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'discount' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    protected function companyId(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? '' : $value,
        );
    }

    protected function branchId(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? '' : $value,
        );
    }

    protected function brandId(): Attribute
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

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return HasMany<PriceListDetail, $this>
     */
    public function pricelistdetails(): HasMany
    {
        return $this->hasMany(PriceListDetail::class, 'list_id');
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

        $query->where('company_id', $user->company_id);

        if ($user?->branch_id && ! $user?->hasRole('companyadmin')) {
            $query->where('branch_id', $user->branch_id);
        }

        return $query;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public static function createPriceList($request, array $lines): self
    {
        $companyId = self::resolveScopedId($request->company_id);

        if ($companyId === null && Auth::user()?->company_id) {
            $companyId = (int) Auth::user()->company_id;
        }

        $priceList = new self;
        $priceList->company_id = $companyId;
        $priceList->branch_id = self::resolveScopedId($request->branch_id);
        $priceList->brand_id = self::resolveScopedId($request->brand_id);
        $priceList->date = $request->date ?: now()->toDateString();
        $priceList->discount = (float) ($request->discount ?? 0);
        $priceList->status = in_array($request->status, self::STATUSES, true) ? $request->status : 'pending';
        $priceList->created_by = Auth::id();
        $priceList->save();

        $priceList->syncDetails($lines);

        return $priceList;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public static function updatePriceList($request, int $id, array $lines): self
    {
        $priceList = self::query()->visibleToCurrentUser()->findOrFail($id);

        $companyId = self::resolveScopedId($request->company_id);

        $priceList->company_id = $companyId ?? $priceList->company_id;
        $priceList->branch_id = self::resolveScopedId($request->branch_id);
        $priceList->brand_id = self::resolveScopedId($request->brand_id);
        $priceList->discount = (float) ($request->discount ?? 0);

        $newStatus = in_array($request->status, self::STATUSES, true) ? $request->status : $priceList->status;

        if ($newStatus === 'approved' && $priceList->status !== 'approved') {
            $priceList->approved_by = Auth::id();
            $priceList->approved_at = now();
        }

        $priceList->status = $newStatus;
        $priceList->updated_by = Auth::id();
        $priceList->save();

        $priceList->syncDetails($lines);

        return $priceList;
    }

    public static function deletePriceList($id): void
    {
        self::query()->visibleToCurrentUser()->find($id)?->delete();
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function syncDetails(array $lines): void
    {
        $this->pricelistdetails()->delete();

        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);

            if ($productId <= 0) {
                continue;
            }

            $this->pricelistdetails()->create([
                'product_id' => $productId,
                'variation_id' => self::resolveScopedId($line['variation_id'] ?? null),
                'unit_id' => self::resolveScopedId($line['unit_id'] ?? null),
                'purchase_price' => (float) ($line['purchase_price'] ?? 0),
                'sell_price' => (float) ($line['sell_price'] ?? 0),
                'profit_margin' => (float) ($line['profit_margin'] ?? 0),
                'discount' => (float) ($line['discount'] ?? 0),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function presentForIndex(): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'branch_id' => $this->branch_id,
            'company_name' => $this->company?->name,
            'branch_name' => $this->branch?->name,
            'brand_id' => $this->brand_id,
            'brand_name' => $this->brand?->name,
            'date' => $this->date?->format('Y-m-d'),
            'date_label' => $this->date?->format('Y-m-d'),
            'discount' => (float) $this->discount,
            'status' => $this->status,
            'status_label' => ucfirst((string) $this->status),
            'line_count' => $this->pricelistdetails()->count(),
        ];
    }
}
