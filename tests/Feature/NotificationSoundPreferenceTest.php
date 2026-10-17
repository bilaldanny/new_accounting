<?php

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * The notification sound switch is the user's own preference on their account: on by default, saved per user, and nobody else's.
 */
test('the sound is on by default, can be turned off, and is remembered', function () {
    $scope = fcoScope();
    $user = fcoStaff($scope, ['/dashboard'], false);
    Sanctum::actingAs($user);

    $this->getJson('/api/me/preferences')->assertOk()->assertJsonPath('data.notification_sound', true);

    $this->putJson('/api/me/preferences', ['notification_sound' => false])->assertOk()->assertJsonPath('data.notification_sound', false);
    expect((bool) DB::table('users')->where('id', $user->id)->value('notification_sound'))->toBeFalse();
    $this->getJson('/api/me/preferences')->assertOk()->assertJsonPath('data.notification_sound', false);

    $this->putJson('/api/me/preferences', ['notification_sound' => true])->assertOk();
    expect((bool) DB::table('users')->where('id', $user->id)->value('notification_sound'))->toBeTrue();
});

test('one user switching the sound off changes nobody else', function () {
    $scope = fcoScope();
    $first = fcoStaff($scope, ['/dashboard'], false);
    $second = fcoStaff($scope, ['/dashboard'], false);
    $other = fcoStaff(fcoOtherCompany('snd'), ['/dashboard'], false);

    Sanctum::actingAs($first);
    $this->putJson('/api/me/preferences', ['notification_sound' => false])->assertOk();

    foreach ([$second, $other] as $user) {
        Sanctum::actingAs($user);
        $this->getJson('/api/me/preferences')->assertOk()->assertJsonPath('data.notification_sound', true);
    }

    expect((bool) DB::table('users')->where('id', $second->id)->value('notification_sound'))->toBeTrue();
});

test('the preference needs a true or false and a signed-in user, and cannot name another user', function () {
    $this->putJson('/api/me/preferences', ['notification_sound' => false])->assertUnauthorized();

    $scope = fcoScope();
    $user = fcoStaff($scope, ['/dashboard'], false);
    $victim = fcoStaff($scope, ['/dashboard'], false);
    Sanctum::actingAs($user);

    $this->putJson('/api/me/preferences', [])->assertUnprocessable();
    $this->putJson('/api/me/preferences', ['notification_sound' => 'maybe'])->assertUnprocessable();
    $this->putJson('/api/me/preferences', ['notification_sound' => false, 'user_id' => $victim->id])->assertOk();

    expect((bool) DB::table('users')->where('id', $victim->id)->value('notification_sound'))->toBeTrue();
});

test('the page carries the preference for the signed-in user', function () {
    $scope = fcoScope();
    $user = fcoStaff($scope, ['/dashboard'], false);
    DB::table('users')->where('id', $user->id)->update(['notification_sound' => false]);

    $this->actingAs($user->fresh())->get('/dashboard')->assertOk()->assertSee('notification_sound":false', false);
});
