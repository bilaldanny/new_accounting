<?php

namespace App\Services;

use App\Models\City;
use App\Models\Country;
use App\Models\State;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class CountryStateCityApiSync
{
    public const STATES_CURSOR_KEY = 'csc.states.last_country_id';

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
     * @return array{created: int, updated: int}
     */
    public function syncCities(?string $countryIso2 = null): array
    {
        $countries = $this->countriesForSync($countryIso2);
        $created = 0;
        $updated = 0;

        foreach ($countries as $country) {
            $states = State::query()
                ->where('country_id', $country->id)
                ->whereNotNull('iso2')
                ->where('iso2', '!=', '')
                ->get();

            foreach ($states->chunk(10) as $chunk) {
                $responses = Http::pool(fn (Pool $pool) => $chunk
                    ->mapWithKeys(fn (State $state) => [
                        $state->id => $this->pooledGet(
                            $pool,
                            (string) $state->id,
                            '/countries/'.$country->iso2.'/states/'.$state->iso2.'/cities',
                        ),
                    ])
                    ->all());

                foreach ($chunk as $state) {
                    $rows = $this->rowsFromPooledResponse($responses[$state->id] ?? null);

                    foreach ($rows as $row) {
                        $result = $this->upsertCity($country, $state, $row);
                        $created += $result === 'created' ? 1 : 0;
                        $updated += $result === 'updated' ? 1 : 0;
                    }
                }
            }
        }

        return ['created' => $created, 'updated' => $updated];
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

    protected function nextCountryForStateSync(): ?Country
    {
        $lastId = (int) Cache::get(self::STATES_CURSOR_KEY, 0);
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
    protected function getJsonList(string $path): array
    {
        $response = $this->client()->get($path);

        if ($response->unauthorized() || $response->forbidden()) {
            throw new RuntimeException('Country State City API rejected the request. Check CSCAPI_KEY.');
        }

        $response->throw();

        return $this->assertList($response->json());
    }

    protected function pooledGet(Pool $pool, string $as, string $path): mixed
    {
        return $pool->as($as)
            ->baseUrl((string) config('services.countrystatecity.base_url'))
            ->withHeaders(['X-CSCAPI-KEY' => $this->apiKey()])
            ->acceptJson()
            ->timeout(30)
            ->connectTimeout(5)
            ->retry([200, 500, 1000], throw: false)
            ->get($path);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function rowsFromPooledResponse(mixed $response): array
    {
        if (! is_object($response) || ! method_exists($response, 'successful')) {
            return [];
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
