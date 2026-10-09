<?php

use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * @return array{company_id: int, source_id: int}
 */
function seedLeadsImportScope(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'LIMP001',
        'name' => 'Company LIMP001',
        'address' => '1 Test Street',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $source = LeadSource::query()->create([
        'company_id' => $companyId,
        'name' => 'Website',
        'is_active' => true,
    ]);

    return ['company_id' => $companyId, 'source_id' => $source->id];
}

test('leads import creates a new lead, resolving the lead source by name', function () {
    $scope = seedLeadsImportScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->postJson('/api/leads/import', [
        'rows' => [[
            'id' => '',
            'company_id' => $scope['company_id'],
            'name' => 'Ahmed Raza',
            'company_name' => 'Raza Traders',
            'email' => 'ahmed@example.com',
            'phone' => '0300-1234567',
            'source' => 'Website',
            'status' => 'new',
            'notes' => 'Imported lead',
        ]],
    ])->assertSuccessful();

    expect($response->json('message'))->toBe('Successfully imported 1 new and updated 0 lead records.');

    $lead = Lead::query()->where('company_id', $scope['company_id'])->firstOrFail();

    expect($lead->name)->toBe('Ahmed Raza')
        ->and($lead->company_name)->toBe('Raza Traders')
        ->and($lead->lead_source_id)->toBe($scope['source_id'])
        ->and($lead->source)->toBe('Website');
});

test('leads import leaves the source as free text when the name does not match any lead source', function () {
    $scope = seedLeadsImportScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/leads/import', [
        'rows' => [[
            'company_id' => $scope['company_id'],
            'name' => 'Zaid Ali',
            'source' => 'Trade Show',
        ]],
    ])->assertSuccessful();

    $lead = Lead::query()->firstOrFail();

    expect($lead->lead_source_id)->toBeNull()
        ->and($lead->source)->toBe('Trade Show');
});

test('leads import updates an existing lead by id', function () {
    $scope = seedLeadsImportScope();
    $lead = Lead::query()->create(['company_id' => $scope['company_id'], 'name' => 'Old Name', 'status' => 'new']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->postJson('/api/leads/import', [
        'rows' => [[
            'id' => $lead->id,
            'company_id' => $scope['company_id'],
            'name' => 'New Name',
            'status' => 'qualified',
        ]],
    ])->assertSuccessful();

    expect($response->json('message'))->toBe('Successfully imported 0 new and updated 1 lead records.');

    expect($lead->refresh()->name)->toBe('New Name')
        ->and($lead->status)->toBe('qualified');
});

test('leads import rejects an unknown id', function () {
    $scope = seedLeadsImportScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/leads/import', [
        'rows' => [[
            'id' => 999999,
            'company_id' => $scope['company_id'],
            'name' => 'Ghost Lead',
        ]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['rows']);

    expect(Lead::query()->count())->toBe(0);
});

test('leads import rejects a row with no name', function () {
    $scope = seedLeadsImportScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/leads/import', [
        'rows' => [[
            'company_id' => $scope['company_id'],
            'name' => '',
        ]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['rows.0.name']);

    expect(Lead::query()->count())->toBe(0);
});

test('leads import is all-or-nothing: one bad row rolls back the whole batch', function () {
    $scope = seedLeadsImportScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/leads/import', [
        'rows' => [
            ['company_id' => $scope['company_id'], 'name' => 'Good Lead'],
            ['id' => 999999, 'company_id' => $scope['company_id'], 'name' => 'Bad Lead'],
        ],
    ])->assertUnprocessable();

    expect(Lead::query()->count())->toBe(0);
});

test('leads import is forbidden without the menu permission', function () {
    $scope = seedLeadsImportScope();
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => $scope['company_id'], 'is_active' => true]);
    Sanctum::actingAs(createStaffUserForRole($role, ['company_id' => $scope['company_id']]));

    $this->postJson('/api/leads/import', [
        'rows' => [['company_id' => $scope['company_id'], 'name' => 'Nope Lead']],
    ])->assertForbidden();

    expect(Lead::query()->count())->toBe(0);
});

test('guests cannot import leads', function () {
    $this->postJson('/api/leads/import', ['rows' => [['name' => 'Nobody Lead']]])->assertUnauthorized();
});
