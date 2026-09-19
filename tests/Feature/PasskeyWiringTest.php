<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Contracts\PasskeyUser;

/**
 * The User model lost `implements PasskeyUser` and the PasskeyAuthenticatable trait when it was
 * customised, so passkeys() did not exist and /settings/security returned 500. These cover the wiring
 * beyond "the page renders": the users table has no `name` column, which the trait asks for.
 */
test('the user model is a passkey user with a passkeys relation', function () {
    $user = User::factory()->create();

    expect($user)->toBeInstanceOf(PasskeyUser::class)
        ->and($user->hasPasskeysEnabled())->toBeFalse();

    $user->passkeys()->create(['name' => 'Laptop', 'credential_id' => 'cred-1', 'credential' => ['aaguid' => '00000000-0000-0000-0000-000000000000']]);

    expect($user->hasPasskeysEnabled())->toBeTrue()
        ->and($user->passkeys()->count())->toBe(1);
});

test('the webauthn identity falls back to the email because users have no name column', function () {
    $user = User::factory()->create(['email' => 'pat@example.com']);

    expect($user->getPasskeyDisplayName())->toBe('pat@example.com')
        ->and($user->getPasskeyUsername())->toBe('pat@example.com')
        ->and(strlen($user->getPasskeyUserHandle()))->toBe(32);
});

test('a signed in user can fetch passkey registration options', function () {
    $user = User::factory()->create(['email' => 'pat@example.com']);

    $response = $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->getJson('/user/passkeys/options')
        ->assertSuccessful();

    expect($response->json('options.challenge'))->not->toBeEmpty()
        ->and($response->json('options.user.name'))->toBe('pat@example.com')
        ->and($response->json('options.user.displayName'))->toBe('pat@example.com');
});

test('a guest can fetch passkey login options', function () {
    $response = $this->getJson('/passkeys/login/options')->assertSuccessful();

    expect($response->json('options.challenge'))->not->toBeEmpty();
});

test('the security page lists the passkeys a user has registered', function () {
    $user = User::factory()->create();
    $user->passkeys()->create(['name' => 'Work laptop', 'credential_id' => 'cred-work', 'credential' => ['aaguid' => '00000000-0000-0000-0000-000000000000']]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Security')
            ->where('canManagePasskeys', true)
            ->has('passkeys', 1)
            ->where('passkeys.0.name', 'Work laptop')
            ->where('passkeys.0.authenticator', null));
});

test('a user only sees their own passkeys', function () {
    $owner = User::factory()->create();
    $owner->passkeys()->create(['name' => 'Owner key', 'credential_id' => 'cred-owner', 'credential' => []]);
    $other = User::factory()->create();

    $this->actingAs($other)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('passkeys', []));
});

// ------------------------------------------------------------ forgot password

test('forgot password sends the default reset notification', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();

    Notification::assertSentTo($user, ResetPassword::class);
});

test('forgot password does not fail for an unknown email', function () {
    Notification::fake();

    $this->post(route('password.email'), ['email' => 'nobody@example.com'])->assertSessionHasErrors('email');

    Notification::assertNothingSent();
});

test('the user model no longer overrides the reset notification with the missing email template code', function () {
    $method = new ReflectionMethod(User::class, 'sendPasswordResetNotification');

    expect($method->getDeclaringClass()->getName())->not->toBe(User::class);
});
