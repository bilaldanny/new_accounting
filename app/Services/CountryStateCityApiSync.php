<?php

namespace App\Services;

use App\Models\City;
use App\Models\Country;
use App\Models\Currency;
use App\Models\State;
use App\Models\Timezone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class CountryStateCityApiSync
{
    public const STATES_CURSOR_KEY = 'csc.states.last_country_id';

    public const CITIES_CURSOR_KEY = 'csc.cities.last_state_id';

    public const TIMEZONES_CURSOR_KEY = 'csc.timezones.last_country_id';

    /**
     * @return array{created: int, updated: int}
     */
    public function syncCountries(): array
    {
        $rows = $this->getJsonList('/countries');
        $created = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $iso2 = Country::normalizeIso2($this->stringValue($row['iso2'] ?? null));

            if ($iso2 === '') {
                continue;
            }

            $payload = $this->countryPayload($row, $iso2);
            $country = Country::withTrashed()->where('iso2', $iso2)->first();

            if ($country === null) {
                Country::query()->create($payload);
                $created++;

                continue;
            }

            if ($country->trashed()) {
                $country->restore();
            }

            $country->fill($this->withoutFlag($payload))->save();
            $updated++;
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * @return array{created: int, updated: int}
     */
    public function syncCurrencies(): array
    {
        $rows = $this->getJsonList('/currency');
        $created = 0;
        $updated = 0;
        $seen = [];

        foreach ($rows as $row) {
            $payload = $this->currencyPayload($row);

            if ($payload === null) {
                continue;
            }

            $code = $payload['code'];

            if (isset($seen[$code])) {
                continue;
            }

            $seen[$code] = true;

            $currency = Currency::withTrashed()->where('code', $code)->first();

            if ($currency === null) {
                Currency::query()->create($payload);
                $created++;

                continue;
            }

            if ($currency->trashed()) {
                $currency->restore();
            }

            unset($payload['is_active']);
            $currency->fill($payload)->save();
            $updated++;
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * @return array{created: int, updated: int, countries: int, remaining: int, done: bool, country: ?string, iso2: ?string}
     */
    public function syncStates(?string $countryIso2 = null): array
    {
        $filteredIso2 = Country::normalizeIso2($countryIso2);

        if ($filteredIso2 !== '') {
            $country = Country::query()->where('iso2', $filteredIso2)->first();

            if ($country === null) {
                return $this->emptyStateSyncResult();
            }

            return $this->syncStatesForCountry($country, remaining: 0, done: true, rememberCursor: false);
        }

        $country = $this->nextCountryForStateSync();

        if ($country === null) {
            $this->syncCountries();
            Cache::forget(self::STATES_CURSOR_KEY);
            $country = $this->nextCountryForStateSync();
        }

        if ($country === null) {
            return $this->emptyStateSyncResult();
        }

        $remaining = $this->countriesAfter($country)->count();

        return $this->syncStatesForCountry($country, $remaining, $remaining === 0);
    }

    /**
     * @return array{created: int, updated: int, countries: int, remaining: int, done: bool, country: ?string, iso2: ?string}
     */
    public function syncTimezones(?string $countryIso2 = null): array
    {
        $filteredIso2 = Country::normalizeIso2($countryIso2);

        if ($filteredIso2 !== '') {
            $country = Country::query()->where('iso2', $filteredIso2)->first();

            if ($country === null) {
                return $this->emptyTimezoneSyncResult();
            }

            return $this->syncTimezonesForCountry($country, remaining: 0, done: true, rememberCursor: false);
        }

        $country = $this->nextCountryForStateSync(self::TIMEZONES_CURSOR_KEY);

        if ($country === null) {
            $this->syncCountries();
            Cache::forget(self::TIMEZONES_CURSOR_KEY);
            $country = $this->nextCountryForStateSync(self::TIMEZONES_CURSOR_KEY);
        }

        if ($country === null) {
            return $this->emptyTimezoneSyncResult();
        }

        $remaining = $this->countriesAfter($country)->count();

        return $this->syncTimezonesForCountry($country, $remaining, $remaining === 0);
    }

    /**
     * @return array{created: int, updated: int, remaining: int, done: bool, country: ?string, iso2: ?string, state: ?string, state_iso2: ?string}
     */
    public function syncCities(?string $countryIso2 = null): array
    {
        $countryId = null;
        $filteredIso2 = Country::normalizeIso2($countryIso2);

        if ($filteredIso2 !== '') {
            $country = Country::query()->where('iso2', $filteredIso2)->first();

            if ($country === null) {
                return $this->emptyCitySyncResult(null);
            }

            $countryId = $country->id;
        }

        $state = $this->nextStateForCitySync($countryId);

        if ($state === null) {
            return $this->emptyCitySyncResult($countryId);
        }

        $state->loadMissing('country');
        $country = $state->country;

        if ($country === null || Country::normalizeIso2($country->iso2) === '' || State::normalizeIso2($state->iso2) === null) {
            return $this->emptyCitySyncResult($countryId);
        }

        $remaining = $this->statesAfter($state, $countryId)->count();

        return $this->syncCitiesForState($country, $state, $remaining, $remaining === 0, $countryId);
    }

    /**
     * @return array{created: int, updated: int, countries: int, remaining: int, done: bool, country: ?string, iso2: ?string}
     */
    protected function syncStatesForCountry(Country $country, int $remaining, bool $done, bool $rememberCursor = true): array
    {
        $created = 0;
        $updated = 0;

        foreach ($this->getStatesByCountry($country->iso2) as $row) {
            $result = $this->upsertState($country, $row);
            $created += $result === 'created' ? 1 : 0;
            $updated += $result === 'updated' ? 1 : 0;
        }

        if ($rememberCursor) {
            if ($done) {
                Cache::forget(self::STATES_CURSOR_KEY);
            } else {
                Cache::forever(self::STATES_CURSOR_KEY, $country->id);
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'countries' => 1,
            'remaining' => $remaining,
            'done' => $done,
            'country' => $country->name,
            'iso2' => $country->iso2,
        ];
    }

    /**
     * @return array{created: int, updated: int, countries: int, remaining: int, done: bool, country: ?string, iso2: ?string}
     */
    protected function emptyStateSyncResult(): array
    {
        Cache::forget(self::STATES_CURSOR_KEY);

        return [
            'created' => 0,
            'updated' => 0,
            'countries' => 0,
            'remaining' => 0,
            'done' => true,
            'country' => null,
            'iso2' => null,
        ];
    }

    /**
     * @return array{created: int, updated: int, countries: int, remaining: int, done: bool, country: ?string, iso2: ?string}
     */
    protected function syncTimezonesForCountry(Country $country, int $remaining, bool $done, bool $rememberCursor = true): array
    {
        $created = 0;
        $updated = 0;

        foreach ($this->getTimezonesByCountry($country->iso2) as $name) {
            $result = $this->upsertTimezone($name);
            $created += $result === 'created' ? 1 : 0;
            $updated += $result === 'updated' ? 1 : 0;
        }

        if ($rememberCursor) {
            if ($done) {
                Cache::forget(self::TIMEZONES_CURSOR_KEY);
            } else {
                Cache::forever(self::TIMEZONES_CURSOR_KEY, $country->id);
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'countries' => 1,
            'remaining' => $remaining,
            'done' => $done,
            'country' => $country->name,
            'iso2' => $country->iso2,
        ];
    }

    /**
     * @return array{created: int, updated: int, countries: int, remaining: int, done: bool, country: ?string, iso2: ?string}
     */
    protected function emptyTimezoneSyncResult(): array
    {
        Cache::forget(self::TIMEZONES_CURSOR_KEY);

        return [
            'created' => 0,
            'updated' => 0,
            'countries' => 0,
            'remaining' => 0,
            'done' => true,
            'country' => null,
            'iso2' => null,
        ];
    }

    protected function upsertTimezone(string $name): ?string
    {
        $name = Timezone::normalizeName($name);

        if ($name === '') {
            return null;
        }

        $timezone = Timezone::withTrashed()
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
            ->first();

        if ($timezone === null) {
            Timezone::query()->create(['name' => $name]);

            return 'created';
        }

        if ($timezone->trashed()) {
            $timezone->restore();
        }

        $timezone->fill(['name' => $name])->save();

        return 'updated';
    }

    protected function nextCountryForStateSync(?string $cursorKey = null): ?Country
    {
        $cursorKey ??= self::STATES_CURSOR_KEY;
        $lastId = (int) Cache::get($cursorKey, 0);
        $last = $lastId > 0 ? Country::query()->find($lastId) : null;

        $query = $this->stateSyncCountryQuery();

        if ($last !== null) {
            $query->where(function ($query) use ($last): void {
                $query->where('name', '>', $last->name)
                    ->orWhere(function ($query) use ($last): void {
                        $query->where('name', $last->name)
                            ->where('id', '>', $last->id);
                    });
            });
        }

        return $query->first();
    }

    /**
     * @return Builder<Country>
     */
    protected function countriesAfter(Country $country): Builder
    {
        return $this->stateSyncCountryQuery()
            ->where(function ($query) use ($country): void {
                $query->where('name', '>', $country->name)
                    ->orWhere(function ($query) use ($country): void {
                        $query->where('name', $country->name)
                            ->where('id', '>', $country->id);
                    });
            });
    }

    /**
     * @return Builder<Country>
     */
    protected function stateSyncCountryQuery(): Builder
    {
        return Country::query()
            ->whereNotNull('iso2')
            ->where('iso2', '!=', '')
            ->orderBy('name')
            ->orderBy('id');
    }

    /**
     * @return array{created: int, updated: int, remaining: int, done: bool, country: ?string, iso2: ?string, state: ?string, state_iso2: ?string}
     */
    protected function syncCitiesForState(Country $country, State $state, int $remaining, bool $done, ?int $cursorCountryId): array
    {
        $created = 0;
        $updated = 0;
        $stateIso2 = State::normalizeIso2($state->iso2) ?? '';
        $countryIso2 = Country::normalizeIso2($country->iso2);

        foreach ($this->getCitiesByState($countryIso2, $stateIso2) as $row) {
            $result = $this->upsertCity($country, $state, $row);
            $created += $result === 'created' ? 1 : 0;
            $updated += $result === 'updated' ? 1 : 0;
        }

        $cursorKey = $this->citiesCursorKey($cursorCountryId);

        if ($done) {
            Cache::forget($cursorKey);
        } else {
            Cache::forever($cursorKey, $state->id);
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'remaining' => $remaining,
            'done' => $done,
            'country' => $country->name,
            'iso2' => $countryIso2,
            'state' => $state->name,
            'state_iso2' => $stateIso2,
        ];
    }

    /**
     * @return array{created: int, updated: int, remaining: int, done: bool, country: ?string, iso2: ?string, state: ?string, state_iso2: ?string}
     */
    protected function emptyCitySyncResult(?int $cursorCountryId): array
    {
        Cache::forget($this->citiesCursorKey($cursorCountryId));

        return [
            'created' => 0,
            'updated' => 0,
            'remaining' => 0,
            'done' => true,
            'country' => null,
            'iso2' => null,
            'state' => null,
            'state_iso2' => null,
        ];
    }

    protected function nextStateForCitySync(?int $countryId): ?State
    {
        $cursorKey = $this->citiesCursorKey($countryId);
        $lastId = (int) Cache::get($cursorKey, 0);
        $last = $lastId > 0 ? State::query()->with('country')->find($lastId) : null;

        $query = $this->citySyncStateQuery($countryId);

        if ($last !== null && ($countryId === null || (int) $last->country_id === $countryId)) {
            $lastCountry = $last->country;

            if ($lastCountry !== null) {
                $query->where(function ($query) use ($last, $lastCountry): void {
                    $query->where('countries.name', '>', $lastCountry->name)
                        ->orWhere(function ($query) use ($lastCountry): void {
                            $query->where('countries.name', $lastCountry->name)
                                ->where('countries.id', '>', $lastCountry->id);
                        })
                        ->orWhere(function ($query) use ($last, $lastCountry): void {
                            $query->where('countries.name', $lastCountry->name)
                                ->where('countries.id', $lastCountry->id)
                                ->where('states.name', '>', $last->name);
                        })
                        ->orWhere(function ($query) use ($last, $lastCountry): void {
                            $query->where('countries.name', $lastCountry->name)
                                ->where('countries.id', $lastCountry->id)
                                ->where('states.name', $last->name)
                                ->where('states.id', '>', $last->id);
                        });
                });
            }
        }

        return $query->first();
    }

    /**
     * @return Builder<State>
     */
    protected function statesAfter(State $state, ?int $countryId): Builder
    {
        $state->loadMissing('country');
        $country = $state->country;

        $query = $this->citySyncStateQuery($countryId);

        if ($country === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($query) use ($state, $country): void {
            $query->where('countries.name', '>', $country->name)
                ->orWhere(function ($query) use ($country): void {
                    $query->where('countries.name', $country->name)
                        ->where('countries.id', '>', $country->id);
                })
                ->orWhere(function ($query) use ($state, $country): void {
                    $query->where('countries.name', $country->name)
                        ->where('countries.id', $country->id)
                        ->where('states.name', '>', $state->name);
                })
                ->orWhere(function ($query) use ($state, $country): void {
                    $query->where('countries.name', $country->name)
                        ->where('countries.id', $country->id)
                        ->where('states.name', $state->name)
                        ->where('states.id', '>', $state->id);
                });
        });
    }

    /**
     * @return Builder<State>
     */
    protected function citySyncStateQuery(?int $countryId): Builder
    {
        return State::query()
            ->select('states.*')
            ->join('countries', 'countries.id', '=', 'states.country_id')
            ->whereNotNull('states.iso2')
            ->where('states.iso2', '!=', '')
            ->whereNotNull('countries.iso2')
            ->where('countries.iso2', '!=', '')
            ->whereNull('countries.deleted_at')
            ->when($countryId !== null, fn (Builder $query) => $query->where('states.country_id', $countryId))
            ->orderBy('countries.name')
            ->orderBy('countries.id')
            ->orderBy('states.name')
            ->orderBy('states.id');
    }

    protected function citiesCursorKey(?int $countryId): string
    {
        return $countryId === null
            ? self::CITIES_CURSOR_KEY
            : self::CITIES_CURSOR_KEY.'.country.'.$countryId;
    }

    /**
     * @return Collection<int, Country>
     */
    protected function countriesForSync(?string $countryIso2): Collection
    {
        $iso2 = Country::normalizeIso2($countryIso2);

        $query = Country::query()->whereNotNull('iso2')->where('iso2', '!=', '');

        if ($iso2 !== '') {
            $query->where('iso2', $iso2);
        }

        return $query->orderBy('name')->get();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{currency_name: string, code: string, symbol: string, is_active: bool}|null
     */
    protected function currencyPayload(array $row): ?array
    {
        $nested = $row['currency'] ?? null;
        $data = is_array($nested) ? $nested : $row;
        $code = Currency::normalizeCode($this->stringValue($data['code'] ?? null));

        if (strlen($code) !== 3) {
            return null;
        }

        $name = $this->stringValue($data['name'] ?? $data['currency_name'] ?? '');
        $symbol = $this->stringValue($data['symbol'] ?? $data['currency_symbol'] ?? '');

        return [
            'currency_name' => $name !== '' ? $name : $code,
            'code' => $code,
            'symbol' => $symbol !== '' ? $symbol : $code,
            'is_active' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function countryPayload(array $row, string $iso2): array
    {
        return [
            'name' => Country::normalizeName($this->stringValue($row['name'] ?? null)) ?: $iso2,
            'iso2' => $iso2,
            'iso3' => Country::normalizeIso3($this->stringValue($row['iso3'] ?? null)),
            'numeric_code' => $this->stringOrNull($row['numeric_code'] ?? null),
            'phonecode' => $this->stringOrNull($row['phonecode'] ?? null),
            'capital' => $this->stringOrNull($row['capital'] ?? null),
            'currency' => $this->stringOrNull($row['currency'] ?? null),
            'currency_name' => $this->stringOrNull($row['currency_name'] ?? null),
            'currency_symbol' => $this->stringOrNull($row['currency_symbol'] ?? null),
            'tld' => $this->stringOrNull($row['tld'] ?? null),
            'native' => $this->stringOrNull($row['native'] ?? null),
            'region' => $this->stringOrNull($row['region'] ?? null),
            'subregion' => $this->stringOrNull($row['subregion'] ?? null),
            'nationality' => $this->stringOrNull($row['nationality'] ?? null),
            'timezones' => $this->jsonOrString($row['timezones'] ?? null),
            'translations' => $this->jsonOrString($row['translations'] ?? null),
            'latitude' => $this->coordinate($row['latitude'] ?? null),
            'longitude' => $this->coordinate($row['longitude'] ?? null),
            'emoji' => $this->stringOrNull($row['emoji'] ?? null),
            'emojiU' => $this->stringOrNull($row['emojiU'] ?? null),
            'wikiDataId' => $this->stringOrNull($row['wikiDataId'] ?? null),
            'flag' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function upsertState(Country $country, array $row): ?string
    {
        $name = State::normalizeName($this->stringValue($row['name'] ?? null));

        if ($name === '') {
            return null;
        }

        $iso2 = State::normalizeIso2($this->stringValue($row['iso2'] ?? null));
        $payload = [
            'name' => $name,
            'country_id' => $country->id,
            'country_code' => State::resolveCountryCode($country),
            'iso2' => $iso2,
            'type' => $this->stringOrNull($row['type'] ?? null),
            'fips_code' => $this->stringOrNull($row['fips_code'] ?? null),
            'latitude' => $this->coordinate($row['latitude'] ?? null),
            'longitude' => $this->coordinate($row['longitude'] ?? null),
            'wikiDataId' => $this->stringOrNull($row['wikiDataId'] ?? null),
            'flag' => true,
        ];

        $query = State::withTrashed()->where('country_id', $country->id);
        $state = null;

        if ($iso2 !== null) {
            $state = (clone $query)->where('iso2', $iso2)->first();
        }

        $state ??= (clone $query)->whereRaw('LOWER(name) = ?', [Str::lower($name)])->first();

        if ($state === null) {
            State::query()->create($payload);

            return 'created';
        }

        if ($state->trashed()) {
            $state->restore();
        }

        $state->fill($this->withoutFlag($payload))->save();

        return 'updated';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function upsertCity(Country $country, State $state, array $row): ?string
    {
        $name = City::normalizeName($this->stringValue($row['name'] ?? null));

        if ($name === '') {
            return null;
        }

        $payload = [
            'name' => $name,
            'state_id' => $state->id,
            'state_code' => City::resolveStateCode($state, $country),
            'country_id' => $country->id,
            'country_code' => State::resolveCountryCode($country),
            'latitude' => $this->coordinate($row['latitude'] ?? null) ?? 0,
            'longitude' => $this->coordinate($row['longitude'] ?? null) ?? 0,
            'wikiDataId' => $this->stringOrNull($row['wikiDataId'] ?? null),
            'flag' => true,
        ];

        $city = City::withTrashed()
            ->where('state_id', $state->id)
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
            ->first();

        if ($city === null) {
            City::query()->create($payload);

            return 'created';
        }

        if ($city->trashed()) {
            $city->restore();
        }

        $city->fill($this->withoutFlag($payload))->save();

        return 'updated';
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function getStatesByCountry(string $countryCode): array
    {
        $response = $this->client()->get('/countries/'.$countryCode.'/states');

        if ($response->unauthorized() || $response->forbidden()) {
            throw new RuntimeException('Country State City API rejected the request. Check CSCAPI_KEY.');
        }

        if (! $response->successful()) {
            return [];
        }

        try {
            return $this->assertList($response->json());
        } catch (RuntimeException) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function getCitiesByState(string $countryCode, string $stateCode): array
    {
        $response = $this->client()->get('/countries/'.$countryCode.'/states/'.$stateCode.'/cities');

        if ($response->unauthorized() || $response->forbidden()) {
            throw new RuntimeException('Country State City API rejected the request. Check CSCAPI_KEY.');
        }

        if (! $response->successful()) {
            return [];
        }

        try {
            return $this->assertList($response->json());
        } catch (RuntimeException) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    protected function getTimezonesByCountry(string $countryCode): array
    {
        $iso2 = Country::normalizeIso2($countryCode);

        if ($iso2 === '') {
            return [];
        }

        $response = $this->client()->get('/timezone/'.$iso2);

        if ($response->unauthorized() || $response->forbidden()) {
            throw new RuntimeException('Country State City API rejected the request. Check CSCAPI_KEY.');
        }

        if (! $response->successful()) {
            return [];
        }

        return $this->timezoneNamesFromPayload($response->json());
    }

    /**
     * @return list<string>
     */
    protected function timezoneNamesFromPayload(mixed $payload): array
    {
        if (! is_array($payload) || isset($payload['error'])) {
            return [];
        }

        if ($payload === []) {
            return [];
        }

        if (array_is_list($payload)) {
            $names = [];

            foreach ($payload as $item) {
                if (is_string($item)) {
                    $name = Timezone::normalizeName($item);

                    if ($name !== '') {
                        $names[] = $name;
                    }

                    continue;
                }

                if (is_array($item)) {
                    $name = $this->timezoneNameFromRow($item);

                    if ($name !== '') {
                        $names[] = $name;
                    }
                }
            }

            return array_values(array_unique($names));
        }

        $name = $this->timezoneNameFromRow($payload);

        return $name === '' ? [] : [$name];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function timezoneNameFromRow(array $row): string
    {
        return Timezone::normalizeName(
            $this->stringValue($row['iana'] ?? $row['zoneName'] ?? $row['name'] ?? null)
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function getJsonList(string $path): array
    {
        $response = $this->client()->get($path);

        if ($response->unauthorized() || $response->forbidden()) {
            throw new RuntimeException('Country State City API rejected the request. Check CSCAPI_KEY.');
        }

        $response->throw();

        return $this->assertList($response->json());
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function assertList(mixed $payload): array
    {
        if (is_array($payload) && isset($payload['error'])) {
            throw new RuntimeException((string) $payload['error']);
        }

        if (! is_array($payload)) {
            throw new RuntimeException('Country State City API returned an unexpected response.');
        }

        if ($payload !== [] && ! array_is_list($payload)) {
            throw new RuntimeException('Country State City API returned an unexpected response.');
        }

        return $payload;
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl((string) config('services.countrystatecity.base_url'))
            ->withHeaders(['X-CSCAPI-KEY' => $this->apiKey()])
            ->acceptJson()
            ->timeout(30)
            ->connectTimeout(5)
            ->retry([200, 500, 1000], throw: false);
    }

    protected function apiKey(): string
    {
        $key = trim((string) config('services.countrystatecity.key'));

        if ($key === '') {
            throw new RuntimeException('Set CSCAPI_KEY in the environment before fetching location data.');
        }

        return $key;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function withoutFlag(array $payload): array
    {
        unset($payload['flag']);

        return $payload;
    }

    protected function stringValue(mixed $value): string
    {
        return trim((string) $value);
    }

    protected function stringOrNull(mixed $value): ?string
    {
        $normalized = $this->stringValue($value);

        return $normalized === '' ? null : $normalized;
    }

    protected function jsonOrString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value) ?: null;
        }

        return $this->stringOrNull($value);
    }

    protected function coordinate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
