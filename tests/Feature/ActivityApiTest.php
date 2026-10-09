<?php

use App\Models\Activity;
use App\Models\Lead;
use App\Models\Opportunity;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function actCompany(string $code = 'ACT001'): int
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
function actStaff(int $companyId, array $paths = []): User
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
function actPayload(int $companyId, array $overrides = []): array
{
    return array_merge([
        'company_id' => $companyId,
        'type' => 'call',
        'subject' => 'Follow up on pricing',
        'description' => 'Call to discuss volume discount.',
        'due_at' => '2026-12-01 10:00:00',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function actMake(int $companyId, array $attributes = []): Activity
{
    return Activity::query()->create(array_merge([
        'company_id' => $companyId,
        'type' => 'task',
        'subject' => 'Existing Activity',
    ], $attributes));
}

test('activities api creates an activity with the given fields', function () {
    $companyId = actCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/activities', actPayload($companyId))->assertSuccessful();

    $activity = Activity::query()->where('company_id', $companyId)->firstOrFail();

    expect($activity->type)->toBe('call')
        ->and($activity->subject)->toBe('Follow up on pricing')
        ->and($activity->description)->toBe('Call to discuss volume discount.')
        ->and($activity->completed_at)->toBeNull()
        ->and($activity->created_by)->toBe(1);
});

test('activities api links an activity to a lead, an opportunity and a contact independently', function () {
    $companyId = actCompany();
    $lead = Lead::query()->create(['company_id' => $companyId, 'name' => 'Ahmed Raza', 'status' => 'new']);
    $opportunity = Opportunity::query()->create(['company_id' => $companyId, 'name' => 'Deal A', 'status' => 'open']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/activities', actPayload($companyId, [
        'lead_id' => $lead->id,
        'opportunity_id' => $opportunity->id,
    ]))->assertSuccessful();

    $activity = Activity::query()->firstOrFail();

    expect($activity->lead_id)->toBe($lead->id)
        ->and($activity->opportunity_id)->toBe($opportunity->id)
        ->and($activity->contact_id)->toBeNull();
});

test('activities api defaults to task type and rejects an unknown type', function () {
    $companyId = actCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/activities', actPayload($companyId, ['type' => null]))->assertSuccessful();
    expect(Activity::query()->firstOrFail()->type)->toBe('task');

    $this->postJson('/api/activities', actPayload($companyId, ['type' => 'bogus']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['type']);
});

test('activities api validates the fields', function (array $overrides, string $field) {
    $companyId = actCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/activities', actPayload($companyId, $overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(Activity::query()->count())->toBe(0);
})->with([
    'no subject' => [['subject' => ''], 'subject'],
    'subject too short' => [['subject' => 'a'], 'subject'],
    'invalid due date' => [['due_at' => 'not-a-date'], 'due_at'],
    'unknown lead' => [['lead_id' => 999999], 'lead_id'],
    'unknown opportunity' => [['opportunity_id' => 999999], 'opportunity_id'],
    'unknown contact' => [['contact_id' => 999999], 'contact_id'],
    'unknown assignee' => [['assigned_to' => 999999], 'assigned_to'],
    'unknown company' => [['company_id' => 999999], 'company_id'],
    'no company for the superadmin' => [['company_id' => null], 'company_id'],
]);

test('activities show returns one activity with its relations', function () {
    $companyId = actCompany();
    $lead = Lead::query()->create(['company_id' => $companyId, 'name' => 'Ahmed Raza', 'status' => 'new']);
    $activity = actMake($companyId, ['lead_id' => $lead->id]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->getJson('/api/activities/'.$activity->id)
        ->assertSuccessful()
        ->assertJsonPath('lead.name', 'Ahmed Raza');

    $this->getJson('/api/activities/999999')->assertNotFound();
});

test('activities api updates an activity', function () {
    $companyId = actCompany();
    $activity = actMake($companyId, ['subject' => 'Old Subject']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->putJson('/api/activities/'.$activity->id, actPayload($companyId, ['subject' => 'New Subject']))
        ->assertSuccessful();

    expect($activity->refresh()->subject)->toBe('New Subject');
});

test('activities api soft deletes, lists the trash, restores and permanently deletes', function () {
    $companyId = actCompany();
    $activity = actMake($companyId, ['subject' => 'Trash Me Activity']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->deleteJson('/api/activities/'.$activity->id)->assertSuccessful();

    expect(Activity::query()->find($activity->id))->toBeNull()
        ->and(Activity::onlyTrashed()->find($activity->id))->not->toBeNull();

    $this->getJson('/api/activities/trash')
        ->assertSuccessful()
        ->assertJsonPath('data.data.0.subject', 'Trash Me Activity');
    $this->getJson('/api/activities')->assertJsonPath('trash_count', 1);

    $this->postJson('/api/activities/restore_records', [$activity->id])->assertSuccessful();
    expect(Activity::query()->find($activity->id))->not->toBeNull();

    $this->postJson('/api/activities/bulk_delete', [$activity->id])->assertSuccessful();
    $this->postJson('/api/activities/bulk_delete_per', [$activity->id])->assertSuccessful();

    expect(Activity::withTrashed()->find($activity->id))->toBeNull();
});

test('complete toggles an activity done and reopened', function () {
    $companyId = actCompany();
    $activity = actMake($companyId);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->postJson("/api/activities/{$activity->id}/complete")->assertSuccessful();
    expect($response->json('completed_at'))->not->toBeNull();
    expect($activity->refresh()->completed_at)->not->toBeNull();

    $this->postJson("/api/activities/{$activity->id}/complete")->assertSuccessful();
    expect($activity->refresh()->completed_at)->toBeNull();
});

test('complete is forbidden without the menu permission', function () {
    $companyId = actCompany();
    $activity = actMake($companyId);
    Sanctum::actingAs(actStaff($companyId));

    $this->postJson("/api/activities/{$activity->id}/complete")->assertForbidden();
    expect($activity->refresh()->completed_at)->toBeNull();
});

test('the timeline endpoint filters by lead, opportunity or contact and orders newest first', function () {
    $companyId = actCompany();
    $lead = Lead::query()->create(['company_id' => $companyId, 'name' => 'Ahmed Raza', 'status' => 'new']);
    $other = Lead::query()->create(['company_id' => $companyId, 'name' => 'Other Lead', 'status' => 'new']);
    actMake($companyId, ['lead_id' => $lead->id, 'subject' => 'First']);
    actMake($companyId, ['lead_id' => $lead->id, 'subject' => 'Second']);
    actMake($companyId, ['lead_id' => $other->id, 'subject' => 'Unrelated']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->getJson('/api/activities/timeline?lead_id='.$lead->id)->assertSuccessful();

    expect(collect($response->json('data'))->pluck('subject')->all())->toBe(['Second', 'First']);
});

test('activities write actions are forbidden without the menu permission', function () {
    $companyId = actCompany();
    $activity = actMake($companyId);
    Sanctum::actingAs(actStaff($companyId));

    $this->postJson('/api/activities', actPayload($companyId))->assertForbidden();
    $this->putJson('/api/activities/'.$activity->id, actPayload($companyId, ['subject' => 'Hijacked']))->assertForbidden();

    $this->deleteJson('/api/activities/'.$activity->id)->assertSuccessful()->assertContent('"406"');

    expect(Activity::query()->count())->toBe(1)
        ->and($activity->refresh()->subject)->toBe('Existing Activity');
});

test('a company user always saves under their own company, whatever company the request names', function () {
    $ownCompany = actCompany('ACT001');
    $otherCompany = actCompany('ACT002');
    Sanctum::actingAs(actStaff($ownCompany, ['/activities/add']));

    $this->postJson('/api/activities', actPayload($otherCompany))->assertSuccessful();

    expect(Activity::query()->firstOrFail()->company_id)->toBe($ownCompany);
});

test('a company user cannot see or change the activities of another company', function () {
    $ownCompany = actCompany('ACT001');
    $otherCompany = actCompany('ACT002');
    $mine = actMake($ownCompany, ['subject' => 'Mine Activity']);
    $theirs = actMake($otherCompany, ['subject' => 'Theirs Activity']);
    Sanctum::actingAs(actStaff($ownCompany, ['/activities']));

    $listed = collect($this->getJson('/api/activities')->assertSuccessful()->json('data.data'))->pluck('subject')->all();

    expect($listed)->toBe(['Mine Activity']);

    $this->getJson('/api/activities/'.$theirs->id)->assertNotFound();

    expect($theirs->refresh()->subject)->toBe('Theirs Activity')
        ->and($mine->refresh()->subject)->toBe('Mine Activity');
});

test('activities api requires authentication', function () {
    $this->getJson('/api/activities')->assertUnauthorized();
    $this->postJson('/api/activities', ['subject' => 'Nobody Activity'])->assertUnauthorized();
    $this->getJson('/api/activities/timeline')->assertUnauthorized();
});
