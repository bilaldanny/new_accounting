<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function createBranchForImport(array $attributes = []): Branch
{
    $company = Company::query()->create([
        'code' => 'CO-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
        'name' => 'Branch Import Co',
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 5,
    ]);

    return Branch::query()->create(array_merge([
        'company_id' => $company->id,
        'code' => 'BR-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
        'name' => 'Existing Branch',
        'email' => 'existing@branch.test',
        'is_active' => true,
        'is_default' => true,
    ], $attributes));
}

/**
 * @return array{country_id: int, state_id: int, city_id: int}
 */
function seedBranchImportLocations(): array
{
    $countryId = DB::table('countries')->insertGetId([
        'name' => 'Pakistan',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $stateId = DB::table('states')->insertGetId([
        'name' => 'Punjab',
        'country_id' => $countryId,
        'country_code' => 'PK',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $cityId = DB::table('cities')->insertGetId([
        'name' => 'Lahore',
        'state_id' => $stateId,
        'state_code' => 'PB',
        'country_id' => $countryId,
        'country_code' => 'PK',
        'latitude' => 31.5497,
        'longitude' => 74.3436,
    ]);

    return [
        'country_id' => $countryId,
        'state_id' => $stateId,
        'city_id' => $cityId,
    ];
}

test('branches import creates records when id is empty', function () {
    $company = Company::query()->create([
        'code' => 'CO-00001',
        'name' => 'Import Branch Co',
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 5,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/branches/import', [
        'rows' => [
            [
                'id' => '',
                'company_id' => $company->id,
                'code' => '',
                'name' => 'Imported Branch',
                'email' => 'imported@branch.test',
                'phone' => '',
                'mobile' => '',
                'address' => '',
                'description' => '',
                'country_id' => '',
                'state_id' => '',
                'city_id' => '',
                'is_active' => 1,
                'is_default' => 0,
            ],
        ],
    ])->assertSuccessful()
        ->assertJsonPath('message', 'Successfully imported 1 new and updated 0 branch records.')
        ->assertJsonPath('summary.total', 1)
        ->assertJsonPath('summary.created', 1)
        ->assertJsonPath('summary.updated', 0)
        ->assertJsonPath('summary.failed', 0);

    $branch = Branch::query()->where('name', 'Imported Branch')->first();

    expect($branch)->not->toBeNull()
        ->and($branch->email)->toBe('imported@branch.test')
        ->and($branch->is_active)->toBeTrue()
        ->and($branch->code)->toMatch('/^BR-\d{5}$/');
});

test('branches import resolves location columns by name', function () {
    seedBranchImportLocations();

    $company = Company::query()->create([
        'code' => 'CO-00002',
        'name' => 'Location Import Co',
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 5,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/branches/import', [
        'rows' => [
            [
                'id' => '',
                'company_id' => $company->id,
                'code' => '',
                'name' => 'Lahore Branch',
                'email' => 'lahore@branch.test',
                'phone' => '',
                'mobile' => '',
                'address' => '',
                'description' => '',
                'country_id' => 'Pakistan',
                'state_id' => 'Punjab',
                'city_id' => 'Lahore',
                'is_active' => 1,
                'is_default' => 0,
            ],
        ],
    ])->assertSuccessful();

    $branch = Branch::query()->where('name', 'Lahore Branch')->first();

    expect($branch)->not->toBeNull()
        ->and($branch->country_id)->toBeGreaterThan(0)
        ->and($branch->state_id)->toBeGreaterThan(0)
        ->and($branch->city_id)->toBeGreaterThan(0);
});

test('branches import rejects unknown location names', function () {
    $company = Company::query()->create([
        'code' => 'CO-00003',
        'name' => 'Bad Location Co',
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 5,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/branches/import', [
        'rows' => [
            [
                'id' => '',
                'company_id' => $company->id,
                'code' => '',
                'name' => 'Bad Location Branch',
                'email' => 'bad@branch.test',
                'country_id' => 'Unknown Country',
                'is_active' => 1,
            ],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['rows']);
});

test('branches import updates records when id is provided', function () {
    $branch = createBranchForImport();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/branches/import', [
        'rows' => [
            [
                'id' => $branch->id,
                'company_id' => $branch->company_id,
                'code' => $branch->code,
                'name' => 'Updated Branch Name',
                'email' => 'updated@branch.test',
                'phone' => '',
                'mobile' => '',
                'address' => '',
                'description' => '',
                'country_id' => '',
                'state_id' => '',
                'city_id' => '',
                'is_active' => 1,
                'is_default' => 1,
            ],
        ],
    ])->assertSuccessful()
        ->assertJsonPath('message', 'Successfully imported 0 new and updated 1 branch records.');

    $branch->refresh();

    expect($branch->name)->toBe('Updated Branch Name')
        ->and($branch->email)->toBe('updated@branch.test')
        ->and($branch->is_active)->toBeTrue();
});

test('branches import rejects unknown ids', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/branches/import', [
        'rows' => [
            [
                'id' => 999999,
                'company_id' => '',
                'code' => 'BR-00001',
                'name' => 'Missing Branch',
                'email' => 'missing@branch.test',
                'is_active' => 1,
            ],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['rows']);
});

test('branches import rejects rows that exceed company branch limit', function () {
    $company = Company::query()->create([
        'code' => 'CO-00004',
        'name' => 'Import Limit Co',
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 2,
    ]);

    Branch::query()->create([
        'code' => 'BR-00001',
        'company_id' => $company->id,
        'name' => 'Existing Branch',
        'email' => 'existing@limit.test',
        'is_active' => true,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/branches/import', [
        'rows' => [
            [
                'id' => '',
                'company_id' => $company->id,
                'code' => '',
                'name' => 'Second Branch',
                'email' => 'second@limit.test',
                'is_active' => 1,
            ],
            [
                'id' => '',
                'company_id' => $company->id,
                'code' => '',
                'name' => 'Third Branch',
                'email' => 'third@limit.test',
                'is_active' => 1,
            ],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['rows']);
});

test('guests cannot import branches', function () {
    $this->postJson('/api/branches/import', [
        'rows' => [],
    ])->assertUnauthorized();
});
