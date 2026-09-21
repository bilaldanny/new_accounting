<?php

use App\Models\Contact;
use App\Models\Discount;
use App\Models\DocumentSetting;
use App\Models\GiftCard;
use App\Models\GiftCardEntry;
use App\Models\LoyaltyPointEntry;
use App\Models\LoyaltySetting;
use App\Models\Payment;
use App\Models\SaleDiscount;
use App\Models\TAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LoyaltyPoints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Discount codes, gift card payments and loyalty points at checkout: the sell save (POST / PUT /api/sells),
 * the sale return and the sale delete. A standard sale here is 2 x 120 = 240 of goods + 40 shipping = 280.
 */
function sciScope(): array
{
    $scope = seedSellScope();
    $cashId = insertPurchaseChartAccount($scope, '111-00001', 'Cash in Hand', 'dr', false);
    insertPurchaseAccountMapping($scope, 'Cash', 'cash', $cashId);

    return array_merge($scope, ['cash_account_id' => $cashId]);
}

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $extras
 */
function sciSwitchOn(array $scope, array $extras = ['discount_codes_enabled' => true, 'gift_cards_enabled' => true]): void
{
    DocumentSetting::saveFor($scope['company_id'], 'checkout', $extras);
}

/**
 * Loyalty on: 1 point per 100 spent, a point worth 1.
 *
 * @param  array<string, mixed>  $scope
 */
