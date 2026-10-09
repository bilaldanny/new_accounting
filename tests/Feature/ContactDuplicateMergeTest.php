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

test('contacts sharing a mobile number are grouped as duplicates', function () {
    $scope = seedSellScope();

    $duplicate = Contact::query()->create([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'business_name' => 'Acme Retail Duplicate',
        'first_name' => 'Sara',
        'mobile' => '03007654321',
        'address' => 'Another address',
        'code' => 'CU-00099',
        'user_type' => 'customer',
        'type' => 'local',
        'ntn_number' => '1112223',
        'active' => true,
    ]);

    $response = $this->getJson('/api/contacts/duplicates')->assertSuccessful();

    $groups = collect($response->json('data'));
    $mobileGroup = $groups->firstWhere('match_field', 'mobile');

    expect($mobileGroup)->not->toBeNull();
    expect(collect($mobileGroup['contacts'])->pluck('id')->sort()->values()->all())
        ->toBe([$scope['contact_id'], $duplicate->id]);
});

test('merging reassigns transactions and soft-deletes the duplicate', function () {
    $scope = seedSellScope();
    $keep = Contact::query()->findOrFail($scope['contact_id']);

    $duplicate = Contact::query()->create([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'business_name' => 'Acme Retail Duplicate',
        'first_name' => 'Sara',
        'mobile' => '03009999999',
        'email' => 'acme@example.test',
        'address' => 'Another address',
        'code' => 'CU-00099',
        'user_type' => 'customer',
        'type' => 'local',
        'ntn_number' => '1112223',
        'active' => true,
    ]);

    $sell = createSellRecord($scope, ['contact_id' => $duplicate->id, 'invoice_no' => 'DUP-00001']);

    $response = $this->postJson('/api/contacts/duplicates/merge', [
        'keep_id' => $keep->id,
        'duplicate_id' => $duplicate->id,
    ])->assertSuccessful();

    expect($response->json('message'))->toBe('Successfully Merged');
    expect(Transaction::query()->findOrFail($sell->id)->contact_id)->toBe($keep->id);
    expect(Contact::query()->find($duplicate->id))->toBeNull();
    expect(Contact::onlyTrashed()->find($duplicate->id))->not->toBeNull();
});

test('merging a contact into itself is rejected', function () {
    $scope = seedSellScope();

    $this->postJson('/api/contacts/duplicates/merge', [
        'keep_id' => $scope['contact_id'],
        'duplicate_id' => $scope['contact_id'],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['duplicate_id']);
});
