<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function createRoleForImport(array $attributes = []): Role
{
    return Role::query()->create(array_merge([
        'name' => 'Existing Role',
        'is_active' => true,
        'is_admin' => false,
    ], $attributes));
}

test('roles import creates records when id is empty', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/roles/import', [
        'rows' => [
            [
                'id' => '',
                'company_id' => '',
                'branch_id' => '',
                'name' => 'Imported Role',
                'is_active' => 1,
            ],
        ],
    ])->assertSuccessful()
        ->assertJsonPath('message', 'Successfully imported 1 new and updated 0 role records.');

    $role = Role::query()->where('name', 'Imported Role')->first();

    expect($role)->not->toBeNull()
        ->and($role->is_active)->toBeTrue();
});

test('roles import updates records when id is provided', function () {
    $role = createRoleForImport();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/roles/import', [
        'rows' => [
            [
                'id' => $role->id,
                'company_id' => '',
                'branch_id' => '',
                'name' => 'Updated Role Name',
                'is_active' => 1,
            ],
        ],
    ])->assertSuccessful()
        ->assertJsonPath('message', 'Successfully imported 0 new and updated 1 role records.');

    $role->refresh();

    expect($role->name)->toBe('Updated Role Name')
        ->and($role->is_active)->toBeTrue();
});

test('roles import rejects unknown ids', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/roles/import', [
        'rows' => [
            [
                'id' => 999999,
                'company_id' => '',
                'branch_id' => '',
                'name' => 'Missing Role',
                'is_active' => 1,
            ],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['rows']);
});

test('guests cannot import roles', function () {
    $this->postJson('/api/roles/import', [
        'rows' => [],
    ])->assertUnauthorized();
});
