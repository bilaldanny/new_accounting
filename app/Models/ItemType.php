<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ItemType extends Model
{
    use SoftDeletes;

    public const BEHAVIOUR_INVENTORY = 'inventory';

    public const BEHAVIOUR_NON_INVENTORY = 'non_inventory';

    public const BEHAVIOUR_SERVICE = 'service';

    public const BEHAVIOUR_KIT = 'kit';

    public const BEHAVIOUR_DIGITAL = 'digital';

    /**
     * What an item type does to stock. NULL (every item type that existed before this column) behaves as inventory.
     * Non-inventory, service and digital items never move stock; a kit is not stocked itself, its components are
     * (see StockMovements).
     *
     * @var list<string>
     */
    public const BEHAVIOURS = [
        self::BEHAVIOUR_INVENTORY,
        self::BEHAVIOUR_NON_INVENTORY,
        self::BEHAVIOUR_SERVICE,
        self::BEHAVIOUR_KIT,
        self::BEHAVIOUR_DIGITAL,
    ];

    /**
     * @var list<string>
     */
    public const UNSTOCKED_BEHAVIOURS = [
        self::BEHAVIOUR_NON_INVENTORY,
        self::BEHAVIOUR_SERVICE,
        self::BEHAVIOUR_KIT,
        self::BEHAVIOUR_DIGITAL,
    ];

    protected $fillable = [
        'company_id',
        'name',
        'active',
        'behaviour',
    ];

    private static ?bool $behaviourColumn = null;

    /**
     * False until the migration has run, so a deploy ahead of it keeps saving item types as before.
     */
    public static function supportsBehaviour(): bool
    {
        return self::$behaviourColumn ??= Schema::hasColumn('item_types', 'behaviour');
    }

    /**
     * The behaviour a request names, or the current one when it names none (a client that predates the field leaves it alone).
     */
    private static function behaviourFrom(object $request, ?string $current): ?string
    {
        $sent = method_exists($request, 'has') ? $request->has('behaviour') : isset($request->behaviour);

        if (! $sent) {
            return $current;
        }

        $value = $request->behaviour ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (! in_array($value, self::BEHAVIOURS, true)) {
            throw ValidationException::withMessages(['behaviour' => ['Choose inventory, non_inventory, service, kit or digital.']]);
        }

        return $value;
    }

    protected function active(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === 1 || $value === '1' || $value === true,
            set: fn ($value) => ($value === 'false' || $value === false || $value === 0 || $value === '0') ? 0 : 1,
        );
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

    public static function normalizeName(?string $name): string
    {
        return strtolower(preg_replace('/\s+/', '', trim((string) $name)));
    }

    public static function nameExists(
        string $name,
        ?int $exceptId = null,
        ?int $companyId = null,
    ): bool {
        return self::query()
            ->when($exceptId !== null, fn (Builder $query) => $query->where('id', '!=', $exceptId))
            ->when($companyId !== null, fn (Builder $query) => $query->where('company_id', $companyId))
            ->whereRaw("LOWER(REPLACE(name, ' ', '')) = ?", [self::normalizeName($name)])
            ->exists();
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

    public static function findVisibleToCurrentUser(int $id): ?self
    {
        return self::query()->visibleToCurrentUser()->find($id);
    }

    /**
     * @throws ValidationException
     */
    public static function assertUniqueName(
        string $name,
        ?int $exceptId = null,
        ?int $companyId = null,
    ): void {
        if (self::nameExists($name, $exceptId, $companyId)) {
            throw ValidationException::withMessages([
                'name' => ['An item type with this name already exists.'],
            ]);
        }
    }

    public static function resolveScopedId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 'undefined') {
            return null;
        }

        return (int) $value;
    }

    public static function createItemType(object $request): self
    {
        $companyId = self::resolveScopedId($request->company_id);

        self::assertUniqueName($request->name, null, $companyId);

        $itemType = new self;
        $itemType->company_id = $companyId;
        $itemType->name = $request->name;
        $itemType->active = $request->active ?? true;
        if (self::supportsBehaviour()) {
            $itemType->behaviour = self::behaviourFrom($request, null);
        }

        $itemType->save();

        return $itemType;
    }

    public static function updateItemType(object $request, int $id): self
    {
        $itemType = self::findVisibleToCurrentUser($id);

        if ($itemType === null) {
            abort(404);
        }

        $companyId = self::resolveScopedId($request->company_id);

        self::assertUniqueName($request->name, $id, $companyId);

        $itemType->company_id = $companyId;
        $itemType->name = $request->name;
        $itemType->active = $request->active ?? true;
        if (self::supportsBehaviour()) {
            $itemType->behaviour = self::behaviourFrom($request, $itemType->behaviour);
        }

        $itemType->save();

        return $itemType;
    }

    public static function deleteItemType(int $id): void
    {
        $itemType = self::findVisibleToCurrentUser($id);

        if ($itemType === null) {
            return;
        }

        $itemType->delete();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function upsertFromImport(array $row): string
    {
        $id = self::normalizeImportId($row['id'] ?? null);
        $payload = self::buildImportPayload($row, $id);

        if ($id !== null) {
            $itemType = self::findVisibleToCurrentUser($id);

            if ($itemType === null) {
                throw ValidationException::withMessages([
                    'rows' => ["Item type with id {$id} was not found."],
                ]);
            }

            self::updateItemType($payload, $id);

            return 'updated';
        }

        self::createItemType($payload);

        return 'created';
    }

    protected static function normalizeImportId(mixed $id): ?int
    {
        if ($id === null || $id === '' || $id === 0 || $id === '0') {
            return null;
        }

        if (is_numeric($id)) {
            $normalized = (int) $id;

            return $normalized > 0 ? $normalized : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected static function buildImportPayload(array $row, ?int $existingId = null): object
    {
        $companyId = self::resolveScopedId($row['company_id'] ?? null);

        if ($companyId === null && Auth::user()?->company_id) {
            $companyId = (int) Auth::user()->company_id;
        }

        if ($existingId !== null) {
            $existingItemType = self::findVisibleToCurrentUser($existingId);

            if ($existingItemType !== null && $companyId === null) {
                $companyId = self::resolveScopedId($existingItemType->company_id);
            }
        }

        $active = $row['active'] ?? $row['is_active'] ?? 1;

        return (object) [
            'company_id' => $companyId,
            'name' => (string) ($row['name'] ?? ''),
            'active' => self::normalizeImportBool($active),
        ];
    }

    protected static function normalizeImportBool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
