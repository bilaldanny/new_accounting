<?php

use App\Models\DocumentSetting;
use App\Models\GiftCard;
use App\Models\GiftCardEntry;
use App\Models\GiftCardOrphanRefund;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SaleGiftCards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Money spent from a gift card at checkout comes back when the sale is deleted, turned into a draft or
 * returned (in proportion), and goes out again when the sale is restored or finished again. A standard sale
 * here is 2 x 120 = 240 of goods + 40 shipping = 280, paid 100 from a card.
 */
function sgrScope(): array
{
    $scope = seedSellScope();
    $cashId = insertPurchaseChartAccount($scope, '111-00001', 'Cash in Hand', 'dr', false);
    insertPurchaseAccountMapping($scope, 'Cash', 'cash', $cashId);
    DocumentSetting::saveFor($scope['company_id'], 'checkout', ['gift_cards_enabled' => true]);

    return array_merge($scope, ['cash_account_id' => $cashId]);
}

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $attributes
 */
function sgrCard(array $scope, float $balance = 100, array $attributes = []): GiftCard
{
    $card = GiftCard::query()->create(array_merge([
        'company_id' => $scope['company_id'], 'code' => 'GC-REF', 'initial_value' => $balance, 'balance' => $balance, 'is_active' => true,
    ], $attributes));
    $card->entries()->create(['type' => 'issue', 'amount' => $balance, 'balance_after' => $balance]);

    return $card;
}

/**
 * A finished sale paid $paid from the card, through the API.
 *
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $overrides
 */
function sgrSale(array $scope, float $paid = 100, string $code = 'GC-REF', array $overrides = []): Transaction
{
    test()->postJson('/api/sells', validSellPayload($scope, array_merge([
        'gift_card_code' => $code, 'gift_card_amount' => $paid, 'gift_card_payment_account' => $scope['cash_account_id'],
    ], $overrides)))->assertSuccessful();

    return Transaction::query()->sells()->latest('id')->firstOrFail();
}

function sgrBalance(GiftCard $card): float
{
    return (float) GiftCard::withTrashed()->findOrFail($card->id)->balance;
}

/**
 * Makes the sale returnable (issued) and returns $quantity of its two units.
 *
 * @param  array<string, mixed>  $scope
 */
function sgrReturn(array $scope, Transaction $sale, int $quantity): int
{
    $sale->update(['status' => 'issue']);
    $sale->selllines()->update(['quantity_issue' => 2]);

    test()->postJson('/api/sell-returns', validSellReturnPayload($scope, $sale, [
        'selllines' => [['id' => $sale->selllines()->first()->id, 'quantity_returned' => $quantity]],
    ]))->assertSuccessful();

    return (int) Transaction::query()->sellReturns()->where('parent_id', $sale->id)->value('id');
}

// --- sale delete and restore -------------------------------------------------------------------

test('deleting a sale gives the gift card its money back as a new ledger entry and keeps the old ones', function () {
    $scope = sgrScope();
    $card = sgrCard($scope, 100);
    $user = User::query()->findOrFail(1);
    Sanctum::actingAs($user);
    $sale = sgrSale($scope);
    expect(sgrBalance($card))->toBe(0.0);

    $this->deleteJson('/api/sells/'.$sale->id)->assertSuccessful();

    $entries = $card->entries()->orderBy('id')->get();
    $refund = $entries->last();

    expect(sgrBalance($card))->toBe(100.0)
        ->and($entries->pluck('type')->all())->toBe(['issue', 'redeem', 'topup'])
        ->and($entries[1]->balance_after)->toBe('0.00')
        ->and($refund->type)->toBe('topup')
        ->and((float) $refund->amount)->toBe(100.0)
        ->and((float) $refund->balance_after)->toBe(100.0)
        ->and($refund->transaction_id)->toBe($sale->id)
        ->and($refund->user_id)->toBe($user->id)
        ->and($refund->note)->toContain('Refund')->toContain('deleted')
        ->and(Payment::query()->where('transaction_id', $sale->id)->exists())->toBeTrue();
});

