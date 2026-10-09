<?php

use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function leadCompany(string $code = 'LEAD001'): int
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
function leadStaff(int $companyId, array $paths = []): User
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
function leadPayload(int $companyId, array $overrides = []): array
{
    return array_merge([
        'company_id' => $companyId,
        'name' => 'Ahmed Raza',
        'company_name' => 'Raza Traders',
        'email' => 'ahmed@example.com',
        'phone' => '0300-1234567',
        'source' => 'Website',
        'status' => 'new',
        'notes' => 'Interested in bulk pricing.',
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function leadMake(int $companyId, array $attributes = []): Lead
{
    return Lead::query()->create(array_merge([
        'company_id' => $companyId,
        'name' => 'Existing Lead',
        'status' => 'new',
    ], $attributes));
}

test('leads api creates a lead with the given fields', function () {
    $companyId = leadCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/leads', leadPayload($companyId))->assertSuccessful();

    $lead = Lead::query()->where('company_id', $companyId)->firstOrFail();

    expect($lead->name)->toBe('Ahmed Raza')
        ->and($lead->company_name)->toBe('Raza Traders')
        ->and($lead->email)->toBe('ahmed@example.com')
        ->and($lead->phone)->toBe('0300-1234567')
        ->and($lead->source)->toBe('Website')
        ->and($lead->status)->toBe('new');
});

test('leads api trims the name and stores blank optional fields as null', function () {
    $companyId = leadCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/leads', leadPayload($companyId, [
        'name' => '  Zaid Ali  ',
        'company_name' => '',
        'phone' => '  ',
    ]))->assertSuccessful();

    $lead = Lead::query()->firstOrFail();

    expect($lead->name)->toBe('Zaid Ali')
        ->and($lead->company_name)->toBeNull()
        ->and($lead->phone)->toBeNull();
});

