<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createLoginUser(array $overrides = []): User
{
    return User::query()->forceCreate(array_merge([
        'first_name' => 'Test',
        'last_name' => 'User',
        'username' => 'testuser',
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
        'pass' => 'password',
        'is_active' => true,
    ], $overrides));
}

test('users can authenticate using their username', function () {
    $user = createLoginUser([
        'username' => 'johndoe',
        'email' => 'john@example.com',
    ]);

    $response = $this->post(route('login.store'), [
        'email' => 'johndoe',
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('users can authenticate using their email', function () {
    $user = createLoginUser([
        'username' => 'johndoe',
        'email' => 'john@example.com',
    ]);

    $response = $this->post(route('login.store'), [
        'email' => 'john@example.com',
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('inactive users cannot authenticate', function () {
    createLoginUser([
        'username' => 'inactiveuser',
        'email' => 'inactive@example.com',
        'is_active' => false,
    ]);

    $response = $this->post(route('login.store'), [
        'email' => 'inactiveuser',
        'password' => 'password',
    ]);

    $this->assertGuest();
    $response->assertSessionHasErrors('email');
});

test('inactive users cannot authenticate with email either', function () {
    createLoginUser([
        'username' => 'inactiveuser',
        'email' => 'inactive@example.com',
        'is_active' => false,
    ]);

    $response = $this->post(route('login.store'), [
        'email' => 'inactive@example.com',
        'password' => 'password',
    ]);

    $this->assertGuest();
    $response->assertSessionHasErrors('email');
});
