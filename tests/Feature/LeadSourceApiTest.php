<?php

use App\Models\LeadSource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function lsCompany(string $code = 'LSRC001'): int
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
function lsStaff(int $companyId, array $paths = []): User
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
function lsPayload(int $companyId, array $overrides = []): array
{
    return array_merge([
        'company_id' => $companyId,
        'name' => 'Website',
        'is_active' => true,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function lsMake(int $companyId, array $attributes = []): LeadSource
{
    return LeadSource::query()->create(array_merge([
        'company_id' => $companyId,
        'name' => 'Existing Source',
        'is_active' => true,
    ], $attributes));
}

test('lead sources api creates a source with the given fields', function () {
    $companyId = lsCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/lead-sources', lsPayload($companyId))->assertSuccessful();

    $source = LeadSource::query()->where('company_id', $companyId)->firstOrFail();

    expect($source->name)->toBe('Website')
        ->and($source->is_active)->toBeTrue();
});

test('lead sources api trims the name', function () {
    $companyId = lsCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/lead-sources', lsPayload($companyId, ['name' => '  Referral  ']))->assertSuccessful();

    expect(LeadSource::query()->firstOrFail()->name)->toBe('Referral');
});

test('lead sources api validates the fields', function (array $overrides, string $field) {
    $companyId = lsCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/lead-sources', lsPayload($companyId, $overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(LeadSource::query()->count())->toBe(0);
})->with([
    'no name' => [['name' => ''], 'name'],
    'name too short' => [['name' => 'a'], 'name'],
    'name too long' => [['name' => str_repeat('a', 101)], 'name'],
    'status not a boolean' => [['is_active' => 'maybe'], 'is_active'],
    'unknown company' => [['company_id' => 999999], 'company_id'],
    'no company for the superadmin' => [['company_id' => null], 'company_id'],
]);

test('lead sources index lists sources with company names and trash count', function () {
    $companyId = lsCompany();
    lsMake($companyId, ['name' => 'Website']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->getJson('/api/lead-sources')->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.company_name'))->toBe('Company LSRC001')
        ->and($response->json('data.data.0.name'))->toBe('Website')
        ->and($response->json('trash_count'))->toBe(0);
});

test('lead sources index searches the name and filters by status', function () {
    $companyId = lsCompany();
    lsMake($companyId, ['name' => 'Website']);
    lsMake($companyId, ['name' => 'Referral', 'is_active' => false]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $names = fn (array $query) => collect($this->getJson('/api/lead-sources?'.http_build_query($query))->assertSuccessful()->json('data.data'))->pluck('name')->all();

    expect($names(['search' => 'Refer']))->toBe(['Referral'])
        ->and($names(['status' => '0']))->toBe(['Referral'])
        ->and($names(['status' => '1']))->toBe(['Website'])
        ->and($names(['status' => 'all']))->toHaveCount(2);
});

test('lead sources show returns one source with its company', function () {
    $companyId = lsCompany();
    $source = lsMake($companyId);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->getJson('/api/lead-sources/'.$source->id)
        ->assertSuccessful()
        ->assertJsonPath('company.name', 'Company LSRC001');

    $this->getJson('/api/lead-sources/999999')->assertNotFound();
});

test('lead sources api updates a source', function () {
    $companyId = lsCompany();
    $source = lsMake($companyId, ['is_active' => true]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->putJson('/api/lead-sources/'.$source->id, lsPayload($companyId, [
        'name' => 'Renamed Source',
        'is_active' => false,
    ]))->assertSuccessful();

    $source->refresh();

    expect($source->name)->toBe('Renamed Source')
        ->and($source->is_active)->toBeFalse();
});

test('lead sources api soft deletes, lists the trash, restores and permanently deletes', function () {
    $companyId = lsCompany();
    $source = lsMake($companyId, ['name' => 'Trash Me Source']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->deleteJson('/api/lead-sources/'.$source->id)->assertSuccessful();

    expect(LeadSource::query()->find($source->id))->toBeNull()
        ->and(LeadSource::onlyTrashed()->find($source->id))->not->toBeNull();

    $this->getJson('/api/lead-sources/trash')
        ->assertSuccessful()
        ->assertJsonPath('data.data.0.name', 'Trash Me Source');
    $this->getJson('/api/lead-sources')->assertJsonPath('trash_count', 1);

    $this->postJson('/api/lead-sources/restore_records', [$source->id])->assertSuccessful();
    expect(LeadSource::query()->find($source->id))->not->toBeNull();

    $this->postJson('/api/lead-sources/bulk_delete', [$source->id])->assertSuccessful();
    $this->postJson('/api/lead-sources/bulk_delete_per', [$source->id])->assertSuccessful();

    expect(LeadSource::withTrashed()->find($source->id))->toBeNull();
});

test('lead sources api toggles the status', function () {
    $source = lsMake(lsCompany());
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/lead-sources/statusupdate', ['ids' => [$source->id], 'status' => 0])->assertSuccessful();
    expect($source->refresh()->is_active)->toBeFalse();

    $this->postJson('/api/lead-sources/statusupdate', ['ids' => [$source->id]])->assertSuccessful();
    expect($source->refresh()->is_active)->toBeTrue();
});

test('lead sources fetch returns only the active sources, named for a dropdown', function () {
    $companyId = lsCompany();
    lsMake($companyId, ['name' => 'Active Source']);
    lsMake($companyId, ['name' => 'Retired Source', 'is_active' => false]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $rows = $this->getJson('/api/fetchleadsources?company_id='.$companyId)->assertSuccessful()->json();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['text'])->toBe('Active Source');
});

test('lead sources write actions are forbidden without the menu permission', function () {
    $companyId = lsCompany();
    $source = lsMake($companyId);
    Sanctum::actingAs(lsStaff($companyId));

    $this->postJson('/api/lead-sources', lsPayload($companyId))->assertForbidden();
    $this->putJson('/api/lead-sources/'.$source->id, lsPayload($companyId, ['name' => 'Hijacked']))->assertForbidden();
    $this->postJson('/api/lead-sources/statusupdate', ['ids' => [$source->id], 'status' => 0])->assertForbidden();

    $this->deleteJson('/api/lead-sources/'.$source->id)->assertSuccessful()->assertContent('"406"');
    $this->postJson('/api/lead-sources/bulk_delete', [$source->id])->assertSuccessful()->assertContent('"406"');

    $source->refresh();

    expect(LeadSource::query()->count())->toBe(1)
        ->and($source->name)->toBe('Existing Source')
        ->and($source->trashed())->toBeFalse();
});

test('a company user always saves under their own company, whatever company the request names', function () {
    $ownCompany = lsCompany('LSRC001');
    $otherCompany = lsCompany('LSRC002');
    Sanctum::actingAs(lsStaff($ownCompany, ['/leadsources/add', '/leadsources/:id/edit']));

    $this->postJson('/api/lead-sources', lsPayload($otherCompany))->assertSuccessful();

    $source = LeadSource::query()->firstOrFail();

    expect($source->company_id)->toBe($ownCompany);
});

test('a company user cannot see or change the sources of another company', function () {
    $ownCompany = lsCompany('LSRC001');
    $otherCompany = lsCompany('LSRC002');
    $mine = lsMake($ownCompany, ['name' => 'Mine Source']);
    $theirs = lsMake($otherCompany, ['name' => 'Theirs Source']);
    Sanctum::actingAs(lsStaff($ownCompany, ['/leadsources', '/leadsources/:id/edit', '/leadsources/delete']));

    $listed = collect($this->getJson('/api/lead-sources')->assertSuccessful()->json('data.data'))->pluck('name')->all();

    expect($listed)->toBe(['Mine Source']);

    $this->getJson('/api/lead-sources/'.$theirs->id)->assertNotFound();
    $this->putJson('/api/lead-sources/'.$theirs->id, lsPayload($ownCompany, ['name' => 'Taken']))->assertNotFound();

    expect($theirs->refresh()->name)->toBe('Theirs Source')
        ->and($mine->refresh()->name)->toBe('Mine Source');
});

test('lead sources api requires authentication', function () {
    $this->getJson('/api/lead-sources')->assertUnauthorized();
    $this->postJson('/api/lead-sources', ['name' => 'Nobody Source'])->assertUnauthorized();
});
