<?php

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function strongTestPassword(): string
{
    return 'Password1!';
}

function seedUserScope(): array
{
    $suffix = substr(str_replace('.', '', uniqid('', true)), -6);

    $companyId = DB::table('companies')->insertGetId([
        'code' => 'CMP'.$suffix,
        'name' => 'Test Company '.$suffix,
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $branchOneId = DB::table('branches')->insertGetId([
        'code' => 'BR'.$suffix,
        'company_id' => $companyId,
        'name' => 'Branch One',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'company_id' => $companyId,
        'branch_one_id' => $branchOneId,
    ];
}

function validUserCreatePayload(array $overrides = []): array
{
    $scope = seedUserScope();
    $role = createUserRole($overrides['role_attributes'] ?? []);
    unset($overrides['role_attributes']);

    $department = createUserDepartment([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_one_id'],
    ]);

    return array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_one_id'],
        'department_id' => $department->id,
        'role_id' => $role->id,
        'first_name' => 'John',
        'last_name' => 'Smith',
        'username' => 'johnsmith',
        'email' => 'john@example.com',
        'password' => strongTestPassword(),
        'password_confirmation' => strongTestPassword(),
        'user_image' => '/storage/photos/sample-user.png',
        'is_active' => true,
    ], $overrides);
}

function createUserDepartment(array $attributes = []): Department
{
    return Department::query()->create(array_merge([
        'name' => 'sales',
        'active' => true,
    ], $attributes));
}

function createUserRole(array $attributes = []): Role
{
    return Role::query()->create(array_merge([
        'name' => 'staff',
        'is_active' => true,
        'is_admin' => false,
    ], $attributes));
}

function createManagedUser(array $attributes = []): User
{
    $role = createUserRole();

    return User::query()->create(array_merge([
        'role_id' => $role->id,
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'username' => 'janedoe_'.$role->id,
        'email' => 'jane_'.$role->id.'@example.com',
        'password' => Hash::make('password123'),
        'is_active' => true,
    ], $attributes));
}

test('users index excludes superadmin records', function () {
    createManagedUser();

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/users');

    $response->assertSuccessful();
    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.first_name'))->toBe('Jane');
});

test('users index excludes companyadmin records', function () {
    $companyAdminRole = createUserRole(['name' => 'companyadmin']);

    User::query()->create([
        'role_id' => $companyAdminRole->id,
        'first_name' => 'Company',
        'last_name' => 'Admin',
        'username' => 'companyadminuser',
        'email' => 'companyadmin@example.com',
        'password' => Hash::make('password123'),
        'is_active' => true,
    ]);

    createManagedUser();

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/users');

    $response->assertSuccessful();
    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.first_name'))->toBe('Jane');
});

test('users api creates a user', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/users', validUserCreatePayload())
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Saved');

    expect(User::query()->where('email', 'john@example.com')->exists())->toBeTrue();
});

test('users api rejects duplicate email on create', function () {
    createManagedUser(['email' => 'duplicate@example.com', 'username' => 'userone']);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/users', validUserCreatePayload([
        'email' => 'duplicate@example.com',
        'username' => 'usertwo',
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);
});

test('users api updates a user without changing password when omitted', function () {
    $scope = seedUserScope();
    $managedUser = createManagedUser([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_one_id'],
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $originalPassword = $managedUser->password;

    $this->putJson('/api/users/'.$managedUser->id, [
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_one_id'],
        'role_id' => $managedUser->role_id,
        'department_id' => $managedUser->department_id ?: createUserDepartment([
            'company_id' => $scope['company_id'],
            'branch_id' => $scope['branch_one_id'],
        ])->id,
        'first_name' => 'Updated',
        'last_name' => 'Name',
        'email' => $managedUser->email,
        'is_active' => true,
    ])->assertSuccessful();

    $managedUser->refresh();

    expect($managedUser->first_name)->toBe('Updated')
        ->and($managedUser->password)->toBe($originalPassword);
});

test('users api requires department_id on create', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $payload = validUserCreatePayload();
    unset($payload['department_id']);

    $this->postJson('/api/users', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['department_id']);
});

test('users api allows create without user_image', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $payload = validUserCreatePayload();
    unset($payload['user_image']);

    $this->postJson('/api/users', $payload)
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Saved');

    $user = User::query()->where('email', $payload['email'])->first();

    expect($user)->not->toBeNull()
        ->and($user->getRawOriginal('user_image'))->toBeNull();
});

test('users api rejects weak passwords on create', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/users', validUserCreatePayload([
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);
});

test('users check-identity api reports when email or username already exists', function () {
    createManagedUser([
        'username' => 'existinguser',
        'email' => 'existing@example.com',
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/users/check-identity', [
        'email' => 'existing@example.com',
    ])->assertSuccessful()->assertJson(['email_taken' => true]);

    $this->postJson('/api/users/check-identity', [
        'email' => 'new@example.com',
    ])->assertSuccessful()->assertJson(['email_taken' => false]);

    $this->postJson('/api/users/check-identity', [
        'username' => 'existinguser',
    ])->assertSuccessful()->assertJson(['username_taken' => true]);

    $this->postJson('/api/users/check-identity', [
        'username' => 'newuser',
    ])->assertSuccessful()->assertJson(['username_taken' => false]);
});

