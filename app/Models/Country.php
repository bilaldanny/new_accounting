<?php

namespace App\Models;

use Database\Factories\CountryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Country extends Model
{
    /** @use HasFactory<CountryFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'iso3',
        'numeric_code',
        'iso2',
        'phonecode',
        'capital',
        'currency',
        'currency_name',
        'currency_symbol',
        'tld',
        'native',
        'region',
        'subregion',
        'nationality',
        'timezones',
        'translations',
        'latitude',
        'longitude',
        'emoji',
        'emojiU',
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
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
        ];
    }

    /**
     * @return HasMany<State, $this>
     */
    public function states(): HasMany
    {
        return $this->hasMany(State::class);
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

    public static function normalizeIso2(?string $iso2): string
    {
        return Str::upper(trim((string) $iso2));
    }

    public static function normalizeIso3(?string $iso3): ?string
    {
        $normalized = Str::upper(trim((string) $iso3));

        return $normalized === '' ? null : $normalized;
    }

    public static function nameExists(string $name, ?int $exceptId = null): bool
    {
        $normalized = self::normalizeName($name);

        if ($normalized === '') {
            return false;
        }

        return self::query()
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->whereRaw('LOWER(name) = ?', [Str::lower($normalized)])
            ->exists();
    }

    public static function iso2Exists(string $iso2, ?int $exceptId = null): bool
    {
        $normalized = self::normalizeIso2($iso2);

        if ($normalized === '') {
            return false;
        }

        return self::query()
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->where('iso2', $normalized)
            ->exists();
    }

    /**
     * @throws ValidationException
     */
    public static function assertUniqueName(string $name, ?int $exceptId = null): void
    {
        if (self::nameExists($name, $exceptId)) {
            throw ValidationException::withMessages([
                'name' => ['A country with this name already exists.'],
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    public static function assertUniqueIso2(string $iso2, ?int $exceptId = null): void
    {
        if (self::iso2Exists($iso2, $exceptId)) {
            throw ValidationException::withMessages([
                'iso2' => ['This ISO2 code is already taken.'],
            ]);
        }
    }

    public static function storeFromRequest(Request $request): self
    {
        $name = self::normalizeName($request->string('name')->toString());
        $iso2 = self::normalizeIso2($request->string('iso2')->toString());

        self::assertUniqueName($name);
        self::assertUniqueIso2($iso2);

        return self::query()->create([
            'name' => $name,
            'iso2' => $iso2,
            'iso3' => self::normalizeIso3($request->input('iso3')),
            'phonecode' => $request->filled('phonecode') ? $request->string('phonecode')->toString() : null,
            'capital' => $request->filled('capital') ? $request->string('capital')->toString() : null,
            'nationality' => $request->filled('nationality') ? $request->string('nationality')->toString() : null,
            'flag' => $request->boolean('flag', true),
        ]);
    }

    public function updateFromRequest(Request $request): self
    {
        $name = self::normalizeName($request->string('name')->toString());
        $iso2 = self::normalizeIso2($request->string('iso2')->toString());

        self::assertUniqueName($name, $this->id);
        self::assertUniqueIso2($iso2, $this->id);

        $this->update([
            'name' => $name,
            'iso2' => $iso2,
            'iso3' => self::normalizeIso3($request->input('iso3')),
            'phonecode' => $request->filled('phonecode') ? $request->string('phonecode')->toString() : null,
            'capital' => $request->filled('capital') ? $request->string('capital')->toString() : null,
            'nationality' => $request->filled('nationality') ? $request->string('nationality')->toString() : null,
            'flag' => $request->boolean('flag', (bool) $this->flag),
        ]);

        return $this;
    }

    public static function deleteCountry(int $id): void
    {
        $country = self::query()->find($id);

        if ($country !== null) {
            $country->delete();
        }
    }
}
