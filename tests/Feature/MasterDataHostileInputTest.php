<?php

use App\Models\City;
use App\Models\Country;
use App\Models\Department;
use App\Models\Role;
use App\Models\State;
use App\Models\Timezone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The create and update of roles, departments, cities, countries, states and timezones answer a hostile value
 * (an array where a single value belongs, a name too long for its column, a company or branch id that does not
 * exist) with a 422 and change nothing, never a 500.
 *
 * @return array{company: int, branch: int, country: Country, state: State, role: Role, department: Department, timezone: Timezone, city: City}
 */
function mdhScope(): array
{
    $scope = seedCompanyAndBranches();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $country = Country::factory()->create(['name' => 'Probeland', 'iso2' => 'PL']);
    $state = State::factory()->create(['name' => 'Probe State', 'country_id' => $country->id, 'country_code' => 'PL', 'iso2' => 'PS']);

    return [
        'company' => (int) $scope['company_id'],
        'branch' => (int) $scope['branch_one_id'],
        'country' => $country,
        'state' => $state,
        'role' => Role::query()->create(['name' => 'Probe Role', 'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_one_id'], 'is_active' => true]),
        'department' => Department::query()->create(['name' => 'Probe Dept', 'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_one_id'], 'active' => true]),
        'timezone' => Timezone::query()->create(['name' => 'Probe/Zone']),
        'city' => City::factory()->create(['name' => 'Probe City', 'country_id' => $country->id, 'state_id' => $state->id]),
    ];
}

/**
 * A valid body for each resource, and the id of the existing record its update goes to.
 *
 * @param  array<string, mixed>  $s
 * @return array{0: array<string, mixed>, 1: int}
 */
function mdhBody(string $resource, array $s): array
{
    return match ($resource) {
        'roles' => [['name' => 'New Role', 'company_id' => $s['company'], 'branch_id' => $s['branch'], 'is_active' => true], $s['role']->id],
        'departments' => [['name' => 'New Dept', 'company_id' => $s['company'], 'branch_id' => $s['branch'], 'active' => true], $s['department']->id],
        'countries' => [['name' => 'Newland', 'iso2' => 'NL', 'iso3' => 'NLD', 'phonecode' => '31', 'capital' => 'Nc', 'nationality' => 'Nn', 'flag' => true], $s['country']->id],
        'states' => [['name' => 'New State', 'country_id' => $s['country']->id, 'iso2' => 'NS', 'type' => 'province', 'flag' => true], $s['state']->id],
        'cities' => [['name' => 'New City', 'country_id' => $s['country']->id, 'state_id' => $s['state']->id, 'latitude' => 1.5, 'longitude' => 2.5, 'flag' => true], $s['city']->id],
        'timezones' => [['name' => 'New/Zone'], $s['timezone']->id],
    };
}

/**
 * Sends $body to the create (POST) or update (PUT) of the resource.
 *
 * @param  array<string, mixed>  $s
 * @param  array<string, mixed>  $body
 */
function mdhSend(string $method, string $resource, array $s, array $body): TestResponse
{
    [, $id] = mdhBody($resource, $s);

    return test()->json($method, $method === 'POST' ? "/api/{$resource}" : "/api/{$resource}/{$id}", $body);
}

/**
 * The table and record count of a resource, to prove a rejected request wrote nothing.
 */
function mdhCount(string $resource): int
{
    return DB::table($resource)->count();
}

dataset('mdh methods', ['create' => ['POST'], 'update' => ['PUT']]);
dataset('mdh resources', ['roles', 'departments', 'cities', 'countries', 'states', 'timezones']);