test('leads api defaults to the new status and rejects an unknown status', function () {
    $companyId = leadCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/leads', leadPayload($companyId, ['status' => null]))->assertSuccessful();
    expect(Lead::query()->firstOrFail()->status)->toBe('new');

    $this->postJson('/api/leads', leadPayload($companyId, ['status' => 'bogus']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

test('leads api validates the fields', function (array $overrides, string $field) {
    $companyId = leadCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/leads', leadPayload($companyId, $overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(Lead::query()->count())->toBe(0);
})->with([
    'no name' => [['name' => ''], 'name'],
    'name too short' => [['name' => 'ab'], 'name'],
    'name too long' => [['name' => str_repeat('a', 201)], 'name'],
    'invalid email' => [['email' => 'not-an-email'], 'email'],
    'phone with letters' => [['phone' => '03OO-abc'], 'phone'],
    'phone too long' => [['phone' => str_repeat('1', 31)], 'phone'],
    'unknown assignee' => [['assigned_to' => 999999], 'assigned_to'],
    'unknown company' => [['company_id' => 999999], 'company_id'],
    'no company for the superadmin' => [['company_id' => null], 'company_id'],
]);

test('leads api links a lead source by id and backfills the source text from it', function () {
    $companyId = leadCompany();
    $source = LeadSource::query()->create(['company_id' => $companyId, 'name' => 'Referral', 'is_active' => true]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/leads', leadPayload($companyId, ['lead_source_id' => $source->id, 'source' => 'ignored free text']))
        ->assertSuccessful();

    $lead = Lead::query()->firstOrFail();

    expect($lead->lead_source_id)->toBe($source->id)
        ->and($lead->source)->toBe('Referral');
});

test('leads api rejects an unknown lead_source_id', function () {
    $companyId = leadCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/leads', leadPayload($companyId, ['lead_source_id' => 999999]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lead_source_id']);
});

test('leads api clears the lead source link when none is given, keeping free-text source', function () {
    $companyId = leadCompany();
    $source = LeadSource::query()->create(['company_id' => $companyId, 'name' => 'Referral', 'is_active' => true]);
    $lead = leadMake($companyId, ['lead_source_id' => $source->id, 'source' => 'Referral']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->putJson('/api/leads/'.$lead->id, leadPayload($companyId, ['lead_source_id' => null, 'source' => 'Cold Call']))
        ->assertSuccessful();

    $lead->refresh();

    expect($lead->lead_source_id)->toBeNull()
        ->and($lead->source)->toBe('Cold Call');
});

test('leads api assigns a lead to a user', function () {
    $companyId = leadCompany();
    $rep = User::factory()->create(['company_id' => $companyId]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/leads', leadPayload($companyId, ['assigned_to' => $rep->id]))->assertSuccessful();

    expect(Lead::query()->firstOrFail()->assigned_to)->toBe($rep->id);
});

test('leads fetch returns only leads not yet converted, named for a dropdown', function () {
    $companyId = leadCompany();
    leadMake($companyId, ['name' => 'Open Lead', 'status' => 'qualified']);
    leadMake($companyId, ['name' => 'Converted Lead', 'status' => 'converted']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $rows = $this->getJson('/api/fetchleads?company_id='.$companyId)->assertSuccessful()->json();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['text'])->toBe('Open Lead');
});

test('leads index lists leads with company names, assignee names and trash count', function () {
    $companyId = leadCompany();
    $rep = User::factory()->create(['company_id' => $companyId, 'first_name' => 'Sara', 'last_name' => 'Khan']);
    leadMake($companyId, ['name' => 'Ahmed Raza', 'assigned_to' => $rep->id]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->getJson('/api/leads')->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.company_name_display'))->toBe('Company LEAD001')
        ->and($response->json('data.data.0.name'))->toBe('Ahmed Raza')
        ->and($response->json('data.data.0.assignee_name'))->toBe('Sara Khan')
        ->and($response->json('trash_count'))->toBe(0);
});

test('leads index searches name, business, email and phone, and filters by status', function () {
    $companyId = leadCompany();
    leadMake($companyId, ['name' => 'Ahmed Raza', 'company_name' => 'Raza Traders', 'phone' => '0300-1111111', 'status' => 'new']);
    leadMake($companyId, ['name' => 'Zaid Ali', 'email' => 'zaid@example.com', 'status' => 'qualified']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $names = fn (array $query) => collect($this->getJson('/api/leads?'.http_build_query($query))->assertSuccessful()->json('data.data'))->pluck('name')->all();

    expect($names(['search' => 'Raza Traders']))->toBe(['Ahmed Raza'])
        ->and($names(['search' => 'zaid@example.com']))->toBe(['Zaid Ali'])
        ->and($names(['status' => 'qualified']))->toBe(['Zaid Ali'])
        ->and($names(['status' => 'all']))->toHaveCount(2);
});

test('leads show returns one lead with its company and assignee', function () {
    $companyId = leadCompany();
    $rep = User::factory()->create(['company_id' => $companyId, 'first_name' => 'Sara', 'last_name' => 'Khan']);
    $lead = leadMake($companyId, ['assigned_to' => $rep->id]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->getJson('/api/leads/'.$lead->id)
        ->assertSuccessful()
        ->assertJsonPath('company.name', 'Company LEAD001')
        ->assertJsonPath('assignee.first_name', 'Sara');

    $this->getJson('/api/leads/999999')->assertNotFound();
});

test('leads api updates a lead', function () {
    $companyId = leadCompany();
    $lead = leadMake($companyId, ['status' => 'new']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->putJson('/api/leads/'.$lead->id, leadPayload($companyId, [
        'name' => 'Renamed Lead',
        'status' => 'qualified',
    ]))->assertSuccessful();

    $lead->refresh();

    expect($lead->name)->toBe('Renamed Lead')
        ->and($lead->status)->toBe('qualified');
});

test('leads api soft deletes, lists the trash, restores and permanently deletes', function () {
    $companyId = leadCompany();
    $lead = leadMake($companyId, ['name' => 'Trash Me Lead']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->deleteJson('/api/leads/'.$lead->id)->assertSuccessful();

    expect(Lead::query()->find($lead->id))->toBeNull()
        ->and(Lead::onlyTrashed()->find($lead->id))->not->toBeNull();

    $this->getJson('/api/leads/trash')
        ->assertSuccessful()
        ->assertJsonPath('data.data.0.name', 'Trash Me Lead');
    $this->getJson('/api/leads')->assertJsonPath('trash_count', 1);

    $this->postJson('/api/leads/restore_records', [$lead->id])->assertSuccessful();
    expect(Lead::query()->find($lead->id))->not->toBeNull();

    $this->postJson('/api/leads/bulk_delete', [$lead->id])->assertSuccessful();
    $this->postJson('/api/leads/bulk_delete_per', [$lead->id])->assertSuccessful();

    expect(Lead::withTrashed()->find($lead->id))->toBeNull();
});

test('leads api changes the status of the given leads', function () {
    $lead = leadMake(leadCompany(), ['status' => 'new']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/leads/statusupdate', ['ids' => [$lead->id], 'status' => 'contacted'])->assertSuccessful();
    expect($lead->refresh()->status)->toBe('contacted');

    $this->postJson('/api/leads/statusupdate', ['ids' => [$lead->id], 'status' => 'bogus'])->assertStatus(422);
    expect($lead->refresh()->status)->toBe('contacted');
});

test('leads write actions are forbidden without the menu permission', function () {
    $companyId = leadCompany();
    $lead = leadMake($companyId);
    Sanctum::actingAs(leadStaff($companyId));

    $this->postJson('/api/leads', leadPayload($companyId))->assertForbidden();
    $this->putJson('/api/leads/'.$lead->id, leadPayload($companyId, ['name' => 'Hijacked']))->assertForbidden();
    $this->postJson('/api/leads/statusupdate', ['ids' => [$lead->id], 'status' => 'contacted'])->assertForbidden();

    // delete and restore answer "406" in place of doing anything
    $this->deleteJson('/api/leads/'.$lead->id)->assertSuccessful()->assertContent('"406"');
    $this->postJson('/api/leads/bulk_delete', [$lead->id])->assertSuccessful()->assertContent('"406"');

    $lead->refresh();

    expect(Lead::query()->count())->toBe(1)
        ->and($lead->name)->toBe('Existing Lead')
        ->and($lead->trashed())->toBeFalse();
});

test('a user with the menu permissions can add, edit, delete and restore in their own company', function () {
    $companyId = leadCompany();
    Sanctum::actingAs(leadStaff($companyId, [
        '/leads/add', '/leads/:id/edit', '/leads/delete', '/leads/restore',
    ]));

    $this->postJson('/api/leads', leadPayload($companyId, ['company_id' => null]))->assertSuccessful();

    $lead = Lead::query()->firstOrFail();

    $this->putJson('/api/leads/'.$lead->id, leadPayload($companyId, ['name' => 'Edited Lead']))->assertSuccessful();
    expect($lead->refresh()->name)->toBe('Edited Lead');

    $this->deleteJson('/api/leads/'.$lead->id)->assertSuccessful()->assertJson(['message' => 'Successfully Deleted']);
    expect(Lead::query()->count())->toBe(0);

    $this->postJson('/api/leads/restore_records', [$lead->id])->assertSuccessful()->assertJson(['message' => 'Successfully Restored']);
    expect(Lead::query()->count())->toBe(1);
});

test('a company user always saves under their own company, whatever company the request names', function () {
    $ownCompany = leadCompany('LEAD001');
    $otherCompany = leadCompany('LEAD002');
    Sanctum::actingAs(leadStaff($ownCompany, ['/leads/add', '/leads/:id/edit']));

    $this->postJson('/api/leads', leadPayload($otherCompany))->assertSuccessful();

    $lead = Lead::query()->firstOrFail();

    expect($lead->company_id)->toBe($ownCompany);

    $this->putJson('/api/leads/'.$lead->id, leadPayload($otherCompany, ['name' => 'Still Mine']))->assertSuccessful();

    expect($lead->refresh()->company_id)->toBe($ownCompany)
        ->and($lead->name)->toBe('Still Mine');
});

test('a company user cannot see or change the leads of another company', function () {
    $ownCompany = leadCompany('LEAD001');
    $otherCompany = leadCompany('LEAD002');
    $mine = leadMake($ownCompany, ['name' => 'Mine Lead']);
    $theirs = leadMake($otherCompany, ['name' => 'Theirs Lead']);
    Sanctum::actingAs(leadStaff($ownCompany, ['/leads', '/leads/:id/edit', '/leads/delete']));

    $listed = collect($this->getJson('/api/leads')->assertSuccessful()->json('data.data'))->pluck('name')->all();

    expect($listed)->toBe(['Mine Lead']);

    $this->getJson('/api/leads/'.$theirs->id)->assertNotFound();
    $this->getJson('/api/leads?company_id='.$otherCompany)->assertJsonCount(0, 'data.data');

    $this->putJson('/api/leads/'.$theirs->id, leadPayload($ownCompany, ['name' => 'Taken']))->assertNotFound();
    $this->postJson('/api/leads/bulk_delete', [$theirs->id, $mine->id])->assertSuccessful();

    expect($theirs->refresh()->name)->toBe('Theirs Lead')
        ->and($theirs->trashed())->toBeFalse()
        ->and($mine->refresh()->trashed())->toBeTrue();
});

test('leads api requires authentication', function () {
    $this->getJson('/api/leads')->assertUnauthorized();
    $this->postJson('/api/leads', ['name' => 'Nobody Lead'])->assertUnauthorized();
});
