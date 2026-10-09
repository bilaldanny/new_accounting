<?php

use App\Models\CompanySetting;
use App\Models\PurchaseRequisition;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Purchase Requisition: the internal "what do we need" request before a real Purchase Order exists.
 * A standalone table (no ledger, no `transactions` row) with its own approval flow and, once approved,
 * a one-way conversion into a real Purchase Order (PurchaseController, `purchase_requisition_id`).
 */

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function prqPayload(array $scope, array $overrides = []): array
{
    return array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'requisition_date' => '2026-09-20',
        'note' => 'Need stock for next week',
        'lines' => [
            [
                'product_id' => $scope['product_id'],
                'variation_id' => $scope['variation_id'],
                'unit_id' => $scope['unit_id'],
                'requested_quantity' => 10,
                'note' => 'Urgent',
            ],
        ],
    ], $overrides);
}

function prqSetting(int $companyId, bool $on): void
{
    $setting = CompanySetting::query()->where('company_id', $companyId)->first()
        ?? CompanySetting::createCompanySettings($companyId, 'Purchase Test Company');

    $setting->forceFill(['purchase_requisition_approval' => $on])->save();
}

/**
 * @param  list<string>  $paths
 */
function prqUserWith(array $scope, array $paths): User
{
    $role = Role::query()->create(['name' => 'requisitioner'.uniqid(), 'company_id' => $scope['company_id'], 'is_active' => true]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path, ltrim(str_replace('/', '', $path), '/').uniqid());
    }

    return createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']]);
}

// --- create / edit / delete ------------------------------------------------------------------------

test('a requisition can be created, viewed and edited while pending', function () {
    $scope = seedPurchaseScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/purchase-requisitions', prqPayload($scope))->assertSuccessful();
    $requisition = PurchaseRequisition::query()->latest('id')->firstOrFail();

    expect($requisition->requisition_no)->toStartWith('PR-')
        ->and($requisition->status)->toBe('pending')
        ->and($requisition->requested_by)->toBe(1)
        ->and($requisition->lines()->count())->toBe(1);

    $show = $this->getJson("/api/purchase-requisitions/{$requisition->id}")->assertSuccessful();
    expect($show->json('requisition_no'))->toBe($requisition->requisition_no)
        ->and($show->json('lines.0.requested_quantity'))->toEqual(10)
        ->and($show->json('is_editable'))->toBeTrue();

    $this->putJson("/api/purchase-requisitions/{$requisition->id}", prqPayload($scope, ['note' => 'Updated note']))
        ->assertSuccessful();

    expect($requisition->refresh()->note)->toBe('Updated note');
});

test('a requisition needs at least one line', function () {
    $scope = seedPurchaseScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/purchase-requisitions', prqPayload($scope, ['lines' => []]))
        ->assertUnprocessable()->assertJsonValidationErrors(['lines']);
});

test('a requisition supports multiple lines', function () {
    $scope = seedPurchaseScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/purchase-requisitions', prqPayload($scope, [
        'lines' => [
            ['product_id' => $scope['product_id'], 'variation_id' => $scope['variation_id'], 'unit_id' => $scope['unit_id'], 'requested_quantity' => 5],
            ['product_id' => $scope['product_id'], 'variation_id' => $scope['variation_id'], 'unit_id' => $scope['unit_id'], 'requested_quantity' => 8, 'note' => 'Second line'],
        ],
    ]))->assertSuccessful();

    $requisition = PurchaseRequisition::query()->latest('id')->firstOrFail();
    expect($requisition->lines()->count())->toBe(2)
        ->and((float) $requisition->lines()->orderBy('id')->get()->sum('requested_quantity'))->toBe(13.0);
});

