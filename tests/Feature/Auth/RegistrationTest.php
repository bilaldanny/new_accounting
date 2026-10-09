<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;

uses(RefreshDatabase::class);

/**
 * Fortify's own registration stays off: it created a user from a `name` field the users table does not have, with no company
 * or role. Self sign-up is the app's own, much fuller flow (App\Services\SelfSignup, see SelfRegistrationTest).
 */
test('fortify registration is not one of the enabled fortify features', function () {
    expect(Features::enabled(Features::registration()))->toBeFalse();
});

test('the register screen is the public sign-up page', function () {
    $this->get('/register')->assertOk()->assertInertia(fn ($page) => $page->component('landing/Register'));
});

test('posting an incomplete form to the register endpoint creates no user', function () {
    $before = User::query()->count();

    $this->post('/register', [
        'first_name' => 'Test',
        'last_name' => 'User',
        'username' => 'testuser',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors();

    $this->assertGuest();
    expect(User::query()->count())->toBe($before);
});

test('the home page is the public landing page and offers register', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('landing/Index')->where('registrationEnabled', true));

    expect(file_get_contents(resource_path('js/pages/landing/Index.vue')))->toContain('/register');
});

test('the login page offers sign-up while public registration is open and not when it is closed', function () {
    $this->get('/login')->assertInertia(fn ($page) => $page->component('auth/Login')->where('canRegister', true));

    config(['landing.registration_enabled' => false]);

    $this->get('/login')->assertInertia(fn ($page) => $page->where('canRegister', false));
    expect(file_get_contents(resource_path('js/pages/auth/Login.vue')))->toContain('href="/register"');
});
