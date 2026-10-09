<?php

use App\Models\PipelineStage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function psCompany(string $code = 'PSTG001'): int
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
function psStaff(int $companyId, array $paths = []): User
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
function psPayload(int $companyId, array $overrides = []): array
{
    return array_merge([
        'company_id' => $companyId,
        'name' => 'Proposal Sent',
        'sort_order' => 3,
        'color' => '#199683',
        'is_won' => false,
        'is_lost' => false,
        'is_active' => true,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function psMake(int $companyId, array $attributes = []): PipelineStage
{
    return PipelineStage::query()->create(array_merge([
        'company_id' => $companyId,
        'name' => 'Existing Stage',
        'sort_order' => 1,
        'is_active' => true,
    ], $attributes));
}

test('pipeline stages api creates a stage with the given fields', function () {
    $companyId = psCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/pipeline-stages', psPayload($companyId))->assertSuccessful();

    $stage = PipelineStage::query()->where('company_id', $companyId)->firstOrFail();

    expect($stage->name)->toBe('Proposal Sent')
        ->and($stage->sort_order)->toBe(3)
        ->and($stage->color)->toBe('#199683')
        ->and($stage->is_won)->toBeFalse()
        ->and($stage->is_lost)->toBeFalse();
});

test('pipeline stages api auto-assigns the next sort_order when none is given', function () {
    $companyId = psCompany();
    psMake($companyId, ['sort_order' => 5]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/pipeline-stages', psPayload($companyId, ['sort_order' => null]))->assertSuccessful();

    $stage = PipelineStage::query()->where('name', 'Proposal Sent')->firstOrFail();

    expect($stage->sort_order)->toBe(6);
});

test('pipeline stages api validates the fields', function (array $overrides, string $field) {
    $companyId = psCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/pipeline-stages', psPayload($companyId, $overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(PipelineStage::query()->count())->toBe(0);
})->with([
    'no name' => [['name' => ''], 'name'],
    'name too short' => [['name' => 'a'], 'name'],
    'negative sort order' => [['sort_order' => -1], 'sort_order'],
    'is_won not a boolean' => [['is_won' => 'maybe'], 'is_won'],
    'unknown company' => [['company_id' => 999999], 'company_id'],
    'no company for the superadmin' => [['company_id' => null], 'company_id'],
]);

test('pipeline stages index lists stages ordered by sort_order', function () {
    $companyId = psCompany();
    psMake($companyId, ['name' => 'Negotiation', 'sort_order' => 2]);
    psMake($companyId, ['name' => 'New', 'sort_order' => 1]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->getJson('/api/pipeline-stages')->assertSuccessful();

    expect(collect($response->json('data.data'))->pluck('name')->all())->toBe(['New', 'Negotiation'])
        ->and($response->json('trash_count'))->toBe(0);
});

test('pipeline stages show returns one stage with its company', function () {
    $companyId = psCompany();
    $stage = psMake($companyId);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->getJson('/api/pipeline-stages/'.$stage->id)
        ->assertSuccessful()
        ->assertJsonPath('company.name', 'Company PSTG001');

    $this->getJson('/api/pipeline-stages/999999')->assertNotFound();
});

test('pipeline stages api updates a stage', function () {
    $companyId = psCompany();
    $stage = psMake($companyId, ['is_won' => false]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->putJson('/api/pipeline-stages/'.$stage->id, psPayload($companyId, [
        'name' => 'Won',
        'is_won' => true,
    ]))->assertSuccessful();

    $stage->refresh();

    expect($stage->name)->toBe('Won')
        ->and($stage->is_won)->toBeTrue();
});

test('pipeline stages api soft deletes, lists the trash, restores and permanently deletes', function () {
    $companyId = psCompany();
    $stage = psMake($companyId, ['name' => 'Trash Me Stage']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->deleteJson('/api/pipeline-stages/'.$stage->id)->assertSuccessful();

    expect(PipelineStage::query()->find($stage->id))->toBeNull()
        ->and(PipelineStage::onlyTrashed()->find($stage->id))->not->toBeNull();

    $this->getJson('/api/pipeline-stages/trash')
        ->assertSuccessful()
        ->assertJsonPath('data.data.0.name', 'Trash Me Stage');
    $this->getJson('/api/pipeline-stages')->assertJsonPath('trash_count', 1);

    $this->postJson('/api/pipeline-stages/restore_records', [$stage->id])->assertSuccessful();
    expect(PipelineStage::query()->find($stage->id))->not->toBeNull();

    $this->postJson('/api/pipeline-stages/bulk_delete', [$stage->id])->assertSuccessful();
    $this->postJson('/api/pipeline-stages/bulk_delete_per', [$stage->id])->assertSuccessful();

    expect(PipelineStage::withTrashed()->find($stage->id))->toBeNull();
});

test('pipeline stages fetch returns only the active stages, ordered and named for the board', function () {
    $companyId = psCompany();
    psMake($companyId, ['name' => 'Second', 'sort_order' => 2]);
    psMake($companyId, ['name' => 'First', 'sort_order' => 1]);
    psMake($companyId, ['name' => 'Retired', 'sort_order' => 0, 'is_active' => false]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $rows = $this->getJson('/api/fetchpipelinestages?company_id='.$companyId)->assertSuccessful()->json();

    expect(collect($rows)->pluck('text')->all())->toBe(['First', 'Second']);
});

test('pipeline stages write actions are forbidden without the menu permission', function () {
    $companyId = psCompany();
    $stage = psMake($companyId);
    Sanctum::actingAs(psStaff($companyId));

    $this->postJson('/api/pipeline-stages', psPayload($companyId))->assertForbidden();
    $this->putJson('/api/pipeline-stages/'.$stage->id, psPayload($companyId, ['name' => 'Hijacked']))->assertForbidden();

    $this->deleteJson('/api/pipeline-stages/'.$stage->id)->assertSuccessful()->assertContent('"406"');

    expect(PipelineStage::query()->count())->toBe(1)
        ->and($stage->refresh()->name)->toBe('Existing Stage');
});

test('a company user always saves under their own company, whatever company the request names', function () {
    $ownCompany = psCompany('PSTG001');
    $otherCompany = psCompany('PSTG002');
    Sanctum::actingAs(psStaff($ownCompany, ['/pipelinestages/add']));

    $this->postJson('/api/pipeline-stages', psPayload($otherCompany))->assertSuccessful();

    expect(PipelineStage::query()->firstOrFail()->company_id)->toBe($ownCompany);
});

test('a company user cannot see or change the stages of another company', function () {
    $ownCompany = psCompany('PSTG001');
    $otherCompany = psCompany('PSTG002');
    $mine = psMake($ownCompany, ['name' => 'Mine Stage']);
    $theirs = psMake($otherCompany, ['name' => 'Theirs Stage']);
    Sanctum::actingAs(psStaff($ownCompany, ['/pipelinestages']));

    $listed = collect($this->getJson('/api/pipeline-stages')->assertSuccessful()->json('data.data'))->pluck('name')->all();

    expect($listed)->toBe(['Mine Stage']);

    $this->getJson('/api/pipeline-stages/'.$theirs->id)->assertNotFound();

    expect($theirs->refresh()->name)->toBe('Theirs Stage')
        ->and($mine->refresh()->name)->toBe('Mine Stage');
});

test('pipeline stages api requires authentication', function () {
    $this->getJson('/api/pipeline-stages')->assertUnauthorized();
    $this->postJson('/api/pipeline-stages', ['name' => 'Nobody Stage'])->assertUnauthorized();
});
