<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

/**
 * A place inside a warehouse: a zone, a rack, a shelf or a bin (Inventory, warehouse layer W1). Locations form a tree
 * under their warehouse; a child is always a finer kind than its parent (zone, then rack, then shelf, then bin).
 * Master data only for now: no stock is held per location.
 */
class WarehouseLocation extends Model
{
    use Auditable, SoftDeletes;

    /**
     * From the coarsest to the finest.
     *
     * @var list<string>
     */
    public const TYPES = ['zone', 'rack', 'shelf', 'bin'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class)->withTrashed();
    }

    /**
     * @return BelongsTo<WarehouseLocation, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<WarehouseLocation, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function scopeVisibleToCurrentUser(Builder $query): Builder
    {
        $user = Auth::user();

        if ($user?->hasRole('superadmin')) {
            return $query;
        }

        return $user?->company_id ? $query->where('company_id', $user->company_id) : $query->whereRaw('0 = 1');
    }

    /**
     * Whether `$type` may sit directly under a location of type `$parentType` (a finer kind).
     */
    public static function fitsUnder(string $type, string $parentType): bool
    {
        return array_search($type, self::TYPES, true) > array_search($parentType, self::TYPES, true);
    }

    /**
     * The path from the top of the tree, e.g. "Zone A / Rack 3 / Shelf 2".
     */
    public function path(): string
    {
        $names = [$this->name];
        $node = $this;

        for ($depth = 0; $depth < 10 && $node->parent_id; $depth++) {
            $node = self::query()->find($node->parent_id);

            if ($node === null) {
                break;
            }

            array_unshift($names, $node->name);
        }

        return implode(' / ', $names);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'warehouse_id' => $this->warehouse_id,
            'warehouse_name' => $this->warehouse?->name,
            'parent_id' => $this->parent_id,
            'parent_name' => $this->parent?->name,
            'type' => $this->type,
            'code' => $this->code,
            'name' => $this->name,
            'path' => $this->path(),
            'active' => $this->active,
        ];
    }
}