test('users check-identity api ignores the current user when editing', function () {
    $managedUser = createManagedUser([
        'username' => 'currentuser',
        'email' => 'current@example.com',
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/users/check-identity', [
        'email' => 'current@example.com',
        'except_user_id' => $managedUser->id,
    ])->assertSuccessful()->assertJson(['email_taken' => false]);

    $this->postJson('/api/users/check-identity', [
        'username' => 'currentuser',
        'except_user_id' => $managedUser->id,
    ])->assertSuccessful()->assertJson(['username_taken' => false]);
});

test('guests cannot access users api', function () {
    $this->getJson('/api/users')->assertUnauthorized();
});

test('companyadmin cannot assign another company or a foreign role when creating a user', function () {
    $own = seedUserScope();
    $other = seedUserScope();

    $ownRole = createUserRole(['name' => 'staff', 'company_id' => $own['company_id']]);
    $foreignRole = createUserRole(['name' => 'otherstaff', 'company_id' => $other['company_id']]);
    $department = createUserDepartment([
        'company_id' => $own['company_id'],
        'branch_id' => $own['branch_one_id'],
    ]);

    $adminRole = createUserRole(['name' => 'companyadmin', 'company_id' => $own['company_id']]);
    $companyAdmin = User::query()->create([
        'role_id' => $adminRole->id,
        'company_id' => $own['company_id'],
        'branch_id' => $own['branch_one_id'],
        'first_name' => 'Company',
        'last_name' => 'Admin',
        'username' => 'ownadmin',
        'email' => 'ownadmin@example.com',
        'password' => Hash::make('password'),
        'is_active' => true,
    ]);

    grantMenuPermission((int) $adminRole->id, '/user/add');
    Sanctum::actingAs($companyAdmin);

    $this->postJson('/api/users', [
        'company_id' => $other['company_id'],
        'branch_id' => $own['branch_one_id'],
        'department_id' => $department->id,
        'role_id' => $ownRole->id,
        'first_name' => 'Locked',
        'last_name' => 'User',
        'username' => 'lockeduser',
        'email' => 'locked@example.com',
        'password' => strongTestPassword(),
        'password_confirmation' => strongTestPassword(),
        'is_active' => true,
    ])->assertSuccessful();

    $created = User::query()->where('email', 'locked@example.com')->first();

    expect($created)->not->toBeNull()
        ->and((int) $created->company_id)->toBe($own['company_id'])
        ->and((int) $created->branch_id)->toBe($own['branch_one_id'])
        ->and((int) $created->role_id)->toBe($ownRole->id);

    $this->postJson('/api/users', [
        'company_id' => $own['company_id'],
        'branch_id' => $own['branch_one_id'],
        'department_id' => $department->id,
        'role_id' => $foreignRole->id,
        'first_name' => 'Foreign',
        'last_name' => 'Role',
        'username' => 'foreignrole',
        'email' => 'foreignrole@example.com',
        'password' => strongTestPassword(),
        'password_confirmation' => strongTestPassword(),
        'is_active' => true,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['role_id']);

    $this->postJson('/api/users', [
        'company_id' => $own['company_id'],
        'branch_id' => $other['branch_one_id'],
        'department_id' => $department->id,
        'role_id' => $ownRole->id,
        'first_name' => 'Foreign',
        'last_name' => 'Branch',
        'username' => 'foreignbranch',
        'email' => 'foreignbranch@example.com',
        'password' => strongTestPassword(),
        'password_confirmation' => strongTestPassword(),
        'is_active' => true,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['branch_id']);
});

test('branch staff cannot move a created user to another branch via the payload', function () {
    $scope = seedUserScope();
    $otherBranchId = DB::table('branches')->insertGetId([
        'code' => 'BRX'.$scope['company_id'],
        'company_id' => $scope['company_id'],
        'name' => 'Branch Two',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $staffRole = createUserRole(['name' => 'clerk', 'company_id' => $scope['company_id']]);
    $department = createUserDepartment([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_one_id'],
    ]);

    $actor = User::query()->create([
        'role_id' => $staffRole->id,
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_one_id'],
        'first_name' => 'Branch',
        'last_name' => 'Clerk',
        'username' => 'branchclerk',
        'email' => 'branchclerk@example.com',
        'password' => Hash::make('password'),
        'is_active' => true,
    ]);

    grantMenuPermission((int) $staffRole->id, '/user/add');
    Sanctum::actingAs($actor);

    $this->postJson('/api/users', [
        'company_id' => $scope['company_id'],
        'branch_id' => $otherBranchId,
        'department_id' => $department->id,
        'role_id' => $staffRole->id,
        'first_name' => 'Same',
        'last_name' => 'Branch',
        'username' => 'samebranch',
        'email' => 'samebranch@example.com',
        'password' => strongTestPassword(),
        'password_confirmation' => strongTestPassword(),
        'is_active' => true,
    ])->assertSuccessful();

    $created = User::query()->where('email', 'samebranch@example.com')->first();

    expect($created)->not->toBeNull()
        ->and((int) $created->branch_id)->toBe($scope['branch_one_id']);
});