test('restoring the sale takes the money out again, and delete and restore cycles never refund twice', function () {
    $scope = sgrScope();
    $card = sgrCard($scope, 100);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $sale = sgrSale($scope);

    foreach ([100.0, 0.0, 100.0, 0.0] as $step => $expected) {
        if ($step % 2 === 0) {
            $this->deleteJson('/api/sells/'.$sale->id)->assertSuccessful();
        } else {
            $this->postJson('/api/sells/restore_records', [$sale->id])->assertSuccessful();
        }

        expect(sgrBalance($card))->toBe($expected);
    }

    // deleted once more, then deleting a sale that is already gone, or refunding it again by hand, changes nothing
    $this->deleteJson('/api/sells/'.$sale->id)->assertSuccessful();
    $this->deleteJson('/api/sells/'.$sale->id)->assertNotFound();
    $this->postJson('/api/sells/bulk_delete', [$sale->id])->assertSuccessful();
    app(SaleGiftCards::class)->syncForSale(Transaction::withTrashed()->findOrFail($sale->id), null, true);
    app(SaleGiftCards::class)->syncForSale(Transaction::withTrashed()->findOrFail($sale->id), null, true);

    expect(sgrBalance($card))->toBe(100.0)
        ->and($card->entries()->where('type', 'topup')->count())->toBe(3)
        ->and($card->entries()->where('type', 'redeem')->count())->toBe(3);
});

test('the bulk delete refunds too', function () {
    $scope = sgrScope();
    $card = sgrCard($scope, 100);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $sale = sgrSale($scope);

    $this->postJson('/api/sells/bulk_delete', [$sale->id])->assertSuccessful();

    expect(sgrBalance($card))->toBe(100.0);
});

test('only the sale that was deleted is refunded when a card paid several', function () {
    $scope = sgrScope();
    $card = sgrCard($scope, 200);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $first = sgrSale($scope, 60);
    sgrSale($scope, 40);
    expect(sgrBalance($card))->toBe(100.0);

    $this->deleteJson('/api/sells/'.$first->id)->assertSuccessful();

    expect(sgrBalance($card))->toBe(160.0);
});

test('a sale that no card paid is deleted and restored without touching any card', function () {
    $scope = sgrScope();
    $card = sgrCard($scope, 100);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/sells', validSellPayload($scope))->assertSuccessful();
    $sale = Transaction::query()->sells()->firstOrFail();

    $this->deleteJson('/api/sells/'.$sale->id)->assertSuccessful();
    $this->postJson('/api/sells/restore_records', [$sale->id])->assertSuccessful();

    expect(sgrBalance($card))->toBe(100.0)
        ->and($card->entries()->count())->toBe(1)
        ->and(GiftCardOrphanRefund::query()->count())->toBe(0);
});

test('a sale cannot come back when its card can no longer be charged, and nothing changes', function (string $case) {
    $scope = sgrScope();
    $card = sgrCard($scope, 100);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $sale = sgrSale($scope);
    $this->deleteJson('/api/sells/'.$sale->id)->assertSuccessful();

    match ($case) {
        'spent elsewhere' => $card->redeem(30),
        'switched off' => $card->update(['is_active' => false]),
        'expired' => $card->update(['expires_at' => now()->subDay()->toDateString()]),
        'deleted' => $card->delete(),
    };

    $balance = sgrBalance($card);

    $this->postJson('/api/sells/restore_records', [$sale->id])->assertUnprocessable()->assertJsonValidationErrors(['gift_card_code']);

    expect(Transaction::query()->sells()->find($sale->id))->toBeNull()
        ->and(sgrBalance($card))->toBe($balance)
        ->and($card->entries()->where('type', 'redeem')->where('transaction_id', $sale->id)->count())->toBe(1);
})->with(['spent elsewhere', 'switched off', 'expired', 'deleted']);

