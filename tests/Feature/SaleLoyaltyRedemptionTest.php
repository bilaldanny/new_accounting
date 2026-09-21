<?php

use App\Models\Contact;
use App\Models\Discount;
use App\Models\DocumentSetting;
use App\Models\LoyaltyPointEntry;
use App\Models\LoyaltySetting;
use App\Models\Role;
use App\Models\SaleLoyaltyRedemption;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LoyaltyPoints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Loyalty points redeemed at checkout: the sell save takes the value off the sale, the ledger spends the points,
 * and a delete, a draft, a return and a restore keep the two in step. A standard sale here is 2 x 120 = 240 of
 * goods + 40 shipping = 280; a point is worth 1 and 100 spent earns 1 unless a test says otherwise.
 */
function lrdScope(int $points = 50, array $settings = []): array
{
    $scope = seedSellScope();
    LoyaltySetting::query()->updateOrCreate(['company_id' => $scope['company_id']], array_merge([
        'is_enabled' => true, 'amount_per_point' => 100, 'point_value' => 1, 'min_redeem_points' => 0,
    ], $settings));
    DocumentSetting::saveFor($scope['company_id'], 'checkout', ['discount_codes_enabled' => true]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    if ($points > 0) {
        app(LoyaltyPoints::class)->adjust(Contact::query()->findOrFail($scope['customer_id']), $points, 'Opening points');
    }

    return $scope;
}

/**
 * The sale body with $points redeemed: their value comes off the 280.
 *
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function lrdBody(array $scope, int $points, float $value = 1.0, array $overrides = []): array
{
    return validSellPayload($scope, array_merge([
        'loyalty_points' => $points,
        'loyalty_discount_amount' => $points * $value,
        'final_amount' => 280 - $points * $value,
    ], $overrides));
}

function lrdBalance(array $scope, ?int $contactId = null): int
{
    return (int) LoyaltyPointEntry::query()->where('contact_id', $contactId ?? $scope['customer_id'])->sum('points');
}

function lrdSale(): Transaction
{
    return Transaction::query()->sells()->latest('id')->firstOrFail();
}

/**
 * Makes the sale returnable (issued) and returns $quantity of its two units, returning the return's id.
 *
 * @param  array<string, mixed>  $scope
 */
function lrdReturn(array $scope, Transaction $sale, int $quantity): int
{
    $sale->update(['status' => 'issue']);
    $sale->selllines()->update(['quantity_issue' => 2]);

    test()->postJson('/api/sell-returns', validSellReturnPayload($scope, $sale, [
        'selllines' => [['id' => $sale->selllines()->first()->id, 'quantity_returned' => $quantity]],
    ]))->assertSuccessful();

    return (int) Transaction::query()->sellReturns()->where('parent_id', $sale->id)->value('id');
}

// --- redeeming at checkout -----------------------------------------------------------------------

test('points are redeemed on a finished sale: the value comes off, the points are spent and the record is kept', function () {
    $scope = lrdScope(50);

    $this->postJson('/api/sells', lrdBody($scope, 50))->assertSuccessful();

    $sale = lrdSale();
    $redeem = LoyaltyPointEntry::query()->where('type', 'redeem')->firstOrFail();

    expect((float) $sale->final_amount)->toBe(230.0)
        ->and($redeem->points)->toBe(-50)
        ->and((float) $redeem->amount)->toBe(50.0)
        ->and($redeem->transaction_id)->toBe($sale->id)
        ->and($redeem->contact_id)->toBe($scope['customer_id'])
        ->and(SaleLoyaltyRedemption::query()->where('transaction_id', $sale->id)->first())->points->toBe(50)->amount->toBe(50.0)
        // 50 held - 50 spent + 2 earned on the 230 that was paid
        ->and(lrdBalance($scope))->toBe(2);

    $show = $this->getJson('/api/sells/'.$sale->id)->assertSuccessful();

    expect($show->json('loyalty_points'))->toBe(50)
        ->and($show->json('loyalty_discount_amount'))->toBe(50)
        ->and($show->json('loyalty_points_spent'))->toBe(50)
        ->and($show->json('loyalty_saved_contact_id'))->toBe($scope['customer_id']);
});

test('the value of a point is the company\'s: half a currency unit each makes 40 points worth 20', function () {
    $scope = lrdScope(100, ['point_value' => 0.5]);

    $this->postJson('/api/sells', lrdBody($scope, 40, 0.5))->assertSuccessful();

    expect((float) lrdSale()->final_amount)->toBe(260.0)
        ->and((float) LoyaltyPointEntry::query()->where('type', 'redeem')->value('amount'))->toBe(20.0);
});

test('points are earned on what was paid after the points came off', function () {
    $scope = lrdScope(100);

    $this->postJson('/api/sells', lrdBody($scope, 100))->assertSuccessful();

    // 280 - 100 = 180 earns 1 point (not the 2 that 280 would)
    expect(LoyaltyPointEntry::query()->where('type', 'earn')->value('points'))->toBe(1)
        ->and(lrdBalance($scope))->toBe(1);
});

test('a sale that redeems nothing is unchanged', function () {
    $scope = lrdScope(50);

    $this->postJson('/api/sells', validSellPayload($scope))->assertSuccessful();
    $this->postJson('/api/sells', validSellPayload($scope, ['loyalty_points' => 0, 'loyalty_discount_amount' => 0]))->assertSuccessful();

    expect(LoyaltyPointEntry::query()->where('type', 'redeem')->count())->toBe(0)
        ->and(SaleLoyaltyRedemption::query()->count())->toBe(0);
});

test('a redemption that cannot be honoured is refused with a reason and nothing is saved', function (string $case, string $field) {
    $scope = lrdScope(50, $case === 'below minimum' ? ['min_redeem_points' => 20] : ($case === 'programme off' ? ['is_enabled' => false] : []));
    $body = match ($case) {
        'more than held' => lrdBody($scope, 80),
        'below minimum' => lrdBody($scope, 10),
        'programme off' => lrdBody($scope, 20),
        'wrong amount' => lrdBody($scope, 30, 1.0, ['loyalty_discount_amount' => 25, 'final_amount' => 255]),
        'a draft' => lrdBody($scope, 20, 1.0, ['status' => 'draft']),
        'a quotation' => lrdBody($scope, 20, 1.0, ['status' => 'quotation']),
        'text' => lrdBody($scope, 20, 1.0, ['loyalty_points' => 'abc']),
        'a fraction' => lrdBody($scope, 20, 1.0, ['loyalty_points' => 2.5]),
        'negative' => lrdBody($scope, 20, 1.0, ['loyalty_points' => -5]),
    };

    $this->postJson('/api/sells', $body)->assertUnprocessable()->assertJsonValidationErrors([$field]);

    expect(Transaction::query()->sells()->count())->toBe(0)
        ->and(lrdBalance($scope))->toBe(50)
        ->and(SaleLoyaltyRedemption::query()->count())->toBe(0);
})->with([
    'more than held' => ['more than held', 'loyalty_points'],
    'below minimum' => ['below minimum', 'loyalty_points'],
    'programme off' => ['programme off', 'loyalty_points'],
    'wrong amount' => ['wrong amount', 'loyalty_discount_amount'],
    'a draft' => ['a draft', 'loyalty_points'],
    'a quotation' => ['a quotation', 'loyalty_points'],
    'text' => ['text', 'loyalty_points'],
    'a fraction' => ['a fraction', 'loyalty_points'],
    'negative' => ['negative', 'loyalty_points'],
]);

test('points worth more than the goods are refused, and a cent of difference is tolerated', function () {
    $scope = lrdScope(500);

    $this->postJson('/api/sells', lrdBody($scope, 260))->assertUnprocessable()->assertJsonValidationErrors(['loyalty_points']);

    $this->postJson('/api/sells', lrdBody($scope, 30, 1.0, ['loyalty_discount_amount' => 30.01]))->assertSuccessful();
    expect(SaleLoyaltyRedemption::query()->value('amount'))->toBe(30.0);
});

test('a coupon and points share the goods: together they cannot be worth more than the sale', function () {
    $scope = lrdScope(500);
    Discount::query()->create([
        'company_id' => $scope['company_id'], 'name' => 'Big', 'code' => 'BIG', 'discount_type' => 'fixed', 'value' => 200, 'is_active' => true,
    ]);

    $this->postJson('/api/sells', lrdBody($scope, 60, 1.0, ['discount_code' => 'BIG', 'coupon_discount_amount' => 200, 'final_amount' => 20]))
        ->assertUnprocessable()->assertJsonValidationErrors(['loyalty_points']);
});

// --- editing -------------------------------------------------------------------------------------

test('editing a sale that keeps its points changes nothing, and the points can be raised, lowered and removed', function () {
    $scope = lrdScope(80);
    $this->postJson('/api/sells', lrdBody($scope, 50))->assertSuccessful();
    $sale = lrdSale();
    $entries = LoyaltyPointEntry::query()->count();

    $this->putJson('/api/sells/'.$sale->id, lrdBody($scope, 50, 1.0, ['shipping_charges' => 40]))->assertSuccessful();
    expect(LoyaltyPointEntry::query()->where('type', 'redeem')->count())->toBe(1);

    // 80 held, 30 left after the 50; up to 80 can be asked for since this sale's own 50 are the customer's again
    $this->putJson('/api/sells/'.$sale->id, lrdBody($scope, 80))->assertSuccessful();
    expect(LoyaltyPointEntry::query()->where('type', 'redeem')->sum('points'))->toBe(-80);

    $this->putJson('/api/sells/'.$sale->id, lrdBody($scope, 20))->assertSuccessful();
    expect(LoyaltyPointEntry::query()->where('type', 'redeem')->sum('points'))->toBe(-20)
        ->and((float) $sale->refresh()->final_amount)->toBe(260.0)
        ->and(SaleLoyaltyRedemption::query()->value('points'))->toBe(20);

    $this->putJson('/api/sells/'.$sale->id, validSellPayload($scope, ['loyalty_points' => 0, 'loyalty_discount_amount' => 0]))->assertSuccessful();
    expect(LoyaltyPointEntry::query()->where('type', 'redeem')->sum('points'))->toBe(0)
        ->and(SaleLoyaltyRedemption::query()->value('points'))->toBe(0)
        ->and($entries)->toBeGreaterThan(0);
});

test('an edit that leaves the redemption fields out keeps the redemption', function () {
    $scope = lrdScope(80);
    $this->postJson('/api/sells', lrdBody($scope, 50))->assertSuccessful();
    $sale = lrdSale();

    $body = lrdBody($scope, 50);
    unset($body['loyalty_points'], $body['loyalty_discount_amount']);
    $this->putJson('/api/sells/'.$sale->id, $body)->assertSuccessful();

    expect(SaleLoyaltyRedemption::query()->value('points'))->toBe(50)
        ->and(LoyaltyPointEntry::query()->where('type', 'redeem')->sum('points'))->toBe(-50);
});

test('editing keeps a redemption made when the programme was on, even if it is switched off since', function () {
    $scope = lrdScope(80);
    $this->postJson('/api/sells', lrdBody($scope, 50))->assertSuccessful();
    LoyaltySetting::query()->where('company_id', $scope['company_id'])->update(['is_enabled' => false, 'point_value' => 2]);

    $this->putJson('/api/sells/'.lrdSale()->id, lrdBody($scope, 50))->assertSuccessful();

    expect(SaleLoyaltyRedemption::query()->value('amount'))->toBe(50.0);

    // but new points cannot be added while it is off
    $this->putJson('/api/sells/'.lrdSale()->id, lrdBody($scope, 60))->assertUnprocessable()->assertJsonValidationErrors(['loyalty_points']);
});

test('changing the customer of the sale gives the first customer their points back and takes the new customer\'s', function () {
    $scope = lrdScope(80);
    $other = createExportCustomer($scope)->id;
    app(LoyaltyPoints::class)->adjust(Contact::query()->findOrFail($other), 60, 'Opening points');
    $this->postJson('/api/sells', lrdBody($scope, 50))->assertSuccessful();

    $this->putJson('/api/sells/'.lrdSale()->id, lrdBody($scope, 50, 1.0, ['contact_id' => $other]))->assertSuccessful();

    expect(lrdBalance($scope))->toBe(80)
        ->and(lrdBalance($scope, $other))->toBe(60 - 50 + 2)
        ->and(SaleLoyaltyRedemption::query()->value('contact_id'))->toBe($other);
});

// --- delete, draft, restore, return --------------------------------------------------------------

test('deleting the sale gives the points back and restoring it spends them again, however many times', function () {
    $scope = lrdScope(50);
    $this->postJson('/api/sells', lrdBody($scope, 50))->assertSuccessful();
    $sale = lrdSale();

    foreach (range(1, 3) as $ignored) {
        $this->deleteJson('/api/sells/'.$sale->id)->assertSuccessful();
        expect(lrdBalance($scope))->toBe(50);

        $this->postJson('/api/sells/restore_records', [$sale->id])->assertSuccessful();
        expect(lrdBalance($scope))->toBe(2);
    }

    expect(SaleLoyaltyRedemption::query()->count())->toBe(1)
        ->and(LoyaltyPointEntry::query()->where('type', 'redeem')->sum('points'))->toBe(-50);
});

test('a sale cannot be restored when its customer no longer has the points, and nothing changes', function () {
    $scope = lrdScope(50);
    $this->postJson('/api/sells', lrdBody($scope, 50))->assertSuccessful();
    $sale = lrdSale();
    $this->deleteJson('/api/sells/'.$sale->id)->assertSuccessful();
    app(LoyaltyPoints::class)->redeem(Contact::query()->findOrFail($scope['customer_id']), 40, 'Spent elsewhere');

    $this->postJson('/api/sells/restore_records', [$sale->id])->assertUnprocessable()->assertJsonValidationErrors(['loyalty_points']);

    expect(Transaction::query()->sells()->find($sale->id))->toBeNull()
        ->and(lrdBalance($scope))->toBe(10);
});

test('turning the sale into a draft gives the points back and finishing it again spends them', function () {
    $scope = lrdScope(50);
    $this->postJson('/api/sells', lrdBody($scope, 50))->assertSuccessful();
    $sale = lrdSale();

    $this->postJson('/api/sells/statusupdate', ['ids' => [$sale->id], 'status' => 'draft'])->assertSuccessful();
    expect(lrdBalance($scope))->toBe(50);

    $this->postJson('/api/sells/statusupdate', ['ids' => [$sale->id], 'status' => 'final'])->assertSuccessful();
    expect(lrdBalance($scope))->toBe(2);
});

test('a return gives back its share of the points, and deleting the return spends them again', function () {
    $scope = lrdScope(50);
    $this->postJson('/api/sells', lrdBody($scope, 50))->assertSuccessful();
    $sale = lrdSale();
    $spent = fn (): int => -(int) LoyaltyPointEntry::query()->where('type', 'redeem')->sum('points');

    // one of the two units is half of the goods: half of the 50 points come back
    $returnId = lrdReturn($scope, $sale, 1);
    expect($spent())->toBe(25);

    $this->putJson('/api/sell-returns/'.$returnId, validSellReturnPayload($scope, $sale, [
        'selllines' => [['id' => $sale->selllines()->first()->id, 'quantity_returned' => 2]],
    ]))->assertSuccessful();
    expect($spent())->toBe(0);

    $this->deleteJson('/api/sell-returns/'.$returnId)->assertSuccessful();
    expect($spent())->toBe(50);
});

test('a return rounds the share down: 3 of 7 points stays with the customer as 3', function () {
    $scope = lrdScope(50);
    $this->postJson('/api/sells', lrdBody($scope, 7))->assertSuccessful();

    lrdReturn($scope, lrdSale(), 1);

    // half of 7 is 3.5: 3 stay spent, 4 come back
    expect(-(int) LoyaltyPointEntry::query()->where('type', 'redeem')->sum('points'))->toBe(3);
});

test('the sync is idempotent: running it again changes nothing', function () {
    $scope = lrdScope(50);
    $this->postJson('/api/sells', lrdBody($scope, 50))->assertSuccessful();
    $sale = lrdSale();
    $entries = LoyaltyPointEntry::query()->count();

    foreach (range(1, 3) as $ignored) {
        app(LoyaltyPoints::class)->syncRedemptionForSale($sale->fresh());
    }

    expect(LoyaltyPointEntry::query()->count())->toBe($entries);

    $this->deleteJson('/api/sells/'.$sale->id)->assertSuccessful();
    $afterDelete = LoyaltyPointEntry::query()->count();

    foreach (range(1, 3) as $ignored) {
        app(LoyaltyPoints::class)->syncRedemptionForSale(Transaction::withTrashed()->findOrFail($sale->id), null, true);
    }

    expect(LoyaltyPointEntry::query()->count())->toBe($afterDelete)
        ->and(lrdBalance($scope))->toBe(50);
});

test('points redeemed from the Loyalty page against a sale stay through a save and go back when the sale is deleted', function () {
    $scope = lrdScope(50);
    $this->postJson('/api/sells', validSellPayload($scope))->assertSuccessful();
    $sale = lrdSale();
    app(LoyaltyPoints::class)->redeem(Contact::query()->findOrFail($scope['customer_id']), 30, 'At the counter', $sale->id);

    $this->putJson('/api/sells/'.$sale->id, validSellPayload($scope, ['shipping_charges' => 45, 'final_amount' => 285]))->assertSuccessful();
    expect(LoyaltyPointEntry::query()->where('type', 'redeem')->sum('points'))->toBe(-30);

    $this->deleteJson('/api/sells/'.$sale->id)->assertSuccessful();
    expect(LoyaltyPointEntry::query()->where('type', 'redeem')->sum('points'))->toBe(0);
});

test('the redeemed points never take the customer below zero however the sale moves', function () {
    $scope = lrdScope(50);
    $this->postJson('/api/sells', lrdBody($scope, 50))->assertSuccessful();
    $sale = lrdSale();

    foreach ([['delete'], ['restore'], ['draft'], ['final']] as [$step]) {
        match ($step) {
            'delete' => $this->deleteJson('/api/sells/'.$sale->id),
            'restore' => $this->postJson('/api/sells/restore_records', [$sale->id]),
            'draft' => $this->postJson('/api/sells/statusupdate', ['ids' => [$sale->id], 'status' => 'draft']),
            'final' => $this->postJson('/api/sells/statusupdate', ['ids' => [$sale->id], 'status' => 'final']),
        };

        $runningBalances = LoyaltyPointEntry::query()->orderBy('id')->pluck('balance_after');
        expect($runningBalances->min())->toBeGreaterThanOrEqual(0);
    }
});

test('redeeming at checkout needs the permission to redeem loyalty points', function () {
    $scope = lrdScope(50);
    $role = Role::query()->create(['name' => 'cashier'.uniqid(), 'company_id' => $scope['company_id'], 'is_active' => true]);
    grantMenuPermission($role->id, '/sell/add', 'sell.add'.uniqid());
    $cashier = createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']]);
    Sanctum::actingAs($cashier);

    // an ordinary sale is fine, points are refused without the permission
    $this->postJson('/api/sells', validSellPayload($scope))->assertSuccessful();
    $this->postJson('/api/sells', lrdBody($scope, 20))->assertUnprocessable()->assertJsonValidationErrors(['loyalty_points']);
    expect(LoyaltyPointEntry::query()->where('type', 'redeem')->count())->toBe(0);

    grantMenuPermission($role->id, '/loyalty/redeem', 'loyalty.redeem'.uniqid());
    $this->postJson('/api/sells', lrdBody($scope, 20))->assertSuccessful();

    expect(LoyaltyPointEntry::query()->where('type', 'redeem')->sum('points'))->toBe(-20);
});
