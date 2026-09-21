<?php

use App\Models\CommissionAgent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function caCompany(string $code = 'CAG001'): int
{
    return DB::table('companies')->insertGetId([
        'code' => $code,
        'name' => 'Company '.$code,
        'address' => '1 Test Street',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  list<string>  $paths  the menu permissions the user's role is given
 */
function caStaff(int $companyId, array $paths = []): User
{
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => $companyId, 'is_active' => true]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path);
    }

    return createStaffUserForRole($role, ['company_id' => $companyId]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function caPayload(int $companyId, array $overrides = []): array
{
    return array_merge([
        'company_id' => $companyId,
        'name' => 'Imran Khan',
        'phone' => '0300-1234567',
        'commission_percent' => 2.5,
        'is_active' => true,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function caMake(int $companyId, array $attributes = []): CommissionAgent
{
    return CommissionAgent::query()->create(array_merge([
        'company_id' => $companyId,
        'name' => 'Existing Agent',
        'commission_percent' => 1,
        'is_active' => true,
    ], $attributes));
}

test('commission agents api creates an agent with the given fields', function () {
    $companyId = caCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/commission-agents', caPayload($companyId))->assertSuccessful();

    $agent = CommissionAgent::query()->where('company_id', $companyId)->firstOrFail();

    expect($agent->name)->toBe('Imran Khan')
        ->and($agent->phone)->toBe('0300-1234567')
        ->and((float) $agent->commission_percent)->toBe(2.5)
        ->and($agent->is_active)->toBeTrue();
});

test('commission agents api trims the name and stores a blank phone as null', function () {
    $companyId = caCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/commission-agents', caPayload($companyId, ['name' => '  Zaid Ali  ', 'phone' => '  ']))->assertSuccessful();

    $agent = CommissionAgent::query()->firstOrFail();

    expect($agent->name)->toBe('Zaid Ali')
        ->and($agent->phone)->toBeNull();
});

test('commission agents api accepts the rate at both ends of 0 to 100', function (float|int $percent) {
    $companyId = caCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/commission-agents', caPayload($companyId, ['commission_percent' => $percent]))->assertSuccessful();

    expect((float) CommissionAgent::query()->firstOrFail()->commission_percent)->toBe((float) $percent);
})->with([0, 0.01, 12.75, 100]);

test('commission agents api validates the fields', function (array $overrides, string $field) {
    $companyId = caCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/commission-agents', caPayload($companyId, $overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(CommissionAgent::query()->count())->toBe(0);
})->with([
    'no name' => [['name' => ''], 'name'],
    'name too short' => [['name' => 'ab'], 'name'],
    'name too long' => [['name' => str_repeat('a', 201)], 'name'],
    'phone with letters' => [['phone' => '03OO-abc'], 'phone'],
    'phone too long' => [['phone' => str_repeat('1', 31)], 'phone'],
    'no rate' => [['commission_percent' => null], 'commission_percent'],
    'rate not a number' => [['commission_percent' => 'ten'], 'commission_percent'],
    'negative rate' => [['commission_percent' => -0.5], 'commission_percent'],
    'rate above 100' => [['commission_percent' => 100.01], 'commission_percent'],
    'status not a boolean' => [['is_active' => 'maybe'], 'is_active'],
    'unknown company' => [['company_id' => 999999], 'company_id'],
    'no company for the superadmin' => [['company_id' => null], 'company_id'],
]);

test('commission agents index lists agents with company names and rates', function () {
    $companyId = caCompany();
    caMake($companyId, ['name' => 'Imran Khan', 'commission_percent' => 2.5]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->getJson('/api/commission-agents')->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.company_name'))->toBe('Company CAG001')
        ->and($response->json('data.data.0.name'))->toBe('Imran Khan')
        ->and((float) $response->json('data.data.0.commission_percent'))->toBe(2.5)
        ->and($response->json('trash_count'))->toBe(0);
});

test('commission agents index searches the name and phone and filters by status', function () {
    $companyId = caCompany();
    caMake($companyId, ['name' => 'Imran Khan', 'phone' => '0300-1111111']);
    caMake($companyId, ['name' => 'Zaid Ali', 'phone' => '0321-2222222', 'is_active' => false]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $names = fn (array $query) => collect($this->getJson('/api/commission-agents?'.http_build_query($query))->assertSuccessful()->json('data.data'))->pluck('name')->all();

    expect($names(['search' => 'Zaid']))->toBe(['Zaid Ali'])
        ->and($names(['search' => '0300']))->toBe(['Imran Khan'])
        ->and($names(['status' => '0']))->toBe(['Zaid Ali'])
        ->and($names(['status' => '1']))->toBe(['Imran Khan'])
        ->and($names(['status' => 'all']))->toHaveCount(2);
});

test('commission agents index sorts by an allowed column and ignores any other sort column', function () {
    $companyId = caCompany();
    caMake($companyId, ['name' => 'Charlie Agent', 'commission_percent' => 3]);
    caMake($companyId, ['name' => 'Alpha Agent', 'commission_percent' => 1]);
    caMake($companyId, ['name' => 'Bravo Agent', 'commission_percent' => 2]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $byName = $this->getJson('/api/commission-agents?sort_by=name&sort_type=asc')->assertSuccessful();

    expect(collect($byName->json('data.data'))->pluck('name')->all())->toBe(['Alpha Agent', 'Bravo Agent', 'Charlie Agent']);

    $byRate = $this->getJson('/api/commission-agents?sort_by=commission_percent&sort_type=desc')->assertSuccessful();

    expect(collect($byRate->json('data.data'))->pluck('name')->all())->toBe(['Charlie Agent', 'Bravo Agent', 'Alpha Agent']);

    $this->getJson('/api/commission-agents?sort_by='.urlencode('id; drop table commission_agents').'&sort_type=sideways')
        ->assertSuccessful();
    $this->getJson('/api/commission-agents?sort_by=password')->assertSuccessful();

    expect(CommissionAgent::query()->count())->toBe(3);
});

test('commission agents show returns one agent with its company', function () {
    $companyId = caCompany();
    $agent = caMake($companyId, ['commission_percent' => 4]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->getJson('/api/commission-agents/'.$agent->id)
        ->assertSuccessful()
        ->assertJsonPath('company.name', 'Company CAG001');

    expect((float) $response->json('commission_percent'))->toBe(4.0);

    $this->getJson('/api/commission-agents/999999')->assertNotFound();
});

test('commission agents api updates an agent', function () {
    $companyId = caCompany();
    $agent = caMake($companyId, ['phone' => '0300-0000000', 'commission_percent' => 1]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->putJson('/api/commission-agents/'.$agent->id, caPayload($companyId, [
        'name' => 'Renamed Agent',
        'phone' => '',
        'commission_percent' => 7.25,
        'is_active' => false,
    ]))->assertSuccessful();

    $agent->refresh();

    expect($agent->name)->toBe('Renamed Agent')
        ->and($agent->phone)->toBeNull()
        ->and((float) $agent->commission_percent)->toBe(7.25)
        ->and($agent->is_active)->toBeFalse();
});

test('commission agents api soft deletes, lists the trash, restores and permanently deletes', function () {
    $companyId = caCompany();
    $agent = caMake($companyId, ['name' => 'Trash Me Agent']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->deleteJson('/api/commission-agents/'.$agent->id)->assertSuccessful();

    expect(CommissionAgent::query()->find($agent->id))->toBeNull()
        ->and(CommissionAgent::onlyTrashed()->find($agent->id))->not->toBeNull();

    $this->getJson('/api/commission-agents/trash')
        ->assertSuccessful()
        ->assertJsonPath('data.data.0.name', 'Trash Me Agent');
    $this->getJson('/api/commission-agents')->assertJsonPath('trash_count', 1);

    $this->postJson('/api/commission-agents/restore_records', [$agent->id])->assertSuccessful();
    expect(CommissionAgent::query()->find($agent->id))->not->toBeNull();

    $this->postJson('/api/commission-agents/bulk_delete', [$agent->id])->assertSuccessful();
    $this->postJson('/api/commission-agents/bulk_delete_per', [$agent->id])->assertSuccessful();

    expect(CommissionAgent::withTrashed()->find($agent->id))->toBeNull();
});

test('commission agents api toggles the status', function () {
    $agent = caMake(caCompany());
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/commission-agents/statusupdate', ['ids' => [$agent->id], 'status' => 0])->assertSuccessful();
    expect($agent->refresh()->is_active)->toBeFalse();

    $this->postJson('/api/commission-agents/statusupdate', ['ids' => [$agent->id]])->assertSuccessful();
    expect($agent->refresh()->is_active)->toBeTrue();
});

test('commission agents fetch returns only the active agents, named for a dropdown', function () {
    $companyId = caCompany();
    caMake($companyId, ['name' => 'Active Agent']);
    caMake($companyId, ['name' => 'Retired Agent', 'is_active' => false]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $rows = $this->getJson('/api/fetchcommissionagents?company_id='.$companyId)->assertSuccessful()->json();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['text'])->toBe('Active Agent');
});

test('commission agents write actions are forbidden without the menu permission', function () {
    $companyId = caCompany();
    $agent = caMake($companyId);
    Sanctum::actingAs(caStaff($companyId));

    $this->postJson('/api/commission-agents', caPayload($companyId))->assertForbidden();
    $this->putJson('/api/commission-agents/'.$agent->id, caPayload($companyId, ['name' => 'Hijacked']))->assertForbidden();
    $this->postJson('/api/commission-agents/statusupdate', ['ids' => [$agent->id], 'status' => 0])->assertForbidden();

    // delete and restore answer "406" in place of doing anything
    $this->deleteJson('/api/commission-agents/'.$agent->id)->assertSuccessful()->assertContent('"406"');
    $this->postJson('/api/commission-agents/bulk_delete', [$agent->id])->assertSuccessful()->assertContent('"406"');

    $agent->refresh();

    expect(CommissionAgent::query()->count())->toBe(1)
        ->and($agent->name)->toBe('Existing Agent')
        ->and($agent->is_active)->toBeTrue()
        ->and($agent->trashed())->toBeFalse();
});

test('commission agents restore and permanent delete are forbidden without their permissions', function () {
    $companyId = caCompany();
    $agent = caMake($companyId);
    $agent->delete();
    Sanctum::actingAs(caStaff($companyId));

    $this->postJson('/api/commission-agents/restore_records', [$agent->id])->assertContent('"406"');
    $this->postJson('/api/commission-agents/bulk_delete_per', [$agent->id])->assertContent('"406"');

    expect(CommissionAgent::onlyTrashed()->find($agent->id))->not->toBeNull();
});

test('a user with the menu permissions can add, edit, delete and restore in their own company', function () {
    $companyId = caCompany();
    Sanctum::actingAs(caStaff($companyId, [
        '/commissionagent/add', '/commissionagent/:id/edit', '/commissionagent/delete', '/commissionagent/restore',
    ]));

    $this->postJson('/api/commission-agents', caPayload($companyId, ['company_id' => null]))->assertSuccessful();

    $agent = CommissionAgent::query()->firstOrFail();

    $this->putJson('/api/commission-agents/'.$agent->id, caPayload($companyId, ['name' => 'Edited Agent']))->assertSuccessful();
    expect($agent->refresh()->name)->toBe('Edited Agent');

    $this->deleteJson('/api/commission-agents/'.$agent->id)->assertSuccessful()->assertJson(['message' => 'Successfully Deleted']);
    expect(CommissionAgent::query()->count())->toBe(0);

    $this->postJson('/api/commission-agents/restore_records', [$agent->id])->assertSuccessful()->assertJson(['message' => 'Successfully Restored']);
    expect(CommissionAgent::query()->count())->toBe(1);
});

test('a company user always saves under their own company, whatever company the request names', function () {
    $ownCompany = caCompany('CAG001');
    $otherCompany = caCompany('CAG002');
    Sanctum::actingAs(caStaff($ownCompany, ['/commissionagent/add', '/commissionagent/:id/edit']));

    $this->postJson('/api/commission-agents', caPayload($otherCompany))->assertSuccessful();

    $agent = CommissionAgent::query()->firstOrFail();

    expect($agent->company_id)->toBe($ownCompany);

    $this->putJson('/api/commission-agents/'.$agent->id, caPayload($otherCompany, ['name' => 'Still Mine']))->assertSuccessful();

    expect($agent->refresh()->company_id)->toBe($ownCompany)
        ->and($agent->name)->toBe('Still Mine');
});

test('a company user cannot see or change the agents of another company', function () {
    $ownCompany = caCompany('CAG001');
    $otherCompany = caCompany('CAG002');
    $mine = caMake($ownCompany, ['name' => 'Mine Agent']);
    $theirs = caMake($otherCompany, ['name' => 'Theirs Agent']);
    Sanctum::actingAs(caStaff($ownCompany, ['/commissionagent/:id/edit', '/commissionagent/delete']));

    $listed = collect($this->getJson('/api/commission-agents')->assertSuccessful()->json('data.data'))->pluck('name')->all();

    expect($listed)->toBe(['Mine Agent']);

    $this->getJson('/api/commission-agents/'.$theirs->id)->assertNotFound();
    $this->getJson('/api/commission-agents?company_id='.$otherCompany)->assertJsonCount(0, 'data.data');
    $this->getJson('/api/fetchcommissionagents?company_id='.$otherCompany)->assertSuccessful()->assertJsonCount(0);

    $this->putJson('/api/commission-agents/'.$theirs->id, caPayload($ownCompany, ['name' => 'Taken']))->assertNotFound();
    $this->postJson('/api/commission-agents/statusupdate', ['ids' => [$theirs->id], 'status' => 0]);
    $this->postJson('/api/commission-agents/bulk_delete', [$theirs->id, $mine->id])->assertSuccessful();

    expect($theirs->refresh()->name)->toBe('Theirs Agent')
        ->and($theirs->is_active)->toBeTrue()
        ->and($theirs->trashed())->toBeFalse()
        ->and($mine->refresh()->trashed())->toBeTrue();
});

test('commission agents api requires authentication', function () {
    $this->getJson('/api/commission-agents')->assertUnauthorized();
    $this->postJson('/api/commission-agents', ['name' => 'Nobody Agent'])->assertUnauthorized();
});
