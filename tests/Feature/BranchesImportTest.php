<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ->assertJsonPath('message', 'Successfully imported 1 new and updated 0 branch records.');

    $branch = Branch::query()->where('name', 'Imported Branch')->first();

    expect($branch)->not->toBeNull()
        ->and($branch->email)->toBe('imported@branch.test')
        ->and($branch->is_active)->toBeTrue();
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

test('guests cannot import branches', function () {
    $this->postJson('/api/branches/import', [
        'rows' => [],
    ])->assertUnauthorized();
});