test('a pending requisition can be deleted, an approved and a converted one cannot', function () {
    $scope = seedPurchaseScope();
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/purchase-requisitions', prqPayload($scope))->assertSuccessful();
    $pending = PurchaseRequisition::query()->latest('id')->firstOrFail();

    $this->deleteJson("/api/purchase-requisitions/{$pending->id}")->assertSuccessful();
    expect(PurchaseRequisition::query()->find($pending->id))->toBeNull();

    $this->postJson('/api/purchase-requisitions', prqPayload($scope))->assertSuccessful();
    $approved = PurchaseRequisition::query()->latest('id')->firstOrFail();
    $this->postJson("/api/purchase-requisition-approvals/{$approved->id}/approve")->assertSuccessful();

    $this->deleteJson("/api/purchase-requisitions/{$approved->id}")->assertUnprocessable();
    expect($approved->refresh()->exists)->toBeTrue();
});

test('an approved requisition cannot be edited', function () {
    $scope = seedPurchaseScope();
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/purchase-requisitions', prqPayload($scope))->assertSuccessful();
    $requisition = PurchaseRequisition::query()->latest('id')->firstOrFail();
    $this->postJson("/api/purchase-requisition-approvals/{$requisition->id}/approve")->assertSuccessful();

    $this->putJson("/api/purchase-requisitions/{$requisition->id}", prqPayload($scope, ['note' => 'Sneaky edit']))
        ->assertUnprocessable();

    expect($requisition->refresh()->note)->not->toBe('Sneaky edit');
});

// --- approval flow, company toggle both ways --------------------------------------------------------

test('a requisition is pending by default (toggle off) and approving it records who and when', function () {
    $scope = seedPurchaseScope();
    prqSetting($scope['company_id'], false);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/purchase-requisitions', prqPayload($scope))->assertSuccessful();
    $requisition = PurchaseRequisition::query()->latest('id')->firstOrFail();
    expect($requisition->status)->toBe('pending');

    $this->postJson("/api/purchase-requisition-approvals/{$requisition->id}/approve")->assertSuccessful();

    expect($requisition->refresh()->status)->toBe('approved')
        ->and($requisition->approved_by)->toBe(1)
        ->and($requisition->approved_at)->not->toBeNull();
});

test('the company toggle posts a new requisition as approved straight away', function () {
    $scope = seedPurchaseScope();
    prqSetting($scope['company_id'], true);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/purchase-requisitions', prqPayload($scope))->assertSuccessful();

    expect(PurchaseRequisition::query()->latest('id')->firstOrFail()->status)->toBe('approved');
});

test('rejecting a pending requisition keeps the reason and only a pending one can be approved or rejected', function () {
    $scope = seedPurchaseScope();
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/purchase-requisitions', prqPayload($scope))->assertSuccessful();
    $requisition = PurchaseRequisition::query()->latest('id')->firstOrFail();

    $this->postJson("/api/purchase-requisition-approvals/{$requisition->id}/reject", ['reason' => 'Not needed this month'])
        ->assertSuccessful();

    expect($requisition->refresh()->status)->toBe('rejected')
        ->and($requisition->note)->toContain('Not needed this month')
        ->and($requisition->rejected_by)->toBe(1);

    $this->postJson("/api/purchase-requisition-approvals/{$requisition->id}/approve")->assertUnprocessable();
    $this->postJson("/api/purchase-requisition-approvals/{$requisition->id}/reject")->assertUnprocessable();
});

// --- rejected -> edit -> resubmit --------------------------------------------------------------------

test('editing a rejected requisition resubmits it as pending when the toggle is off', function () {
    $scope = seedPurchaseScope();
    prqSetting($scope['company_id'], false);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/purchase-requisitions', prqPayload($scope))->assertSuccessful();
    $requisition = PurchaseRequisition::query()->latest('id')->firstOrFail();
    $originalNo = $requisition->requisition_no;
    $this->postJson("/api/purchase-requisition-approvals/{$requisition->id}/reject", ['reason' => 'wrong quantity'])->assertSuccessful();

    $this->putJson("/api/purchase-requisitions/{$requisition->id}", prqPayload($scope, ['lines' => [
        ['product_id' => $scope['product_id'], 'variation_id' => $scope['variation_id'], 'unit_id' => $scope['unit_id'], 'requested_quantity' => 20],
    ]]))->assertSuccessful();

    $requisition->refresh();
    expect($requisition->status)->toBe('pending')
        ->and($requisition->requisition_no)->toBe($originalNo)
        ->and($requisition->rejected_by)->toBeNull()
        ->and($requisition->rejected_at)->toBeNull()
        ->and((float) $requisition->lines()->sole()->requested_quantity)->toBe(20.0)
        ->and(PurchaseRequisition::query()->count())->toBe(1);
});