test('the refund reaches a card that is switched off, expired or deleted, and still owes nothing to the card state', function (string $case) {
    $scope = sgrScope();
    $card = sgrCard($scope, 100);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $sale = sgrSale($scope);

    match ($case) {
        'switched off' => $card->update(['is_active' => false]),
        'expired' => $card->update(['expires_at' => now()->subDay()->toDateString()]),
        'deleted' => $card->delete(),
    };

    $this->deleteJson('/api/sells/'.$sale->id)->assertSuccessful();

    expect(sgrBalance($card))->toBe(100.0)
        ->and(GiftCardOrphanRefund::query()->count())->toBe(0)
        ->and(GiftCard::withTrashed()->findOrFail($card->id)->is_active)->toBe($case !== 'switched off');
})->with(['switched off', 'expired', 'deleted']);

test('a refund does not need the checkout switch to be on', function () {
    $scope = sgrScope();
    $card = sgrCard($scope, 100);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $sale = sgrSale($scope);
    DocumentSetting::saveFor($scope['company_id'], 'checkout', ['gift_cards_enabled' => false]);

    $this->deleteJson('/api/sells/'.$sale->id)->assertSuccessful();

    expect(sgrBalance($card))->toBe(100.0);
});

// --- a card deleted for good -------------------------------------------------------------------

test('a card that is gone for good leaves an orphaned refund to settle by hand, once', function () {
    $scope = sgrScope();
    $card = sgrCard($scope, 100);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $sale = sgrSale($scope);
    $card->forceDelete();

    $this->deleteJson('/api/sells/'.$sale->id)->assertSuccessful();

    $row = GiftCardOrphanRefund::query()->firstOrFail();

    expect($row->gift_card_code)->toBe('GC-REF')
        ->and($row->amount)->toBe(100.0)
        ->and($row->transaction_id)->toBe($sale->id)
        ->and($row->company_id)->toBe($scope['company_id'])
        ->and($row->resolved_at)->toBeNull();

    app(SaleGiftCards::class)->syncForSale(Transaction::withTrashed()->findOrFail($sale->id), null, true);
    $this->postJson('/api/sells/bulk_delete', [$sale->id])->assertSuccessful();

    expect(GiftCardOrphanRefund::query()->count())->toBe(1);

    // restoring the sale takes the owed money back (a negative row); deleting again owes it again
    $this->postJson('/api/sells/restore_records', [$sale->id])->assertSuccessful();
    expect((float) GiftCardOrphanRefund::query()->sum('amount'))->toBe(0.0);

    $this->deleteJson('/api/sells/'.$sale->id)->assertSuccessful();
    expect((float) GiftCardOrphanRefund::query()->sum('amount'))->toBe(100.0);
});

