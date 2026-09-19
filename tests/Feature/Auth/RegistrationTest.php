<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

/**
 * Self sign-up is switched off. POST /register used to return 500 (it created the user from a
 * `name` field, but users needs first_name, last_name and username), and a self-registered user
 * would have had no company or role anyway: companies get their admin through
 * User::createCompanyAdmin and other staff are added in the Users module.
 */
test('registration is not one of the enabled fortify features', function () {
    expect(Features::enabled(Features::registration()))->toBeFalse();
});

test('the registration screen is not available', function () {
    $this->get('/register')->assertNotFound();
});

test('posting to the registration endpoint creates no user', function () {
    $before = User::query()->count();

    $this->post('/register', [
        'first_name' => 'Test',
        'last_name' => 'User',
        'username' => 'testuser',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();

    $this->assertGuest();
    expect(User::query()->count())->toBe($before);
});

test('the welcome page no longer offers a register link', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Welcome'));

    expect(file_get_contents(resource_path('js/pages/Welcome.vue')))->not->toContain('register');
});