test('editing a rejected requisition resubmits it as approved when the toggle is on', function () {
    $scope = seedPurchaseScope();
    prqSetting($scope['company_id'], false);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/purchase-requisitions', prqPayload($scope))->assertSuccessful();
    $requisition = PurchaseRequisition::query()->latest('id')->firstOrFail();
    $this->postJson("/api/purchase-requisition-approvals/{$requisition->id}/reject")->assertSuccessful();

    prqSetting($scope['company_id'], true);
    $this->putJson("/api/purchase-requisitions/{$requisition->id}", prqPayload($scope))->assertSuccessful();

    expect($requisition->refresh()->status)->toBe('approved');
});

// --- PO conversion -----------------------------------------------------------------------------------

test('only approved, not-yet-converted requisitions are eligible for conversion', function () {
    $scope = seedPurchaseScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/purchase-requisitions', prqPayload($scope))->assertSuccessful();
    $pending = PurchaseRequisition::query()->latest('id')->firstOrFail();

    $this->postJson('/api/purchase-requisitions', prqPayload($scope))->assertSuccessful();
    $approved = PurchaseRequisition::query()->latest('id')->firstOrFail();
    $this->postJson("/api/purchase-requisition-approvals/{$approved->id}/approve")->assertSuccessful();

    $eligible = $this->getJson('/api/purchase-requisitions/eligible?company_id='.$scope['company_id'].'&branch_id='.$scope['branch_id'])
        ->assertSuccessful()->json();

    expect(collect($eligible)->pluck('id')->all())->toBe([$approved->id])
        ->and(collect($eligible)->pluck('id')->all())->not->toContain($pending->id);
});

test('the lines endpoint prefills product, variation, unit and quantity for the PO form', function () {
    $scope = seedPurchaseScope();
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/purchase-requisitions', prqPayload($scope))->assertSuccessful();
    $requisition = PurchaseRequisition::query()->latest('id')->firstOrFail();

    $response = $this->getJson("/api/purchase-requisitions/{$requisition->id}/lines")->assertSuccessful();

    expect($response->json('lines.0.product_id'))->toBe($scope['product_id'])
        ->and($response->json('lines.0.variation_id'))->toBe($scope['variation_id'])
        ->and($response->json('lines.0.unit_id'))->toBe($scope['unit_id'])
        ->and($response->json('lines.0.quantity'))->toEqual(10)
        ->and($response->json('lines.0'))->not->toHaveKey('purchase_rate');
});

test('saving a Purchase Order from an eligible requisition converts it and sets the purchase order id', function () {
    $scope = seedPurchaseScope();
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/purchase-requisitions', prqPayload($scope))->assertSuccessful();
    $requisition = PurchaseRequisition::query()->latest('id')->firstOrFail();
    $this->postJson("/api/purchase-requisition-approvals/{$requisition->id}/approve")->assertSuccessful();

    $this->postJson('/api/purchases', validPurchasePayload($scope, ['purchase_requisition_id' => $requisition->id]))
        ->assertSuccessful();
    $purchase = Transaction::query()->purchases()->latest('id')->firstOrFail();

    expect($requisition->refresh()->status)->toBe('converted')
        ->and($requisition->purchase_order_id)->toBe($purchase->id);
});

