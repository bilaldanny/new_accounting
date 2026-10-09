<?php

use App\Models\Coupon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function cpnSuperadmin(): User
{
    $superadmin = User::query()->findOrFail(1);
    grantMenuPermission((int) $superadmin->role_id, '/coupons');
    grantMenuPermission((int) $superadmin->role_id, '/coupons/add');
    grantMenuPermission((int) $superadmin->role_id, '/coupons/:id/edit');
    grantMenuPermission((int) $superadmin->role_id, '/coupons/delete');

    return $superadmin;
}

test('a superadmin can create a coupon and it is listed', function () {
    Sanctum::actingAs(cpnSuperadmin());

    $this->postJson('/api/coupons', [
        'code' => 'SAVE10', 'type' => 'percent', 'value' => 10,
    ])->assertSuccessful();

    $this->getJson('/api/coupons')
        ->assertSuccessful()
        ->assertJsonPath('data.data.0.code', 'SAVE10');
});

test('a percent coupon discounts the right amount and never more than the total', function () {
    $coupon = Coupon::query()->create(['code' => 'HALF', 'type' => 'percent', 'value' => 50]);

    expect($coupon->discountFor(100))->toBe(50.0)
        ->and($coupon->discountFor(0))->toBe(0.0);

    $fixed = Coupon::query()->create(['code' => 'FLAT20', 'type' => 'fixed', 'value' => 20]);
    expect($fixed->discountFor(100))->toBe(20.0)
        ->and($fixed->discountFor(5))->toBe(5.0); // never more than the amount itself
});

test('an expired or redemption-capped coupon is not redeemable', function () {
    $expired = Coupon::query()->create([
        'code' => 'OLD', 'type' => 'fixed', 'value' => 5, 'is_active' => true,
        'valid_until' => now()->subDay()->toDateString(),
    ]);
    expect($expired->isRedeemable())->toBeFalse();

    $capped = Coupon::query()->create([
        'code' => 'CAPPED', 'type' => 'fixed', 'value' => 5, 'is_active' => true,
        'max_redemptions' => 1, 'redemptions_count' => 1,
    ]);
    expect($capped->isRedeemable())->toBeFalse();

    $fresh = Coupon::query()->create(['code' => 'FRESH', 'type' => 'fixed', 'value' => 5, 'is_active' => true]);
    expect($fresh->isRedeemable())->toBeTrue();
});

test('redeeming a coupon increments its redemption count', function () {
    $coupon = Coupon::query()->create(['code' => 'ONE', 'type' => 'fixed', 'value' => 5]);
    $coupon->redeem();

    expect($coupon->fresh()->redemptions_count)->toBe(1);
});

test('deleting a coupon soft deletes it', function () {
    Sanctum::actingAs(cpnSuperadmin());
    $coupon = Coupon::query()->create(['code' => 'GONE', 'type' => 'fixed', 'value' => 5]);

    $this->deleteJson('/api/coupons/'.$coupon->id)->assertSuccessful();

    expect(Coupon::query()->find($coupon->id))->toBeNull();
});
