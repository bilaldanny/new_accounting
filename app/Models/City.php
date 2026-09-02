<?php

namespace App\Models;

use Database\Factories\CityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class City extends Model
{
    /** @use HasFactory<CityFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'state_id',
        'state_code',
        'country_id',
        'country_code',
        'latitude',
        'longitude',
        'flag',
        'wikiDataId',
    ];

    protected $attributes = [
        'flag' => true,
        'latitude' => 0,
        'longitude' => 0,
    ];

    protected function casts(): array
    {
        return [
            'flag' => 'boolean',
            'state_id' => 'integer',
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
     * @return BelongsTo<State, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    public static function normalizeName(?string $name): string
    {
        return trim((string) $name);
    }

    public static function resolveStateCode(State $state, Country $country): string
    {
        $code = State::normalizeIso2($state->iso2);

        if ($code !== null) {
            return $code;
        }

        return State::resolveCountryCode($country);
    }

    public static function nameExists(string $name, int $stateId, ?int $exceptId = null): bool
    {
        $normalized = self::normalizeName($name);

        if ($normalized === '') {
            return false;
        }

        return self::query()
            ->where('state_id', $stateId)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->whereRaw('LOWER(name) = ?', [Str::lower($normalized)])
            ->exists();
    }

    /**
     * @throws ValidationException
     */
    public static function assertUniqueName(string $name, int $stateId, ?int $exceptId = null): void
    {
        if (self::nameExists($name, $stateId, $exceptId)) {
            throw ValidationException::withMessages([
                'name' => ['A city with this name already exists in the selected state.'],
            ]);
        }
    }

    public static function storeFromRequest(Request $request): self
    {
        $state = State::query()->with('country')->findOrFail((int) $request->state_id);
        $country = $state->country ?? Country::query()->findOrFail((int) $state->country_id);
        $name = self::normalizeName($request->string('name')->toString());

        self::assertUniqueName($name, $state->id);

        return self::query()->create([
            'name' => $name,
            'state_id' => $state->id,
            'state_code' => self::resolveStateCode($state, $country),
            'country_id' => $country->id,
            'country_code' => State::resolveCountryCode($country),
            'latitude' => $request->filled('latitude') ? $request->input('latitude') : 0,
            'longitude' => $request->filled('longitude') ? $request->input('longitude') : 0,
            'flag' => $request->boolean('flag', true),
        ]);
    }

    public function updateFromRequest(Request $request): self
    {
        $state = State::query()->with('country')->findOrFail((int) $request->state_id);
        $country = $state->country ?? Country::query()->findOrFail((int) $state->country_id);
        $name = self::normalizeName($request->string('name')->toString());

        self::assertUniqueName($name, $state->id, $this->id);

        $this->update([
            'name' => $name,
            'state_id' => $state->id,
            'state_code' => self::resolveStateCode($state, $country),
            'country_id' => $country->id,
            'country_code' => State::resolveCountryCode($country),
            'latitude' => $request->filled('latitude') ? $request->input('latitude') : $this->latitude,
            'longitude' => $request->filled('longitude') ? $request->input('longitude') : $this->longitude,
            'flag' => $request->boolean('flag', (bool) $this->flag),
        ]);

        return $this;
    }

    public static function deleteCity(int $id): void
    {
        $city = self::query()->find($id);

        if ($city !== null) {
            $city->delete();
        }
    }
}
