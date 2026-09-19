<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->first_name)->toBe('Test');
    expect($user->last_name)->toBe('User');
    expect($user->full_name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    $this->assertSoftDeleted($user);
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->not->toBeNull();
});

test('profile page shows the current name, email and username', function () {
    $user = User::factory()->create([
        'first_name' => 'Amina',
        'last_name' => 'Khan',
        'username' => 'aminakhan',
        'email' => 'amina@example.com',
    ]);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Profile')
            ->where('username', 'aminakhan')
            ->where('auth.user.first_name', 'Amina')
            ->where('auth.user.last_name', 'Khan')
            ->where('auth.user.email', 'amina@example.com'));
});

test('a saved name shows up on the profile page again', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('profile.update'), [
        'first_name' => 'Bilal',
        'last_name' => 'Younus',
        'email' => $user->email,
    ])->assertSessionHasNoErrors();

    $this->actingAs($user->fresh())
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.first_name', 'Bilal')
            ->where('auth.user.last_name', 'Younus'));
});

test('the username cannot be changed from the profile page', function () {
    $user = User::factory()->create(['username' => 'original']);

    $this->actingAs($user)->patch(route('profile.update'), [
        'first_name' => 'Test',
        'last_name' => 'User',
        'username' => 'hijacked',
        'email' => $user->email,
    ])->assertSessionHasNoErrors();

    expect($user->fresh()->username)->toBe('original');
});

test('first and last name are required', function () {
    $user = User::factory()->create(['first_name' => 'Keep', 'last_name' => 'Me']);

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), ['first_name' => '', 'last_name' => '', 'email' => $user->email])
        ->assertSessionHasErrors(['first_name', 'last_name'])
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh()->first_name)->toBe('Keep')
        ->and($user->fresh()->last_name)->toBe('Me');
});

test('the old single name field is no longer accepted in place of first and last name', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), ['name' => 'Test User', 'email' => $user->email])
        ->assertSessionHasErrors(['first_name', 'last_name']);
});

test('the email must stay unique but the user may keep their own', function () {
    $other = User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), ['first_name' => 'A', 'last_name' => 'B', 'email' => $other->email])
        ->assertSessionHasErrors('email');

    $this->actingAs($user)
        ->patch(route('profile.update'), ['first_name' => 'A', 'last_name' => 'B', 'email' => $user->email])
        ->assertSessionHasNoErrors();
});
