<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function createImportRole(): Role
{
    return Role::query()->create([
        'name' => 'importrole',
        'is_active' => true,
        'is_admin' => false,
    ]);
}

function createImportUser(array $attributes = []): User
{
    $role = createImportRole();

    return User::query()->create(array_merge([
        'role_id' => $role->id,
        'first_name' => 'Existing',
        'last_name' => 'User',
        'username' => 'existinguser',
        'email' => 'existing@example.com',
        'password' => Hash::make('password123'),
        'pass' => 'password123',
        'is_active' => true,
    ], $attributes));
}

test('users import creates records when id is empty', function () {
    $role = createImportRole();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/users/import', [
        'rows' => [
            [
                'id' => '',
                'company_id' => '',
                'branch_id' => '',
                'department_id' => '',
                'role_id' => $role->id,
                'first_name' => 'Imported',
                'last_name' => 'User',
                'username' => 'importeduser',
                'email' => 'imported@example.com',
                'password' => 'password123',
                'phone' => '',
                'is_active' => 1,
            ],
        ],
    ])->assertSuccessful()
        ->assertJsonPath('message', 'Successfully imported 1 new and updated 0 user records.');

    expect(User::query()->where('email', 'imported@example.com')->exists())->toBeTrue();
});

test('users import updates records when id is provided', function () {
    $user = createImportUser();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/users/import', [
        'rows' => [
            [
                'id' => $user->id,
                'company_id' => '',
                'branch_id' => '',
                'department_id' => '',
                'role_id' => $user->role_id,
                'first_name' => 'Updated',
                'last_name' => 'Import',
                'username' => $user->username,
                'email' => $user->email,
                'password' => '',
                'phone' => '555-0100',
                'is_active' => 1,
            ],
        ],
    ])->assertSuccessful()
        ->assertJsonPath('message', 'Successfully imported 0 new and updated 1 user records.');

    $user->refresh();

    expect($user->first_name)->toBe('Updated')
        ->and($user->phone)->toBe('555-0100');
});

test('users import rejects unknown ids', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/users/import', [
        'rows' => [
            [
                'id' => 999999,
                'role_id' => 2,
                'first_name' => 'Missing',
                'last_name' => 'User',
                'username' => 'missinguser',
                'email' => 'missing@example.com',
            ],
        ],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['rows']);
});

test('guests cannot import users', function () {
    $this->postJson('/api/users/import', [
        'rows' => [],
    ])->assertUnauthorized();
});
