<?php

use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * The "Upcoming cheques" dashboard cards: post-dated cheque payments (`payments.method = 'cheque'` with a
 * `cheque_date`) not yet in the past, split into given (against a purchase) and received (against a sale).
 */
beforeEach(function () {
    Carbon::setTestNow('2026-09-15 12:00:00');
    Cache::flush();
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $query
 */
function dscGet(array $query = []): TestResponse
{
    return test()->getJson('/api/dashboard/cheques'.($query === [] ? '' : '?'.http_build_query($query)));
}

/**
 * @param  list<string>  $paths
 */
function dscUser(array $scope, array $paths): User
{
    $role = Role::query()->create(['name' => 'cheqadmin'.uniqid(), 'company_id' => $scope['company_id'], 'is_active' => true]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path, ltrim(str_replace('/', '', $path), '/').uniqid());
    }

    return createStaffUserForRole($role, ['company_id' => $scope['company_id']]);
}

test('given cheques are the purchase-side ones due, received are the sell-side ones, both soonest first', function () {
    $scope = trpScope('A');
    $purchase = trpDoc($scope, 'purchaseorder');
    $sale = trpDoc($scope, 'sell');

    trpPayment($scope, $purchase, 500, '2026-09-01', 'cheque', ['cheque_number' => 'CHQ-1', 'cheque_date' => '2026-09-20']);
    trpPayment($scope, $purchase, 300, '2026-09-01', 'cheque', ['cheque_number' => 'CHQ-2', 'cheque_date' => '2026-09-18']);
    trpPayment($scope, $sale, 200, '2026-09-01', 'cheque', ['cheque_number' => 'CHQ-3', 'cheque_date' => '2026-09-25']);
    // a cash payment and a cheque payment with no date are never cheques due
    trpPayment($scope, $purchase, 100, '2026-09-01', 'cash');
    trpPayment($scope, $purchase, 50, '2026-09-01', 'cheque', ['cheque_number' => 'CHQ-4']);
    // a cheque already in the past is not "upcoming"
    trpPayment($scope, $purchase, 999, '2026-08-01', 'cheque', ['cheque_number' => 'CHQ-OLD', 'cheque_date' => '2026-09-01']);

    Sanctum::actingAs(dscUser($scope, ['/purchase/payment', '/sell/payment']));

    $data = dscGet()->assertSuccessful()->json('data');

    expect($data['given']['count'])->toBe(2)
        ->and(collect($data['given']['items'])->pluck('cheque_number')->all())->toBe(['CHQ-2', 'CHQ-1'])
        ->and($data['received']['count'])->toBe(1)
        ->and($data['received']['items'][0]['cheque_number'])->toBe('CHQ-3');
});

test('a cheque due today is still upcoming, not past', function () {
    $scope = trpScope('A');
    $purchase = trpDoc($scope, 'purchaseorder');
    trpPayment($scope, $purchase, 500, '2026-09-01', 'cheque', ['cheque_number' => 'CHQ-TODAY', 'cheque_date' => '2026-09-15']);

    Sanctum::actingAs(dscUser($scope, ['/purchase/payment']));

    expect(dscGet()->json('data.given.count'))->toBe(1);
});

test('each part needs its own permission, and neither is sent without it', function () {
    $scope = trpScope('A');
    $purchase = trpDoc($scope, 'purchaseorder');
    trpPayment($scope, $purchase, 500, '2026-09-01', 'cheque', ['cheque_number' => 'CHQ-1', 'cheque_date' => '2026-09-20']);

    Sanctum::actingAs(dscUser($scope, []));
    expect(dscGet()->json('data'))->toBe([]);

    Sanctum::actingAs(dscUser($scope, ['/purchase/payment']));
    $data = dscGet()->json('data');
    expect($data)->toHaveKey('given')->not->toHaveKey('received');
});

test('a company never sees another company\'s cheques', function () {
    $mine = trpScope('A');
    $theirs = trpScope('B');
    $minePurchase = trpDoc($mine, 'purchaseorder');
    $theirPurchase = trpDoc($theirs, 'purchaseorder');
    trpPayment($mine, $minePurchase, 500, '2026-09-01', 'cheque', ['cheque_number' => 'MINE', 'cheque_date' => '2026-09-20']);
    trpPayment($theirs, $theirPurchase, 500, '2026-09-01', 'cheque', ['cheque_number' => 'THEIRS', 'cheque_date' => '2026-09-20']);

    Sanctum::actingAs(dscUser($mine, ['/purchase/payment']));

    $data = dscGet()->json('data');
    expect($data['given']['count'])->toBe(1)
        ->and($data['given']['items'][0]['cheque_number'])->toBe('MINE');
});