function sciLoyaltyOn(array $scope, array $overrides = []): void
{
    LoyaltySetting::query()->updateOrCreate(['company_id' => $scope['company_id']], array_merge([
        'is_enabled' => true, 'amount_per_point' => 100, 'point_value' => 1, 'min_redeem_points' => 0,
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $attributes
 */
function sciDiscount(array $scope, array $attributes = []): Discount
{
    return Discount::query()->create(array_merge([
        'company_id' => $scope['company_id'], 'name' => 'Ten Percent', 'code' => 'TEN', 'discount_type' => 'percentage',
        'value' => 10, 'min_purchase_amount' => 0, 'is_active' => true,
    ], $attributes));
}

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $attributes
 */
function sciCard(array $scope, float $balance = 100, array $attributes = []): GiftCard
{
    $card = GiftCard::query()->create(array_merge([
        'company_id' => $scope['company_id'], 'code' => 'GC-TEST', 'initial_value' => $balance, 'balance' => $balance, 'is_active' => true,
    ], $attributes));
    $card->entries()->create(['type' => 'issue', 'amount' => $balance, 'balance_after' => $balance]);

    return $card;
}

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function sciPayload(array $scope, array $overrides = []): array
{
    return validSellPayload($scope, array_merge(['gift_card_payment_account' => $scope['cash_account_id']], $overrides));
}

function sciSale(): Transaction
{
    return Transaction::query()->sells()->latest('id')->firstOrFail();
}

function sciPoints(int $contactId): int
{
    return (int) LoyaltyPointEntry::query()->where('contact_id', $contactId)->sum('points');
}

// --- discount code -----------------------------------------------------------------------------

test('a discount code is applied, worked out on the server and stored with the sale', function () {
    $scope = sciScope();
    sciSwitchOn($scope);
    $discount = sciDiscount($scope);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/sells', sciPayload($scope, ['discount_code' => 'ten', 'coupon_discount_amount' => 24, 'final_amount' => 256]))->assertSuccessful();

    $sale = sciSale();
    $applied = SaleDiscount::query()->where('transaction_id', $sale->id)->firstOrFail();

    expect((float) $sale->final_amount)->toBe(256.0)
        ->and($applied->discount_id)->toBe($discount->id)
        ->and($applied->code)->toBe('TEN')
        ->and($applied->name)->toBe('Ten Percent')
        ->and($applied->discount_type)->toBe('percentage')
        ->and($applied->value)->toBe(10.0)
        ->and($applied->amount)->toBe(24.0);

    $show = $this->getJson('/api/sells/'.$sale->id)->assertSuccessful();

    expect($show->json('discount_code'))->toBe('TEN')
        ->and($show->json('coupon_discount_amount'))->toBe(24)
        ->and($show->json('applied_discount.name'))->toBe('Ten Percent')
        ->and($show->json('gift_card_payments'))->toBe([]);
});

test('the discount is the rule\'s: a fixed amount, a capped percentage, never more than the goods', function (array $attributes, float $expected) {
    $scope = sciScope();
    sciSwitchOn($scope);
    sciDiscount($scope, $attributes);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/sells', sciPayload($scope, ['discount_code' => 'TEN', 'coupon_discount_amount' => $expected, 'final_amount' => 280 - $expected]))->assertSuccessful();

    expect(SaleDiscount::query()->firstOrFail()->amount)->toBe($expected);
})->with([
    'a fixed amount' => [['discount_type' => 'fixed', 'value' => 30], 30.0],
    'a percentage capped by the maximum' => [['max_discount_amount' => 10], 10.0],
    'a fixed amount above the goods is the goods' => [['discount_type' => 'fixed', 'value' => 900], 240.0],
]);

test('a sale whose discount amount is not the server\'s is refused and nothing is saved', function (float $sent) {
    $scope = sciScope();
    sciSwitchOn($scope);
    sciDiscount($scope);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/sells', sciPayload($scope, ['discount_code' => 'TEN', 'coupon_discount_amount' => $sent, 'final_amount' => 250]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['coupon_discount_amount']);

    expect(Transaction::query()->sells()->count())->toBe(0)
        ->and(SaleDiscount::query()->count())->toBe(0);
})->with([[0.0], [23.98], [24.02], [240.0]]);

test('a difference of a cent is tolerated', function () {
    $scope = sciScope();
    sciSwitchOn($scope);
    sciDiscount($scope);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/sells', sciPayload($scope, ['discount_code' => 'TEN', 'coupon_discount_amount' => 24.01, 'final_amount' => 255.99]))->assertSuccessful();

    expect(SaleDiscount::query()->firstOrFail()->amount)->toBe(24.0);
});

test('a code that cannot be used is refused with a reason and nothing is saved', function (string $case, string $message) {
    $scope = sciScope();
    sciSwitchOn($scope);
    $rule = match ($case) {
        'inactive' => ['is_active' => false],
        'expired' => ['expires_at' => now()->subDay()->toDateString()],
        'not started' => ['starts_at' => now()->addDay()->toDateString()],
        'minimum purchase' => ['min_purchase_amount' => 500],
        default => [],
    };
    sciDiscount($scope, $rule);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $code = $case === 'unknown' ? 'NOPE' : 'TEN';

    $this->postJson('/api/sells', sciPayload($scope, ['discount_code' => $code, 'coupon_discount_amount' => 24, 'final_amount' => 256]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.discount_code.0', $message);

    expect(Transaction::query()->sells()->count())->toBe(0);
})->with([
    'unknown' => ['unknown', 'This coupon code does not exist.'],
    'inactive' => ['inactive', 'This discount is switched off.'],
    'expired' => ['expired', 'This discount has expired.'],
    'not started' => ['not started', 'This discount is not valid yet.'],
    'minimum purchase' => ['minimum purchase', 'The sale is below the minimum amount for this discount.'],
]);

test('discount codes do nothing until the company switches them on', function () {
    $scope = sciScope();
    sciSwitchOn($scope, ['discount_codes_enabled' => false, 'gift_cards_enabled' => true]);
    sciDiscount($scope);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/sells', sciPayload($scope, ['discount_code' => 'TEN', 'coupon_discount_amount' => 24, 'final_amount' => 256]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.discount_code.0', 'Discount codes are not switched on for this company.');

    // and a sale that does not use one is not affected at all
    $this->postJson('/api/sells', sciPayload($scope))->assertSuccessful();
    expect(SaleDiscount::query()->count())->toBe(0);
});

test('a code needs its amount, and a code of another company is not found', function () {
    $scope = sciScope();
    sciSwitchOn($scope);
    $other = DB::table('companies')->insertGetId(['code' => 'SCI002', 'name' => 'Other Co', 'address' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    Discount::query()->create(['company_id' => $other, 'name' => 'Theirs', 'code' => 'THEIRS', 'discount_type' => 'fixed', 'value' => 5, 'is_active' => true]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/sells', sciPayload($scope, ['discount_code' => 'TEN']))->assertUnprocessable()->assertJsonValidationErrors(['coupon_discount_amount']);
    $this->postJson('/api/sells', sciPayload($scope, ['discount_code' => 'THEIRS', 'coupon_discount_amount' => 5, 'final_amount' => 275]))->assertUnprocessable()->assertJsonValidationErrors(['discount_code']);

    expect(Transaction::query()->sells()->count())->toBe(0);
});

test('a draft and a quotation can carry a discount code too', function (string $status) {
    $scope = sciScope();
    sciSwitchOn($scope);
    sciDiscount($scope);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/sells', sciPayload($scope, ['status' => $status, 'discount_code' => 'TEN', 'coupon_discount_amount' => 24, 'final_amount' => 256]))->assertSuccessful();

    expect(SaleDiscount::query()->firstOrFail()->amount)->toBe(24.0);
})->with(['draft', 'quotation']);

test('editing a sale keeps its code without applying it again', function () {
    $scope = sciScope();
    sciSwitchOn($scope);
    sciDiscount($scope);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/sells', sciPayload($scope, ['discount_code' => 'TEN', 'coupon_discount_amount' => 24, 'final_amount' => 256]))->assertSuccessful();
    $sale = sciSale();
    $first = SaleDiscount::query()->firstOrFail();

    $this->putJson('/api/sells/'.$sale->id, sciPayload($scope, ['discount_code' => 'TEN', 'coupon_discount_amount' => 24, 'final_amount' => 256, 'additional_note' => 'edited']))->assertSuccessful();

    expect(SaleDiscount::query()->count())->toBe(1)
        ->and(SaleDiscount::query()->firstOrFail()->id)->toBe($first->id)
        ->and((float) $sale->fresh()->final_amount)->toBe(256.0);
});

test('an edit that leaves the discount fields out keeps the applied code untouched', function () {
    $scope = sciScope();
    sciSwitchOn($scope);
    sciDiscount($scope);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/sells', sciPayload($scope, ['discount_code' => 'TEN', 'coupon_discount_amount' => 24, 'final_amount' => 256]))->assertSuccessful();
    $sale = sciSale();

    $this->putJson('/api/sells/'.$sale->id, sciPayload($scope, ['final_amount' => 256]))->assertSuccessful();

    expect(SaleDiscount::query()->where('transaction_id', $sale->id)->exists())->toBeTrue();
});

test('an edit that changes the goods re-prices the code, a code that has expired since stays, one below its minimum goes', function () {
    $scope = sciScope();
    sciSwitchOn($scope);
    $discount = sciDiscount($scope, ['min_purchase_amount' => 200]);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/sells', sciPayload($scope, ['discount_code' => 'TEN', 'coupon_discount_amount' => 24, 'final_amount' => 256]))->assertSuccessful();
    $sale = sciSale();

    // three units now: 360 of goods, 10% = 36
    $bigger = sciPayload($scope, ['discount_code' => 'TEN', 'coupon_discount_amount' => 36, 'final_amount' => 364]);
    $bigger['selllines'][0] = array_merge($bigger['selllines'][0], ['id' => $sale->selllines()->first()->id, 'quantity' => 3, 'row_subtotal' => 360]);
    $this->putJson('/api/sells/'.$sale->id, $bigger)->assertSuccessful();

    expect(SaleDiscount::query()->firstOrFail()->amount)->toBe(36.0);

    // the discount has since expired: the sale keeps it
    $discount->update(['expires_at' => now()->subWeek()->toDateString()]);
    $this->putJson('/api/sells/'.$sale->id, $bigger)->assertSuccessful();
    expect(SaleDiscount::query()->count())->toBe(1);

    // one unit only (120) is under the 200 minimum: refused, and the old record is untouched
    $smaller = sciPayload($scope, ['discount_code' => 'TEN', 'coupon_discount_amount' => 12, 'final_amount' => 148]);
    $smaller['selllines'][0] = array_merge($smaller['selllines'][0], ['id' => $sale->selllines()->first()->id, 'quantity' => 1, 'row_subtotal' => 120]);
    $this->putJson('/api/sells/'.$sale->id, $smaller)->assertUnprocessable()->assertJsonValidationErrors(['discount_code']);

    expect(SaleDiscount::query()->firstOrFail()->amount)->toBe(36.0);
});

test('a sent empty code removes the discount and a different code replaces it', function () {
    $scope = sciScope();
    sciSwitchOn($scope);
    sciDiscount($scope);
    sciDiscount($scope, ['name' => 'Flat Fifty', 'code' => 'FLAT', 'discount_type' => 'fixed', 'value' => 50]);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/sells', sciPayload($scope, ['discount_code' => 'TEN', 'coupon_discount_amount' => 24, 'final_amount' => 256]))->assertSuccessful();
    $sale = sciSale();

    $this->putJson('/api/sells/'.$sale->id, sciPayload($scope, ['discount_code' => 'FLAT', 'coupon_discount_amount' => 50, 'final_amount' => 230]))->assertSuccessful();
    expect(SaleDiscount::query()->count())->toBe(1)
        ->and(SaleDiscount::query()->firstOrFail()->code)->toBe('FLAT');

    $this->putJson('/api/sells/'.$sale->id, sciPayload($scope, ['discount_code' => '', 'final_amount' => 280]))->assertSuccessful();
    expect(SaleDiscount::query()->count())->toBe(0);
});

test('deleting the sale removes its discount record, deleting the discount keeps the snapshot', function () {
    $scope = sciScope();
    sciSwitchOn($scope);
    $discount = sciDiscount($scope);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/sells', sciPayload($scope, ['discount_code' => 'TEN', 'coupon_discount_amount' => 24, 'final_amount' => 256]))->assertSuccessful();

    $discount->forceDelete();

    $applied = SaleDiscount::query()->firstOrFail();

    expect($applied->discount_id)->toBeNull()
        ->and($applied->code)->toBe('TEN')
        ->and($applied->amount)->toBe(24.0);
});

// --- gift card ---------------------------------------------------------------------------------

test('a gift card pays part of a sale: a payment is posted, the card is charged, the rest stays due', function () {
    $scope = sciScope();
    sciSwitchOn($scope);
    $card = sciCard($scope, 100);
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->postJson('/api/sells', sciPayload($scope, ['gift_card_code' => 'gc-test', 'gift_card_amount' => 100]))
        ->assertSuccessful()
        ->assertJson(['remaining_amount' => 180, 'payment_status' => 'partial']);

    $sale = sciSale();
    $payment = Payment::query()->where('transaction_id', $sale->id)->firstOrFail();
    $entry = GiftCardEntry::query()->where('type', 'redeem')->firstOrFail();

    expect($response->json('id'))->toBe($sale->id)
        ->and((float) $payment->amount)->toBe(100.0)
        ->and($payment->method)->toBe('other')
        ->and($payment->note)->toBe('Gift card GC-TEST')
        ->and($payment->payment_account)->toBe($scope['cash_account_id'])
        ->and($sale->payment_status)->toBe('partial')
        ->and((float) $card->refresh()->balance)->toBe(0.0)
        ->and($entry->gift_card_id)->toBe($card->id)
        ->and((float) $entry->amount)->toBe(100.0)
        ->and($entry->transaction_id)->toBe($sale->id)
        ->and($entry->user_id)->toBe($superadmin->id)
        ->and($entry->note)->toBe('Sale '.$sale->invoice_no);

    $journal = TAccount::query()->with('details')->findOrFail($payment->t_account_id);

    expect((float) $journal->total_amount)->toBe(100.0)
        ->and((float) $journal->details->firstWhere('coa_id', $scope['cash_account_id'])->debit)->toBe(100.0)
        ->and((float) $journal->details->firstWhere('coa_id', $scope['customer_coa_id'])->credit)->toBe(100.0);
});

test('a gift card that covers the sale marks it paid', function () {
    $scope = sciScope();
    sciSwitchOn($scope);
    sciCard($scope, 500);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/sells', sciPayload($scope, ['gift_card_code' => 'GC-TEST', 'gift_card_amount' => 280]))
        ->assertSuccessful()
        ->assertJson(['remaining_amount' => 0, 'payment_status' => 'paid']);

    expect(GiftCard::query()->firstOrFail()->balance)->toBe('220.00');
});

test('a gift card that cannot pay is refused with a reason and neither the sale nor the card changes', function (string $case, string $field) {
    $scope = sciScope();
    sciSwitchOn($scope, ['discount_codes_enabled' => true, 'gift_cards_enabled' => $case !== 'switched off']);
    $card = sciCard($scope, 100, match ($case) {
        'inactive card' => ['is_active' => false],
        'expired card' => ['expires_at' => now()->subDay()->toDateString()],
        default => [],
    });
    Sanctum::actingAs(User::query()->findOrFail(1));

    $overrides = match ($case) {
        'unknown code' => ['gift_card_code' => 'NOPE', 'gift_card_amount' => 10],
        'more than the balance' => ['gift_card_code' => 'GC-TEST', 'gift_card_amount' => 100.01],
        'more than the sale' => ['gift_card_code' => 'GC-TEST', 'gift_card_amount' => 281],
        'no amount' => ['gift_card_code' => 'GC-TEST', 'gift_card_amount' => 0],
        'no account' => ['gift_card_code' => 'GC-TEST', 'gift_card_amount' => 50, 'gift_card_payment_account' => null],
        'a draft' => ['gift_card_code' => 'GC-TEST', 'gift_card_amount' => 50, 'status' => 'draft'],
        'an amount with no code' => ['gift_card_amount' => 50],
        default => ['gift_card_code' => 'GC-TEST', 'gift_card_amount' => 50],
    };

    $this->postJson('/api/sells', sciPayload($scope, $overrides))->assertUnprocessable()->assertJsonValidationErrors([$field]);

    expect(Transaction::query()->sells()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and((float) $card->refresh()->balance)->toBe(100.0)
        ->and($card->entries()->count())->toBe(1);
})->with([
    'switched off' => ['switched off', 'gift_card_code'],
    'unknown code' => ['unknown code', 'gift_card_code'],
    'inactive card' => ['inactive card', 'gift_card_amount'],
    'expired card' => ['expired card', 'gift_card_amount'],
    'more than the balance' => ['more than the balance', 'gift_card_amount'],
    'more than the sale' => ['more than the sale', 'gift_card_amount'],
    'no amount' => ['no amount', 'gift_card_amount'],
    'no account' => ['no account', 'gift_card_payment_account'],
    'a draft' => ['a draft', 'gift_card_code'],
    'an amount with no code' => ['an amount with no code', 'gift_card_code'],
]);

test('a card from another company or a trashed one is not found', function () {
    $scope = sciScope();
    sciSwitchOn($scope);
    $other = DB::table('companies')->insertGetId(['code' => 'SCI002', 'name' => 'Other Co', 'address' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    GiftCard::query()->create(['company_id' => $other, 'code' => 'THEIRS', 'initial_value' => 100, 'balance' => 100, 'is_active' => true]);
    sciCard($scope, 100, ['code' => 'GONE'])->delete();
    Sanctum::actingAs(User::query()->findOrFail(1));

    foreach (['THEIRS', 'GONE'] as $code) {
        $this->postJson('/api/sells', sciPayload($scope, ['gift_card_code' => $code, 'gift_card_amount' => 10]))->assertUnprocessable()->assertJsonValidationErrors(['gift_card_code']);
    }

    expect(Transaction::query()->sells()->count())->toBe(0);
});

test('editing a sale never charges its gift card again', function () {
    $scope = sciScope();
    sciSwitchOn($scope);
    $card = sciCard($scope, 100);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/sells', sciPayload($scope, ['gift_card_code' => 'GC-TEST', 'gift_card_amount' => 60]))->assertSuccessful();
    $sale = sciSale();

    $this->putJson('/api/sells/'.$sale->id, sciPayload($scope, ['gift_card_code' => 'GC-TEST', 'gift_card_amount' => 60, 'additional_note' => 'edited']))
        ->assertSuccessful()
        ->assertJson(['remaining_amount' => 220]);

    expect((float) $card->refresh()->balance)->toBe(40.0)
        ->and(Payment::query()->where('transaction_id', $sale->id)->count())->toBe(1)
        ->and(GiftCardEntry::query()->where('type', 'redeem')->count())->toBe(1);

    $show = $this->getJson('/api/sells/'.$sale->id)->assertSuccessful();

    expect($show->json('gift_card_payments'))->toBe([['code' => 'GC-TEST', 'amount' => 60, 'balance_after' => 40]]);
});

test('a draft turned final can then be paid with a gift card', function () {
    $scope = sciScope();
    sciSwitchOn($scope);
    $card = sciCard($scope, 100);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/sells', sciPayload($scope, ['status' => 'draft']))->assertSuccessful();
    $sale = sciSale();

    $this->putJson('/api/sells/'.$sale->id, sciPayload($scope, ['status' => 'final', 'gift_card_code' => 'GC-TEST', 'gift_card_amount' => 100]))->assertSuccessful();

    expect((float) $card->refresh()->balance)->toBe(0.0)
        ->and($sale->fresh()->payment_status)->toBe('partial');
});

// --- loyalty -----------------------------------------------------------------------------------

test('a finished sale earns its customer points, a draft and a quotation do not', function () {
    $scope = sciScope();
    sciLoyaltyOn($scope);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/sells', sciPayload($scope, ['status' => 'draft']))->assertSuccessful();
    $this->postJson('/api/sells', sciPayload($scope, ['status' => 'quotation']))->assertSuccessful();

    expect(LoyaltyPointEntry::query()->count())->toBe(0);

    $this->postJson('/api/sells', sciPayload($scope))->assertSuccessful();

    $entry = LoyaltyPointEntry::query()->firstOrFail();

    expect($entry->type)->toBe('earn')
        ->and($entry->points)->toBe(2)
        ->and($entry->contact_id)->toBe($scope['customer_id'])
        ->and($entry->transaction_id)->toBe(sciSale()->id)
        ->and((float) $entry->amount)->toBe(280.0)
        ->and($entry->balance_after)->toBe(2);

    $show = $this->getJson('/api/sells/'.sciSale()->id)->assertSuccessful();

    expect($show->json('loyalty_points_earned'))->toBe(2);
});

test('an approved sale earns too, and points are on the amount after the coupon', function () {
    $scope = sciScope();
    sciLoyaltyOn($scope);
    sciSwitchOn($scope);
    sciDiscount($scope, ['discount_type' => 'fixed', 'value' => 100]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/sells', sciPayload($scope, ['status' => 'approved', 'discount_code' => 'TEN', 'coupon_discount_amount' => 100, 'final_amount' => 180]))->assertSuccessful();

    expect(sciPoints($scope['customer_id']))->toBe(1);
});

test('nothing is earned while the programme is off, and switching it off later does not take points back on an edit', function () {
    $scope = sciScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/sells', sciPayload($scope))->assertSuccessful();
    expect(LoyaltyPointEntry::query()->count())->toBe(0);

    sciLoyaltyOn($scope);
    $this->postJson('/api/sells', sciPayload($scope, ['final_amount' => 500]))->assertSuccessful();
    $sale = sciSale();
    expect(sciPoints($scope['customer_id']))->toBe(5);

    LoyaltySetting::query()->update(['is_enabled' => false]);
    $this->putJson('/api/sells/'.$sale->id, sciPayload($scope, ['final_amount' => 900]))->assertSuccessful();

    expect(sciPoints($scope['customer_id']))->toBe(5);
});

test('editing a sale moves its points up and down and never double counts', function () {
    $scope = sciScope();
    sciLoyaltyOn($scope);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/sells', sciPayload($scope))->assertSuccessful();
    $sale = sciSale();

    $this->putJson('/api/sells/'.$sale->id, sciPayload($scope, ['final_amount' => 280]))->assertSuccessful();
    expect(sciPoints($scope['customer_id']))->toBe(2)
        ->and(LoyaltyPointEntry::query()->count())->toBe(1);

    $this->putJson('/api/sells/'.$sale->id, sciPayload($scope, ['final_amount' => 500]))->assertSuccessful();
    expect(sciPoints($scope['customer_id']))->toBe(5);

    $this->putJson('/api/sells/'.$sale->id, sciPayload($scope, ['final_amount' => 150]))->assertSuccessful();
    expect(sciPoints($scope['customer_id']))->toBe(1);

    $types = LoyaltyPointEntry::query()->orderBy('id')->pluck('type')->all();

    expect($types)->toBe(['earn', 'adjust', 'adjust'])
        ->and(LoyaltyPointEntry::query()->orderBy('id')->pluck('balance_after')->all())->toBe([2, 5, 1]);
});

test('turning a sale into a draft takes its points back and finishing it again gives them again', function () {
    $scope = sciScope();
    sciLoyaltyOn($scope);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/sells', sciPayload($scope))->assertSuccessful();
    $sale = sciSale();

    $this->putJson('/api/sells/'.$sale->id, sciPayload($scope, ['status' => 'draft']))->assertSuccessful();
    expect(sciPoints($scope['customer_id']))->toBe(0);

    $this->putJson('/api/sells/'.$sale->id, sciPayload($scope, ['status' => 'final']))->assertSuccessful();
    expect(sciPoints($scope['customer_id']))->toBe(2);
});

test('the status update endpoint keeps the points in step too', function () {
    $scope = sciScope();
    sciLoyaltyOn($scope);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/sells', sciPayload($scope))->assertSuccessful();
    $sale = sciSale();

    $this->postJson('/api/sells/statusupdate', ['ids' => [$sale->id], 'status' => 'draft'])->assertSuccessful();
    expect(sciPoints($scope['customer_id']))->toBe(0);

    $this->postJson('/api/sells/statusupdate', ['ids' => [$sale->id], 'status' => 'final'])->assertSuccessful();
    expect(sciPoints($scope['customer_id']))->toBe(2);
});

test('changing the customer of a sale moves the points to the new customer', function () {
    $scope = sciScope();
    sciLoyaltyOn($scope);
    $other = trpContact($scope, 'Beta Traders', 'customer', 'CU-BETA');
    DB::table('contacts')->where('id', $other)->update(['customer_gl_id' => DB::table('contacts')->where('id', $scope['customer_id'])->value('customer_gl_id'), 'gl_id' => DB::table('contacts')->where('id', $scope['customer_id'])->value('gl_id')]);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/sells', sciPayload($scope))->assertSuccessful();
    $sale = sciSale();

    $this->putJson('/api/sells/'.$sale->id, sciPayload($scope, ['contact_id' => $other]))->assertSuccessful();

    expect(sciPoints($scope['customer_id']))->toBe(0)
        ->and(sciPoints($other))->toBe(2);
});

test('deleting a sale takes its points back, restoring it gives them again', function () {
    $scope = sciScope();
    sciLoyaltyOn($scope);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/sells', sciPayload($scope))->assertSuccessful();
    $sale = sciSale();

    $this->deleteJson('/api/sells/'.$sale->id)->assertSuccessful();
    expect(sciPoints($scope['customer_id']))->toBe(0);

    $this->postJson('/api/sells/restore_records', [$sale->id])->assertSuccessful();
    expect(sciPoints($scope['customer_id']))->toBe(2);

    $this->postJson('/api/sells/bulk_delete', [$sale->id])->assertSuccessful();
    expect(sciPoints($scope['customer_id']))->toBe(0);
});

test('a reversal never takes the customer below zero: points already spent stay spent', function () {
    $scope = sciScope();
    sciLoyaltyOn($scope);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/sells', sciPayload($scope, ['final_amount' => 500]))->assertSuccessful();
    $sale = sciSale();
    app(LoyaltyPoints::class)->redeem(Contact::query()->findOrFail($scope['customer_id']), 4);

    $this->deleteJson('/api/sells/'.$sale->id)->assertSuccessful();

    expect(sciPoints($scope['customer_id']))->toBe(0);
});

test('a sale return takes back the points of what was returned, and deleting the return gives them back', function () {
    $scope = sciScope();
    sciLoyaltyOn($scope);
    Sanctum::actingAs(User::query()->findOrFail(1));
    $this->postJson('/api/sells', sciPayload($scope, ['final_amount' => 600]))->assertSuccessful();
    $sale = sciSale();
    expect(sciPoints($scope['customer_id']))->toBe(6);

    // an issued sale can be returned
    $sale->update(['status' => 'issue']);
    $sale->selllines()->update(['quantity_issue' => 2]);

    $this->postJson('/api/sell-returns', validSellReturnPayload($scope, $sale))->assertSuccessful();

    // 120 of the 600 came back: points on the 480 that stays
    expect(sciPoints($scope['customer_id']))->toBe(4);

    $returnId = Transaction::query()->sellReturns()->where('parent_id', $sale->id)->value('id');
    $this->deleteJson('/api/sell-returns/'.$returnId)->assertSuccessful();

    expect(sciPoints($scope['customer_id']))->toBe(6);
});

test('the manual award on the loyalty page still answers already awarded for a sale that earned at checkout', function () {
    $scope = sciScope();
    sciLoyaltyOn($scope);
    $user = User::query()->findOrFail(1);
    Sanctum::actingAs($user);
    $this->postJson('/api/sells', sciPayload($scope))->assertSuccessful();

    $this->postJson('/api/loyalty/earn', ['transaction_id' => sciSale()->id])->assertUnprocessable()->assertJsonPath('reason', 'already_awarded');

    expect(sciPoints($scope['customer_id']))->toBe(2);
});

// --- all three together ------------------------------------------------------------------------

test('a coupon, a gift card and loyalty on the same sale each do their part', function () {
    $scope = sciScope();
    sciSwitchOn($scope);
    sciLoyaltyOn($scope);
    sciDiscount($scope);
    $card = sciCard($scope, 100);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/sells', sciPayload($scope, [
        'discount_code' => 'TEN', 'coupon_discount_amount' => 24, 'final_amount' => 256,
        'gift_card_code' => 'GC-TEST', 'gift_card_amount' => 100,
    ]))->assertSuccessful()->assertJson(['final_amount' => 256, 'remaining_amount' => 156, 'payment_status' => 'partial']);

    $sale = sciSale();

    expect(SaleDiscount::query()->firstOrFail()->amount)->toBe(24.0)
        ->and((float) $card->refresh()->balance)->toBe(0.0)
        ->and((float) Payment::query()->where('transaction_id', $sale->id)->sum('amount'))->toBe(100.0)
        // points follow the value of the sale, 256, not what was paid by the card
        ->and(sciPoints($scope['customer_id']))->toBe(2);

    $show = $this->getJson('/api/sells/'.$sale->id)->assertSuccessful();

    expect($show->json('discount_code'))->toBe('TEN')
        ->and($show->json('gift_card_payments.0.amount'))->toBe(100)
        ->and($show->json('loyalty_points_earned'))->toBe(2)
        ->and($show->json('balance'))->toBe(156);
});

test('a sale that uses none of it behaves exactly as before', function () {
    $scope = sciScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/sells', sciPayload($scope))->assertSuccessful()->assertJson(['final_amount' => 280, 'remaining_amount' => 280, 'payment_status' => 'due']);

    expect(SaleDiscount::query()->count())->toBe(0)
        ->and(GiftCardEntry::query()->count())->toBe(0)
        ->and(LoyaltyPointEntry::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0);
});