test('a requisition can only be converted once', function () {
    $scope = seedPurchaseScope();
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/purchase-requisitions', prqPayload($scope))->assertSuccessful();
    $requisition = PurchaseRequisition::query()->latest('id')->firstOrFail();
    $this->postJson("/api/purchase-requisition-approvals/{$requisition->id}/approve")->assertSuccessful();

    $this->postJson('/api/purchases', validPurchasePayload($scope, ['purchase_requisition_id' => $requisition->id]))->assertSuccessful();
    $firstPurchaseCount = Transaction::query()->purchases()->count();

    $this->postJson('/api/purchases', validPurchasePayload($scope, ['purchase_requisition_id' => $requisition->id]))
        ->assertUnprocessable();

    // the second purchase order was rolled back with the failed conversion, not left dangling
    expect(Transaction::query()->purchases()->count())->toBe($firstPurchaseCount);
});

test('a purchase order created without a requisition never touches any requisition', function () {
    $scope = seedPurchaseScope();
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/purchase-requisitions', prqPayload($scope))->assertSuccessful();
    $requisition = PurchaseRequisition::query()->latest('id')->firstOrFail();
    $this->postJson("/api/purchase-requisition-approvals/{$requisition->id}/approve")->assertSuccessful();

    $this->postJson('/api/purchases', validPurchasePayload($scope))->assertSuccessful();

    expect($requisition->refresh()->status)->toBe('approved')
        ->and($requisition->purchase_order_id)->toBeNull();
});

// --- permissions ---------------------------------------------------------------------------------------

test('creating and listing need their own permissions', function () {
    $scope = seedPurchaseScope();

    Sanctum::actingAs(prqUserWith($scope, []));
    $this->postJson('/api/purchase-requisitions', prqPayload($scope))->assertForbidden();
    $this->getJson('/api/purchase-requisitions')->assertForbidden();

    Sanctum::actingAs(prqUserWith($scope, ['/purchaserequisition', '/purchaserequisition/add']));
    $this->postJson('/api/purchase-requisitions', prqPayload($scope))->assertSuccessful();
    $this->getJson('/api/purchase-requisitions')->assertSuccessful();
});

test('approving and rejecting need their own permissions, and the approval list needs its own too', function () {
    $scope = seedPurchaseScope();
    Sanctum::actingAs(prqUserWith($scope, ['/purchaserequisition', '/purchaserequisition/add']));
    $this->postJson('/api/purchase-requisitions', prqPayload($scope))->assertSuccessful();
    $requisition = PurchaseRequisition::query()->latest('id')->firstOrFail();

    Sanctum::actingAs(prqUserWith($scope, []));
    $this->getJson('/api/purchase-requisition-approvals')->assertForbidden();
    $this->postJson("/api/purchase-requisition-approvals/{$requisition->id}/approve")->assertForbidden();

    $rejecter = prqUserWith($scope, ['/purchaserequisition/:id/reject']);
    Sanctum::actingAs($rejecter);
    $this->postJson("/api/purchase-requisition-approvals/{$requisition->id}/approve")->assertForbidden();
    $this->postJson("/api/purchase-requisition-approvals/{$requisition->id}/reject")->assertSuccessful();
});

// --- branch scoping -------------------------------------------------------------------------------------

test('a branch user only sees their own branch\'s requisitions', function () {
    $scope = seedPurchaseScope();
    $otherBranchId = DB::table('branches')->insertGetId([
        'code' => 'PURB002', 'company_id' => $scope['company_id'], 'name' => 'Other Branch', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/purchase-requisitions', prqPayload($scope))->assertSuccessful();
    $mine = PurchaseRequisition::query()->latest('id')->firstOrFail();
    $this->postJson('/api/purchase-requisitions', prqPayload($scope, ['branch_id' => $otherBranchId]))->assertSuccessful();
    $theirs = PurchaseRequisition::query()->latest('id')->firstOrFail();

    $branchUser = prqUserWith($scope, ['/purchaserequisition']);
    Sanctum::actingAs($branchUser);

    $ids = collect($this->getJson('/api/purchase-requisitions')->assertSuccessful()->json('data.data'))->pluck('id')->all();
    expect($ids)->toContain($mine->id)->not->toContain($theirs->id);

    $this->getJson("/api/purchase-requisitions/{$theirs->id}")->assertNotFound();
});
