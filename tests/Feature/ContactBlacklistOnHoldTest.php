<?php

use App\Models\Contact;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
});

test('a sale to a blacklisted customer is blocked', function () {
    $scope = seedSellScope();
    Contact::query()->findOrFail($scope['contact_id'])->update(['is_blacklisted' => true]);

    $response = $this->postJson('/api/sells', validSellPayload($scope))->assertUnprocessable();

    $response->assertJsonValidationErrors(['contact_id']);
    expect($response->json('errors.contact_id.0'))->toContain('blacklisted');
    expect(Transaction::query()->sells()->count())->toBe(0);
});

test('a sale to a customer on hold is blocked', function () {
    $scope = seedSellScope();
    Contact::query()->findOrFail($scope['contact_id'])->update(['is_on_hold' => true]);

    $response = $this->postJson('/api/sells', validSellPayload($scope))->assertUnprocessable();

    $response->assertJsonValidationErrors(['contact_id']);
    expect($response->json('errors.contact_id.0'))->toContain('on hold');
    expect(Transaction::query()->sells()->count())->toBe(0);
});

test('a sale to a normal customer is allowed', function () {
    $scope = seedSellScope();

    $this->postJson('/api/sells', validSellPayload($scope))->assertSuccessful();

    expect(Transaction::query()->sells()->count())->toBe(1);
});

test('a purchase from a blacklisted supplier is blocked', function () {
    $scope = seedPurchaseScope();
    Contact::query()->findOrFail($scope['contact_id'])->update(['is_blacklisted' => true]);

    $response = $this->postJson('/api/purchases', validPurchasePayload($scope))->assertUnprocessable();

    $response->assertJsonValidationErrors(['contact_id']);
    expect(Transaction::query()->purchases()->count())->toBe(0);
});

test('a purchase from a supplier on hold is blocked', function () {
    $scope = seedPurchaseScope();
    Contact::query()->findOrFail($scope['contact_id'])->update(['is_on_hold' => true]);

    $response = $this->postJson('/api/purchases', validPurchasePayload($scope))->assertUnprocessable();

    $response->assertJsonValidationErrors(['contact_id']);
    expect(Transaction::query()->purchases()->count())->toBe(0);
});

test('a purchase from a normal supplier is allowed', function () {
    $scope = seedPurchaseScope();

    $this->postJson('/api/purchases', validPurchasePayload($scope))->assertSuccessful();

    expect(Transaction::query()->purchases()->count())->toBe(1);
});

test('tags are stored as an array on the customer', function () {
    $scope = seedSellScope();

    $response = $this->putJson("/api/customers/{$scope['contact_id']}", array_merge(
        Contact::query()->findOrFail($scope['contact_id'])->only([
            'company_id', 'branch_id', 'business_name', 'first_name', 'mobile', 'address', 'ntn_number',
        ]),
        ['tags' => ['VIP', 'Wholesale']],
    ))->assertSuccessful();

    expect(Contact::query()->findOrFail($scope['contact_id'])->tags)->toBe(['VIP', 'Wholesale']);
});
