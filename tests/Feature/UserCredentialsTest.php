<?php

use App\Mail\UserCredentialsMail;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function credentialsStaffUser(array $attributes = []): User
{
    $role = Role::query()->create([
        'name' => 'staff',
        'is_active' => true,
        'is_admin' => false,
    ]);

    return User::query()->create(array_merge([
        'role_id' => $role->id,
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'username' => 'janedoe_'.$role->id,
        'email' => 'jane_'.$role->id.'@example.com',
        'password' => Hash::make('OldPassword1!'),
        'is_active' => true,
    ], $attributes));
}

test('user credentials mailable includes login details', function () {
    $mailable = new UserCredentialsMail(
        recipientName: 'Jane Doe',
        username: 'janedoe',
        email: 'jane@example.com',
        plainPassword: 'Password1!',
        loginUrl: 'http://localhost/login',
    );

    $mailable->assertSeeInHtml('Jane Doe');
    $mailable->assertSeeInHtml('janedoe');
    $mailable->assertSeeInHtml('jane@example.com');
    $mailable->assertSeeInHtml('Password1!');
    $mailable->assertSeeInHtml('http://localhost/login');
});

test('guests cannot send user credentials', function () {
    $user = credentialsStaffUser();

    $this->postJson("/api/users/{$user->id}/send-credentials")
        ->assertUnauthorized();
});

test('users api queues credentials email and resets the password', function () {
    Mail::fake();

    $user = credentialsStaffUser();
    $originalPassword = $user->password;

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson("/api/users/{$user->id}/send-credentials")
        ->assertSuccessful()
        ->assertJsonPath('message', 'Login credentials have been queued for email.');

    $plainPassword = null;

    Mail::assertQueued(UserCredentialsMail::class, function (UserCredentialsMail $mail) use ($user, &$plainPassword): bool {
        $plainPassword = $mail->plainPassword;

        return $mail->hasTo($user->email)
            && $mail->username === $user->username
            && $mail->recipientName === $user->full_name;
    });

    $user->refresh();

    expect($plainPassword)->not->toBeNull()
        ->and($user->password)->not->toBe($originalPassword)
        ->and(Hash::check($plainPassword, $user->password))->toBeTrue();
});

test('users api cannot send credentials for hidden users', function () {
    Mail::fake();

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson("/api/users/{$superadmin->id}/send-credentials")
        ->assertNotFound();

    Mail::assertNothingQueued();
});

test('staff without edit permission cannot send user credentials', function () {
    Mail::fake();

    $target = credentialsStaffUser();
    $actor = credentialsStaffUser([
        'username' => 'clerkuser',
        'email' => 'clerk@example.com',
    ]);

    Sanctum::actingAs($actor);

    $this->postJson("/api/users/{$target->id}/send-credentials")
        ->assertForbidden();

    Mail::assertNothingQueued();
});

test('guests cannot send company credentials', function () {
    $company = Company::query()->create([
        'code' => 'CO-00030',
        'name' => 'No Auth Co',
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 2,
    ]);

    $this->postJson("/api/companies/{$company->id}/send-credentials")
        ->assertUnauthorized();
});

test('companies api queues credentials email for the company administrator', function () {
    Mail::fake();

    $company = Company::query()->create([
        'code' => 'CO-00031',
        'name' => 'Credentials Co',
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 2,
    ]);

    $admin = User::query()->create([
        'company_id' => $company->id,
        'first_name' => 'Owner',
        'last_name' => 'Person',
        'username' => 'ownerperson',
        'email' => 'owner@example.com',
        'password' => Hash::make('OldPassword1!'),
        'is_active' => true,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson("/api/companies/{$company->id}/send-credentials")
        ->assertSuccessful()
        ->assertJsonPath('message', 'Login credentials have been queued for email.');

    Mail::assertQueued(UserCredentialsMail::class, function (UserCredentialsMail $mail) use ($admin): bool {
        return $mail->hasTo($admin->email)
            && $mail->username === $admin->username;
    });

    $admin->refresh();

    expect(Hash::check('OldPassword1!', $admin->password))->toBeFalse();
});

test('companies api rejects sending credentials when the company has no administrator', function () {
    Mail::fake();

    $company = Company::query()->create([
        'code' => 'CO-00032',
        'name' => 'Empty Co',
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 2,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson("/api/companies/{$company->id}/send-credentials")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This company does not have an administrator account.');

    Mail::assertNothingQueued();
});

test('non superadmins cannot send company credentials', function () {
    Mail::fake();

    $company = Company::query()->create([
        'code' => 'CO-00033',
        'name' => 'Locked Co',
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 2,
    ]);

    $actor = credentialsStaffUser();
    grantMenuPermission((int) $actor->role_id, '/company/:id/edit');
    Sanctum::actingAs($actor);

    $this->postJson("/api/companies/{$company->id}/send-credentials")
        ->assertForbidden();

    Mail::assertNothingQueued();
});
