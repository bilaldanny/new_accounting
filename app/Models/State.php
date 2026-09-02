<?php

namespace App\Models;

use Database\Factories\StateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class State extends Model
{
    /** @use HasFactory<StateFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'country_id',
        'country_code',
        'fips_code',
        'iso2',
        'type',
        'level',
        'parent_id',
        'latitude',
        'longitude',
        'flag',
        'wikiDataId',
    ];

    protected $attributes = [
        'flag' => true,
    ];

    protected function casts(): array
    {
        return [
            'flag' => 'boolean',
            'country_id' => 'integer',
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
        ];
    }

    /**
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * @return HasMany<City, $this>
     */
    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }

    public static function normalizeName(?string $name): string
    {
        return trim((string) $name);
    }

    public static function normalizeIso2(?string $iso2): ?string
    {
        $normalized = Str::upper(trim((string) $iso2));

        return $normalized === '' ? null : $normalized;
    }

    public static function resolveCountryCode(Country $country): string
    {
        $code = Country::normalizeIso2($country->iso2);

        return $code !== '' ? $code : 'XX';
    }

    public static function nameExists(string $name, int $countryId, ?int $exceptId = null): bool
    {
        $normalized = self::normalizeName($name);

        if ($normalized === '') {
            return false;
        }

        return self::query()
            ->where('country_id', $countryId)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->whereRaw('LOWER(name) = ?', [Str::lower($normalized)])
            ->exists();
    }

    /**
     * @throws ValidationException
     */
    public static function assertUniqueName(string $name, int $countryId, ?int $exceptId = null): void
    {
        if (self::nameExists($name, $countryId, $exceptId)) {
            throw ValidationException::withMessages([
                'name' => ['A state with this name already exists in the selected country.'],
            ]);
        }
    }

    public static function storeFromRequest(Request $request): self
    {
        $country = Country::query()->findOrFail((int) $request->country_id);
        $name = self::normalizeName($request->string('name')->toString());

        self::assertUniqueName($name, $country->id);

        return self::query()->create([
            'name' => $name,
            'country_id' => $country->id,
            'country_code' => self::resolveCountryCode($country),
            'iso2' => self::normalizeIso2($request->input('iso2')),
            'type' => $request->filled('type') ? $request->string('type')->toString() : null,
            'flag' => $request->boolean('flag', true),
        ]);
    }

    public function updateFromRequest(Request $request): self
    {
        $country = Country::query()->findOrFail((int) $request->country_id);
        $name = self::normalizeName($request->string('name')->toString());

        self::assertUniqueName($name, $country->id, $this->id);

        $this->update([
            'name' => $name,
            'country_id' => $country->id,
            'country_code' => self::resolveCountryCode($country),
            'iso2' => self::normalizeIso2($request->input('iso2')),
            'type' => $request->filled('type') ? $request->string('type')->toString() : null,
            'flag' => $request->boolean('flag', (bool) $this->flag),
        ]);

        return $this;
    }

    public static function deleteState(int $id): void
    {
        $state = self::query()->find($id);

        if ($state !== null) {
            $state->delete();
        }
    }
}