test('the orphaned refunds can be listed and settled', function () {
    $scope = sgrScope();
    $card = sgrCard($scope, 100);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $sale = sgrSale($scope);
    $card->forceDelete();
    $this->deleteJson('/api/sells/'.$sale->id)->assertSuccessful();
    $row = GiftCardOrphanRefund::query()->firstOrFail();

    $list = $this->getJson('/api/gift-cards/orphan-refunds')->assertSuccessful();

    expect($list->json('data.0.gift_card_code'))->toBe('GC-REF')
        ->and($list->json('data.0.amount'))->toBe(100)
        ->and($list->json('open_total'))->toBe(100);

    $this->postJson('/api/gift-cards/orphan-refunds/'.$row->id.'/resolve', ['note' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['note']);
    $this->postJson('/api/gift-cards/orphan-refunds/'.$row->id.'/resolve', ['note' => 'Paid back in cash'])->assertSuccessful();

    expect($row->refresh()->resolved_at)->not->toBeNull()
        ->and($row->resolved_note)->toBe('Paid back in cash');

    $this->getJson('/api/gift-cards/orphan-refunds')->assertJsonCount(0, 'data');
    $this->getJson('/api/gift-cards/orphan-refunds?status=all')->assertJsonCount(1, 'data');
});

test('orphaned refunds are private to their company and settling needs the permission', function () {
    $scope = sgrScope();
    $card = sgrCard($scope, 100);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $sale = sgrSale($scope);
    $card->forceDelete();
    $this->deleteJson('/api/sells/'.$sale->id)->assertSuccessful();
    $row = GiftCardOrphanRefund::query()->firstOrFail();

    $otherCompany = DB::table('companies')->insertGetId(['code' => 'SGR002', 'name' => 'Other Co', 'address' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $outsider = Role::query()->create(['name' => 'companyadmin', 'company_id' => $otherCompany, 'is_active' => true]);
    grantMenuPermission($outsider->id, '/giftcard/topup');
    Sanctum::actingAs(createStaffUserForRole($outsider, ['company_id' => $otherCompany]));

    $this->getJson('/api/gift-cards/orphan-refunds')->assertJsonCount(0, 'data');
    $this->postJson('/api/gift-cards/orphan-refunds/'.$row->id.'/resolve', ['note' => 'not mine'])->assertNotFound();

    $insider = Role::query()->create(['name' => 'companyadmin', 'company_id' => $scope['company_id'], 'is_active' => true]);
    Sanctum::actingAs(createStaffUserForRole($insider, ['company_id' => $scope['company_id']]));

    $this->getJson('/api/gift-cards/orphan-refunds')->assertJsonCount(1, 'data');
    $this->postJson('/api/gift-cards/orphan-refunds/'.$row->id.'/resolve', ['note' => 'no permission'])->assertForbidden();

    expect($row->refresh()->resolved_at)->toBeNull();
});

// --- sale return -------------------------------------------------------------------------------

test('a partial return gives back the same share of the gift card money, and following the return changes it', function () {
    $scope = sgrScope();
    $card = sgrCard($scope, 100);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $sale = sgrSale($scope);

    // one of two units: half of the goods came back, half of the 100
    $returnId = sgrReturn($scope, $sale, 1);
    expect(sgrBalance($card))->toBe(50.0);

    // both units: all of it
    $this->putJson('/api/sell-returns/'.$returnId, validSellReturnPayload($scope, $sale, [
        'selllines' => [['id' => $sale->selllines()->first()->id, 'quantity_returned' => 2]],
    ]))->assertSuccessful();
    expect(sgrBalance($card))->toBe(100.0);

    // and deleting the return puts the sale back as it was
    $this->deleteJson('/api/sell-returns/'.$returnId)->assertSuccessful();
    expect(sgrBalance($card))->toBe(0.0);
});

test('returning every unit gives back the whole gift card portion, shipping or not', function () {
    $scope = sgrScope();
    $card = sgrCard($scope, 100);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $sale = sgrSale($scope);

    sgrReturn($scope, $sale, 2);

    expect(sgrBalance($card))->toBe(100.0)
        ->and($card->entries()->where('type', 'topup')->count())->toBe(1);
});

test('the share is of the goods that came back, so an odd share is rounded to the cent', function () {
    $scope = sgrScope();
    $card = sgrCard($scope, 100);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $payload = validSellPayload($scope, ['gift_card_code' => 'GC-REF', 'gift_card_amount' => 100, 'gift_card_payment_account' => $scope['cash_account_id'], 'final_amount' => 380]);
    $payload['selllines'][0] = array_merge($payload['selllines'][0], ['quantity' => 3, 'row_subtotal' => 360]);
    $this->postJson('/api/sells', $payload)->assertSuccessful();
    $sale = Transaction::query()->sells()->firstOrFail();

    // one of three units: a third of 100
    sgrReturn($scope, $sale->refresh(), 1);

    expect(sgrBalance($card))->toBe(33.33);
});

test('a return after part of the money already came back refunds only the rest, and deleting the sale then refunds exactly what is still held', function () {
    $scope = sgrScope();
    $card = sgrCard($scope, 100);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $sale = sgrSale($scope);
    sgrReturn($scope, $sale, 1);
    expect(sgrBalance($card))->toBe(50.0);

    $this->deleteJson('/api/sells/'.$sale->id)->assertSuccessful();

    // 50 came back with the return, 50 with the delete: 100 in all, never 150
    expect(sgrBalance($card))->toBe(100.0)
        ->and((float) $card->entries()->where('type', 'topup')->sum('amount'))->toBe(100.0);
});

test('a sale that a card did not pay is not affected by a return', function () {
    $scope = sgrScope();
    $card = sgrCard($scope, 100);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/sells', validSellPayload($scope))->assertSuccessful();
    $sale = Transaction::query()->sells()->firstOrFail();

    sgrReturn($scope, $sale, 1);

    expect(sgrBalance($card))->toBe(100.0)
        ->and($card->entries()->count())->toBe(1);
});

// --- edit --------------------------------------------------------------------------------------

test('turning a paid sale into a draft refunds the card and finishing it again charges it again', function () {
    $scope = sgrScope();
    $card = sgrCard($scope, 100);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $sale = sgrSale($scope);

    $this->putJson('/api/sells/'.$sale->id, validSellPayload($scope, ['status' => 'draft']))->assertSuccessful();
    expect(sgrBalance($card))->toBe(100.0);

    $this->putJson('/api/sells/'.$sale->id, validSellPayload($scope, ['status' => 'final']))->assertSuccessful();
    expect(sgrBalance($card))->toBe(0.0);
});

test('finishing the sale again is refused when the card has been spent meanwhile', function () {
    $scope = sgrScope();
    $card = sgrCard($scope, 100);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $sale = sgrSale($scope);
    $this->putJson('/api/sells/'.$sale->id, validSellPayload($scope, ['status' => 'draft']))->assertSuccessful();
    $card->redeem(80);

    $this->putJson('/api/sells/'.$sale->id, validSellPayload($scope, ['status' => 'final']))->assertUnprocessable()->assertJsonValidationErrors(['gift_card_code']);

    expect($sale->fresh()->status)->toBe('draft')
        ->and(sgrBalance($card))->toBe(20.0);
});

test('the status update endpoint refunds and charges too', function () {
    $scope = sgrScope();
    $card = sgrCard($scope, 100);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $sale = sgrSale($scope);

    $this->postJson('/api/sells/statusupdate', ['ids' => [$sale->id], 'status' => 'draft'])->assertSuccessful();
    expect(sgrBalance($card))->toBe(100.0);

    $this->postJson('/api/sells/statusupdate', ['ids' => [$sale->id], 'status' => 'final'])->assertSuccessful();
    expect(sgrBalance($card))->toBe(0.0);
});

// --- never over-refund -------------------------------------------------------------------------

test('however the sale is deleted, returned, restored and edited, the card never ends up above what it started with', function () {
    $scope = sgrScope();
    $card = sgrCard($scope, 100);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $sale = sgrSale($scope);
    $service = app(SaleGiftCards::class);

    $returnId = sgrReturn($scope, $sale, 1);
    $this->deleteJson('/api/sell-returns/'.$returnId)->assertSuccessful();
    $this->deleteJson('/api/sells/'.$sale->id)->assertSuccessful();
    $this->postJson('/api/sells/restore_records', [$sale->id])->assertSuccessful();
    $this->deleteJson('/api/sells/'.$sale->id)->assertSuccessful();

    foreach (range(1, 3) as $ignored) {
        $service->syncForSale(Transaction::withTrashed()->findOrFail($sale->id), null, true);
    }

    $ledger = $card->entries()->orderBy('id')->get();

    expect(sgrBalance($card))->toBe(100.0)
        ->and($ledger->every(fn (GiftCardEntry $entry): bool => (float) $entry->balance_after >= 0 && (float) $entry->balance_after <= 100))->toBeTrue()
        ->and(round($ledger->where('type', 'topup')->sum('amount') - $ledger->where('type', 'redeem')->sum('amount'), 2))->toBe(0.0);
});