test('an array name is a 422 on the create and the update of every master endpoint', function (string $method, string $resource) {
    $s = mdhScope();
    [$body] = mdhBody($resource, $s);
    $before = mdhCount($resource);
    $names = fn (): array => DB::table($resource)->pluck('name')->all();
    $namesBefore = $names();

    foreach ([['x'], [['x']], ['a' => 'b']] as $hostile) {
        mdhSend($method, $resource, $s, array_merge($body, ['name' => $hostile]))->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    expect(mdhCount($resource))->toBe($before)
        ->and($names())->toBe($namesBefore);
})->with('mdh methods')->with('mdh resources');

test('an array iso2 or iso3 is a 422 on the country create and update, not a 500', function (string $method, string $field) {
    $s = mdhScope();
    [$body] = mdhBody('countries', $s);
    $before = mdhCount('countries');

    foreach ([['NL'], [['NL']], ['a' => 'b']] as $hostile) {
        mdhSend($method, 'countries', $s, array_merge($body, [$field => $hostile]))->assertStatus(422)->assertJsonValidationErrors([$field]);
    }

    expect(mdhCount('countries'))->toBe($before)
        ->and($s['country']->refresh()->iso2)->toBe('PL');
})->with('mdh methods')->with(['iso2', 'iso3']);

test('an array iso2 is a 422 on the state create and update, not a 500', function (string $method) {
    $s = mdhScope();
    [$body] = mdhBody('states', $s);
    $before = mdhCount('states');

    foreach ([['NS'], [['NS']], ['a' => 'b']] as $hostile) {
        mdhSend($method, 'states', $s, array_merge($body, ['iso2' => $hostile]))->assertStatus(422)->assertJsonValidationErrors(['iso2']);
    }

    expect(mdhCount('states'))->toBe($before)
        ->and($s['state']->refresh()->iso2)->toBe('PS');
})->with('mdh methods');

test('country and state codes are still upper-cased and blank codes still mean none', function () {
    $s = mdhScope();

    $this->postJson('/api/countries', ['name' => 'Lowland', 'iso2' => 'lw', 'iso3' => 'lwl'])->assertSuccessful();
    $this->postJson('/api/states', ['name' => 'Low State', 'country_id' => $s['country']->id, 'iso2' => ' ls '])->assertSuccessful();
    $this->postJson('/api/states', ['name' => 'No Code State', 'country_id' => $s['country']->id, 'iso2' => ''])->assertSuccessful();

    expect(Country::query()->where('name', 'Lowland')->first())->iso2->toBe('LW')->iso3->toBe('LWL')
        ->and(State::query()->where('name', 'Low State')->value('iso2'))->toBe('LS')
        ->and(State::query()->where('name', 'No Code State')->value('iso2'))->toBeNull();
});

test('a role or department name that is too long or not text is a 422', function (string $method, string $resource) {
    $s = mdhScope();
    [$body] = mdhBody($resource, $s);
    $before = mdhCount($resource);

    foreach ([str_repeat('a', 256), 12345, true] as $hostile) {
        mdhSend($method, $resource, $s, array_merge($body, ['name' => $hostile]))->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    // 255 characters is the column size and is fine
    mdhSend($method, $resource, $s, array_merge($body, ['name' => str_repeat('a', 255)]))->assertSuccessful();
    expect(mdhCount($resource))->toBe($method === 'POST' ? $before + 1 : $before);
})->with('mdh methods')->with(['roles', 'departments']);

test('a company or branch id that does not exist or is not a number is a 422, not a foreign key 500', function (string $method, string $resource, string $field, mixed $hostile) {
    $s = mdhScope();
    [$body] = mdhBody($resource, $s);
    $before = mdhCount($resource);

    mdhSend($method, $resource, $s, array_merge($body, [$field => $hostile]))->assertStatus(422)->assertJsonValidationErrors([$field]);

    expect(mdhCount($resource))->toBe($before);
})->with('mdh methods')->with(['roles', 'departments'])->with(['company_id', 'branch_id'])->with([
    'unknown id' => [999999],
    'text' => ['abc'],
    'negative text' => ['-5x'],
    'float' => [1.5],
    'long text' => [str_repeat('9', 40)],
]);

test('real company and branch ids, empty ones and the string undefined keep working', function (string $resource) {
    $s = mdhScope();
    [$body] = mdhBody($resource, $s);
    $table = $resource;

    mdhSend('POST', $resource, $s, array_merge($body, ['name' => 'With ids']))->assertSuccessful();
    mdhSend('POST', $resource, $s, array_merge($body, ['name' => 'Undefined ids', 'company_id' => 'undefined', 'branch_id' => 'undefined']))->assertSuccessful();
    mdhSend('POST', $resource, $s, array_merge($body, ['name' => 'Empty ids', 'company_id' => '', 'branch_id' => null]))->assertSuccessful();

    expect(DB::table($table)->where('name', 'With ids')->value('company_id'))->toBe($s['company'])
        ->and(DB::table($table)->where('name', 'Undefined ids')->first())->company_id->toBeNull()->branch_id->toBeNull()
        ->and(DB::table($table)->where('name', 'Empty ids')->first())->company_id->toBeNull()->branch_id->toBeNull();
})->with(['roles', 'departments']);

test('the city lookup answers 422 to an array state id and still works with a real one', function () {
    $s = mdhScope();

    foreach ([['1'], [['1']], ['a' => 'b']] as $hostile) {
        $this->postJson('/api/fetchcities', ['country_id' => $s['country']->id, 'state_id' => $hostile])->assertStatus(422)->assertJsonValidationErrors(['state_id']);
    }

    $this->postJson('/api/fetchcities', ['state_id' => 'abc'])->assertStatus(422);

    $this->postJson('/api/fetchcities', ['country_id' => $s['country']->id, 'state_id' => $s['state']->id])
        ->assertSuccessful()
        ->assertJsonCount(1)
        ->assertJsonPath('0.text', 'Probe City');

    $this->postJson('/api/fetchcities', [])->assertSuccessful()->assertExactJson([]);
    $this->postJson('/api/fetchcities', ['state_id' => 999999])->assertSuccessful()->assertExactJson([]);
});
