<?php

use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\PipelineStage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function oppCompany(string $code = 'OPP001'): int
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
function oppStaff(int $companyId, array $paths = []): User
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
function oppPayload(int $companyId, array $overrides = []): array
{
    return array_merge([
        'company_id' => $companyId,
        'name' => 'Raza Traders - Annual Supply Deal',
        'deal_value' => 150000,
        'expected_closing_date' => '2026-12-31',
        'notes' => 'High priority.',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function oppMake(int $companyId, array $attributes = []): Opportunity
{
    return Opportunity::query()->create(array_merge([
        'company_id' => $companyId,
        'name' => 'Existing Opportunity',
        'deal_value' => 1000,
        'status' => 'open',
    ], $attributes));
}

function oppStage(int $companyId, array $attributes = []): PipelineStage
{
    return PipelineStage::query()->create(array_merge([
        'company_id' => $companyId,
        'name' => 'New',
        'sort_order' => 1,
        'is_active' => true,
    ], $attributes));
}

test('opportunities api creates an opportunity with the given fields', function () {
    $companyId = oppCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/opportunities', oppPayload($companyId))->assertSuccessful();

    $opportunity = Opportunity::query()->where('company_id', $companyId)->firstOrFail();

    expect($opportunity->name)->toBe('Raza Traders - Annual Supply Deal')
        ->and((float) $opportunity->deal_value)->toBe(150000.0)
        ->and($opportunity->expected_closing_date->format('Y-m-d'))->toBe('2026-12-31')
        ->and($opportunity->status)->toBe('open');
});

test('opportunities api links an opportunity to a lead without requiring one', function () {
    $companyId = oppCompany();
    $lead = Lead::query()->create(['company_id' => $companyId, 'name' => 'Ahmed Raza', 'status' => 'qualified']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/opportunities', oppPayload($companyId, ['lead_id' => $lead->id]))->assertSuccessful();
    $linked = Opportunity::query()->firstOrFail();
    expect($linked->lead_id)->toBe($lead->id);

    $linked->delete();
    $this->postJson('/api/opportunities', oppPayload($companyId, ['lead_id' => null]))->assertSuccessful();
    expect(Opportunity::query()->whereNull('lead_id')->exists())->toBeTrue();
});

test('opportunities api sets status to won or lost from the pipeline stage on create', function () {
    $companyId = oppCompany();
    $wonStage = oppStage($companyId, ['name' => 'Won', 'is_won' => true]);
    $lostStage = oppStage($companyId, ['name' => 'Lost', 'is_lost' => true]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/opportunities', oppPayload($companyId, ['name' => 'Won Deal', 'pipeline_stage_id' => $wonStage->id]))->assertSuccessful();
    $this->postJson('/api/opportunities', oppPayload($companyId, ['name' => 'Lost Deal', 'pipeline_stage_id' => $lostStage->id]))->assertSuccessful();

    expect(Opportunity::query()->where('name', 'Won Deal')->firstOrFail()->status)->toBe('won')
        ->and(Opportunity::query()->where('name', 'Lost Deal')->firstOrFail()->status)->toBe('lost');
});

test('opportunities api validates the fields', function (array $overrides, string $field) {
    $companyId = oppCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/opportunities', oppPayload($companyId, $overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(Opportunity::query()->count())->toBe(0);
})->with([
    'no name' => [['name' => ''], 'name'],
    'name too short' => [['name' => 'ab'], 'name'],
    'negative deal value' => [['deal_value' => -5], 'deal_value'],
    'invalid closing date' => [['expected_closing_date' => 'not-a-date'], 'expected_closing_date'],
    'unknown lead' => [['lead_id' => 999999], 'lead_id'],
    'unknown contact' => [['contact_id' => 999999], 'contact_id'],
    'unknown stage' => [['pipeline_stage_id' => 999999], 'pipeline_stage_id'],
    'unknown assignee' => [['assigned_to' => 999999], 'assigned_to'],
    'unknown company' => [['company_id' => 999999], 'company_id'],
    'no company for the superadmin' => [['company_id' => null], 'company_id'],
]);

test('opportunities show returns one opportunity with its relations', function () {
    $companyId = oppCompany();
    $stage = oppStage($companyId);
    $opportunity = oppMake($companyId, ['pipeline_stage_id' => $stage->id]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->getJson('/api/opportunities/'.$opportunity->id)
        ->assertSuccessful()
        ->assertJsonPath('pipeline_stage.name', 'New');

    $this->getJson('/api/opportunities/999999')->assertNotFound();
});

test('opportunities api updates an opportunity and re-derives status from its new stage', function () {
    $companyId = oppCompany();
    $wonStage = oppStage($companyId, ['name' => 'Won', 'is_won' => true]);
    $opportunity = oppMake($companyId, ['status' => 'open']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->putJson('/api/opportunities/'.$opportunity->id, oppPayload($companyId, [
        'name' => 'Renamed Deal',
        'pipeline_stage_id' => $wonStage->id,
    ]))->assertSuccessful();

    $opportunity->refresh();

    expect($opportunity->name)->toBe('Renamed Deal')
        ->and($opportunity->status)->toBe('won');
});

test('opportunities api soft deletes, lists the trash, restores and permanently deletes', function () {
    $companyId = oppCompany();
    $opportunity = oppMake($companyId, ['name' => 'Trash Me Deal']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->deleteJson('/api/opportunities/'.$opportunity->id)->assertSuccessful();

    expect(Opportunity::query()->find($opportunity->id))->toBeNull()
        ->and(Opportunity::onlyTrashed()->find($opportunity->id))->not->toBeNull();

    $this->getJson('/api/opportunities/trash')
        ->assertSuccessful()
        ->assertJsonPath('data.data.0.name', 'Trash Me Deal');
    $this->getJson('/api/opportunities')->assertJsonPath('trash_count', 1);

    $this->postJson('/api/opportunities/restore_records', [$opportunity->id])->assertSuccessful();
    expect(Opportunity::query()->find($opportunity->id))->not->toBeNull();

    $this->postJson('/api/opportunities/bulk_delete', [$opportunity->id])->assertSuccessful();
    $this->postJson('/api/opportunities/bulk_delete_per', [$opportunity->id])->assertSuccessful();

    expect(Opportunity::withTrashed()->find($opportunity->id))->toBeNull();
});

test('the board endpoint returns active stages in order and every open opportunity', function () {
    $companyId = oppCompany();
    $stageB = oppStage($companyId, ['name' => 'Negotiation', 'sort_order' => 2]);
    $stageA = oppStage($companyId, ['name' => 'New', 'sort_order' => 1]);
    oppStage($companyId, ['name' => 'Retired', 'is_active' => false, 'sort_order' => 0]);
    oppMake($companyId, ['name' => 'Deal A', 'pipeline_stage_id' => $stageA->id]);
    oppMake($companyId, ['name' => 'Deal B', 'pipeline_stage_id' => $stageB->id]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->getJson('/api/opportunities/board')->assertSuccessful();

    expect(collect($response->json('stages'))->pluck('name')->all())->toBe(['New', 'Negotiation'])
        ->and(collect($response->json('opportunities'))->pluck('name')->sort()->values()->all())->toBe(['Deal A', 'Deal B']);
});

test('move-stage drags a card to a different column and follows its won/lost flag', function () {
    $companyId = oppCompany();
    $openStage = oppStage($companyId, ['name' => 'New']);
    $wonStage = oppStage($companyId, ['name' => 'Won', 'is_won' => true]);
    $opportunity = oppMake($companyId, ['pipeline_stage_id' => $openStage->id, 'status' => 'open']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->postJson("/api/opportunities/{$opportunity->id}/move-stage", [
        'pipeline_stage_id' => $wonStage->id,
    ])->assertSuccessful();

    expect($response->json('status'))->toBe('won');

    $opportunity->refresh();
    expect($opportunity->pipeline_stage_id)->toBe($wonStage->id)
        ->and($opportunity->status)->toBe('won');
});

test('move-stage requires a real pipeline stage and is forbidden without the menu permission', function () {
    $companyId = oppCompany();
    $stage = oppStage($companyId);
    $opportunity = oppMake($companyId, ['pipeline_stage_id' => $stage->id]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson("/api/opportunities/{$opportunity->id}/move-stage", ['pipeline_stage_id' => 999999])
        ->assertUnprocessable();

    Sanctum::actingAs(oppStaff($companyId));
    $this->postJson("/api/opportunities/{$opportunity->id}/move-stage", ['pipeline_stage_id' => $stage->id])
        ->assertForbidden();
});

test('opportunities write actions are forbidden without the menu permission', function () {
    $companyId = oppCompany();
    $opportunity = oppMake($companyId);
    Sanctum::actingAs(oppStaff($companyId));

    $this->postJson('/api/opportunities', oppPayload($companyId))->assertForbidden();
    $this->putJson('/api/opportunities/'.$opportunity->id, oppPayload($companyId, ['name' => 'Hijacked']))->assertForbidden();

    $this->deleteJson('/api/opportunities/'.$opportunity->id)->assertSuccessful()->assertContent('"406"');

    expect(Opportunity::query()->count())->toBe(1)
        ->and($opportunity->refresh()->name)->toBe('Existing Opportunity');
});

test('a company user always saves under their own company, whatever company the request names', function () {
    $ownCompany = oppCompany('OPP001');
    $otherCompany = oppCompany('OPP002');
    Sanctum::actingAs(oppStaff($ownCompany, ['/opportunities/add']));

    $this->postJson('/api/opportunities', oppPayload($otherCompany))->assertSuccessful();

    expect(Opportunity::query()->firstOrFail()->company_id)->toBe($ownCompany);
});

test('a company user cannot see or change the opportunities of another company', function () {
    $ownCompany = oppCompany('OPP001');
    $otherCompany = oppCompany('OPP002');
    $mine = oppMake($ownCompany, ['name' => 'Mine Deal']);
    $theirs = oppMake($otherCompany, ['name' => 'Theirs Deal']);
    Sanctum::actingAs(oppStaff($ownCompany, ['/opportunities']));

    $listed = collect($this->getJson('/api/opportunities')->assertSuccessful()->json('data.data'))->pluck('name')->all();

    expect($listed)->toBe(['Mine Deal']);

    $this->getJson('/api/opportunities/'.$theirs->id)->assertNotFound();

    expect($theirs->refresh()->name)->toBe('Theirs Deal')
        ->and($mine->refresh()->name)->toBe('Mine Deal');
});

test('opportunities api requires authentication', function () {
    $this->getJson('/api/opportunities')->assertUnauthorized();
    $this->postJson('/api/opportunities', ['name' => 'Nobody Deal'])->assertUnauthorized();
    $this->getJson('/api/opportunities/board')->assertUnauthorized();
});
