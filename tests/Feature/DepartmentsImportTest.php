<?php

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function createDepartmentForImport(array $attributes = []): Department
{
    return Department::query()->create(array_merge([
        'name' => 'Existing Department',
        'active' => true,
    ], $attributes));
}

test('departments import creates records when id is empty', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/departments/import', [
        'rows' => [
            [
                'id' => '',
                'company_id' => '',
                'branch_id' => '',
                'name' => 'Imported Department',
                'active' => 1,
            ],
        ],
    ])->assertSuccessful()
        ->assertJsonPath('message', 'Successfully imported 1 new and updated 0 department records.');

    $department = Department::query()->where('name', 'Imported Department')->first();

    expect($department)->not->toBeNull()
        ->and($department->active)->toBeTrue();
});

test('departments import updates records when id is provided', function () {
    $department = createDepartmentForImport();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/departments/import', [
        'rows' => [
            [
                'id' => $department->id,
                'company_id' => '',
                'branch_id' => '',
                'name' => 'Updated Department Name',
                'active' => 1,
            ],
        ],
    ])->assertSuccessful()
        ->assertJsonPath('message', 'Successfully imported 0 new and updated 1 department records.');

    $department->refresh();

    expect($department->name)->toBe('Updated Department Name')
        ->and($department->active)->toBeTrue();
});

test('departments import rejects unknown ids', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/departments/import', [
        'rows' => [
            [
                'id' => 999999,
                'company_id' => '',
                'branch_id' => '',
                'name' => 'Missing Department',
                'active' => 1,
            ],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['rows']);
});

test('guests cannot import departments', function () {
    $this->postJson('/api/departments/import', [
        'rows' => [],
    ])->assertUnauthorized();
});
