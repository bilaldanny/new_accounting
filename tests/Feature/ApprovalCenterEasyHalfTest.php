<?php

use App\Models\CashCollection;
use App\Models\Contact;
use App\Models\CreditLimitRequest;
use App\Models\PriceList;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
});

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $attributes
 */
function makeApprovalTransaction(array $scope, string $type, array $attributes = []): Transaction
{
    return Transaction::query()->create(array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'invoice_no' => strtoupper($type).'-'.fake()->unique()->numerify('#####'),
        'type' => $type,
        'status' => 'pending',
        'payment_status' => 'due',
        'transaction_date' => now(),
        'final_amount' => 100,
        'total_item' => 1,
    ], $attributes));
}

test('a pending purchase return is listed and approved', function () {
    $scope = seedPurchaseScope();
    $return = makeApprovalTransaction($scope, Transaction::TYPE_PURCHASE_RETURN, ['contact_id' => $scope['contact_id']]);

    $this->getJson('/api/purchase-return-approvals')->assertSuccessful()
        ->assertJsonPath('data.data.0.id', $return->id);

    $this->postJson("/api/purchase-return-approvals/{$return->id}/approve")->assertSuccessful();

    $return->refresh();
    expect($return->status)->toBe('approved')->and($return->approved_by)->toBe(1);

    $this->postJson("/api/purchase-return-approvals/{$return->id}/approve")->assertUnprocessable();
});

test('approving a stock adjustment completes it', function () {
    $scope = seedPurchaseScope();
    $adjustment = makeApprovalTransaction($scope, Transaction::TYPE_ADJUSTMENT, ['adjustment_type' => 'normal']);

    $this->getJson('/api/stock-adjustment-approvals')->assertSuccessful()
        ->assertJsonPath('data.data.0.id', $adjustment->id);

    $this->postJson("/api/stock-adjustment-approvals/{$adjustment->id}/approve")->assertSuccessful();

    expect($adjustment->refresh()->status)->toBe('completed');
    $this->postJson("/api/stock-adjustment-approvals/{$adjustment->id}/approve")->assertUnprocessable();
});

test('approving a stock transfer dispatches it', function () {
    $scope = seedPurchaseScope();
    $otherBranch = DB::table('branches')->insertGetId([
        'code' => 'PURB002', 'company_id' => $scope['company_id'], 'name' => 'Second Branch',
        'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $transfer = makeApprovalTransaction($scope, Transaction::TYPE_TRANSFER, ['tobranch_id' => $otherBranch]);

    $this->getJson('/api/stock-transfer-approvals')->assertSuccessful()
        ->assertJsonPath('data.data.0.id', $transfer->id);

    $this->postJson("/api/stock-transfer-approvals/{$transfer->id}/approve")->assertSuccessful();

    expect($transfer->refresh()->status)->toBe('in_transit');
    $this->postJson("/api/stock-transfer-approvals/{$transfer->id}/approve")->assertUnprocessable();
});

test('approving a cash collection stamps the review without changing its status', function () {
    $scope = seedSellScope();
    $collection = CashCollection::query()->create([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'contact_id' => $scope['contact_id'],
        'reference' => 'CC-00001',
        'collected_on' => '2026-10-01',
        'amount' => 500,
        'status' => CashCollection::STATUS_PENDING,
    ]);

    $this->getJson('/api/cash-collection-approvals')->assertSuccessful()
        ->assertJsonPath('data.data.0.id', $collection->id);

    $this->postJson("/api/cash-collection-approvals/{$collection->id}/approve")->assertSuccessful();

    $collection->refresh();
    expect($collection->status)->toBe(CashCollection::STATUS_PENDING)
        ->and($collection->approved_by)->toBe(1)
        ->and($collection->approved_at)->not->toBeNull();
});

test('approving a price list sets it approved', function () {
    $scope = seedSellScope();
    $contact = Contact::query()->findOrFail($scope['contact_id']);
    $priceList = PriceList::query()->create([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'contact_id' => $contact->id,
        'date' => '2026-10-01',
        'discount' => 0,
        'status' => 'pending',
    ]);

    $this->getJson('/api/pricelist-approvals')->assertSuccessful()
        ->assertJsonPath('data.data.0.id', $priceList->id);

    $this->postJson("/api/pricelist-approvals/{$priceList->id}/approve")->assertSuccessful();

    expect($priceList->refresh()->status)->toBe('approved')->and($priceList->approved_by)->toBe(1);
    $this->postJson("/api/pricelist-approvals/{$priceList->id}/approve")->assertUnprocessable();
});

test('an approved credit limit request updates the contact limit', function () {
    $scope = seedSellScope();

    $this->postJson('/api/credit-limit-requests', [
        'contact_id' => $scope['contact_id'],
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'requested_limit' => 90000,
        'reason' => 'Large seasonal order',
    ])->assertSuccessful();

    $request = CreditLimitRequest::query()->firstOrFail();
    expect((float) $request->current_limit)->toBe(25000.0)->and($request->status)->toBe('pending');

    $this->getJson('/api/credit-limit-approvals')->assertSuccessful()
        ->assertJsonPath('data.data.0.requested_limit', 90000);

    $this->postJson("/api/credit-limit-approvals/{$request->id}/approve")->assertSuccessful();

    expect($request->refresh()->status)->toBe('approved')
        ->and((float) Contact::query()->findOrFail($scope['contact_id'])->credit_limit)->toBe(90000.0);
});

test('a rejected credit limit request leaves the contact limit alone', function () {
    $scope = seedSellScope();

    $this->postJson('/api/credit-limit-requests', [
        'contact_id' => $scope['contact_id'],
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'requested_limit' => 90000,
    ])->assertSuccessful();

    $request = CreditLimitRequest::query()->firstOrFail();

    $this->postJson("/api/credit-limit-approvals/{$request->id}/reject", ['reason' => 'Too risky'])->assertSuccessful();

    expect($request->refresh()->status)->toBe('rejected')
        ->and($request->rejection_reason)->toBe('Too risky')
        ->and((float) Contact::query()->findOrFail($scope['contact_id'])->credit_limit)->toBe(25000.0);

    $this->postJson("/api/credit-limit-approvals/{$request->id}/approve")->assertUnprocessable();
});

test('a user without the approval permission is forbidden', function () {
    $scope = seedPurchaseScope();
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => $scope['company_id'], 'is_active' => true]);
    Sanctum::actingAs(createStaffUserForRole($role, [
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
    ]));

    $this->getJson('/api/stock-adjustment-approvals')->assertForbidden();
    $this->getJson('/api/credit-limit-approvals')->assertForbidden();
    $this->postJson('/api/pricelist-approvals/1/approve')->assertForbidden();
});
