<?php

use App\Models\User;
use App\Services\Rental\RentalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Wave 8, rental: items and the booking calendar (no overbooking), deposit at pickup, return with inspection, late charge and damage
 * billing, and the guarded ledger posting. Posting is off by default and the guarantee test says the ledger does not move without it.
 */
function w8rStaff(array $scope, array $paths = ['/rental', '/rental/manage', '/rental/book']): User
{
    return fcoStaff($scope, $paths, false);
}

/**
 * @return array<string, mixed>
 */
function w8rWorld(): array
{
    $scope = fcoScope();
    $scope['tent'] = prdProduct($scope, 'Marquee tent', [], ['default_purchase_price' => 5000, 'default_sell_price' => 8000]);

    return $scope;
}

function w8rItem(array $scope, array $extra = []): int
{
    return (int) test()->postJson('/api/rental/items', $extra + ['product_id' => $scope['tent']['product_id'], 'units' => 3, 'daily_rate' => 100, 'deposit_amount' => 500, 'late_fee_per_day' => 150])
        ->assertCreated()->json('data.id');
}

function w8rBook(array $scope, int $item, string $from, string $to, int $quantity = 1, ?int $contact = null)
{
    return test()->postJson('/api/rental/bookings', ['rental_item_id' => $item, 'contact_id' => $contact ?? $scope['contact_id'], 'quantity' => $quantity, 'start_date' => $from, 'end_date' => $to]);
}

function w8rPostingOn(array $scope): void
{
    app(RentalSettings::class)->save($scope['company_id'], [
        'posting_enabled' => true,
        'accounts' => ['rental_income' => $scope['revenue_id'], 'late_fee_income' => $scope['insurance_id'], 'damage_income' => $scope['deferred_id'], 'deposit_liability' => $scope['accrued_id']],
    ]);
}

test('a booking is priced from the item and cannot overbook it on any day of the dates', function () {
    $scope = w8rWorld();
    Sanctum::actingAs(w8rStaff($scope));
    $item = w8rItem($scope);

    $first = (int) w8rBook($scope, $item, '2026-10-10', '2026-10-12', 2)->assertCreated()->json('data.id');
    $row = DB::table('rental_bookings')->where('id', $first)->first();

    // 3 days x 100 x 2 units; deposit and late fee are per unit
    expect((float) $row->rent_amount)->toBe(600.0)->and((float) $row->deposit_amount)->toBe(1000.0)->and((float) $row->late_fee_per_day)->toBe(300.0)->and($row->status)->toBe('reserved');

    w8rBook($scope, $item, '2026-10-12', '2026-10-14', 2)->assertUnprocessable();
    w8rBook($scope, $item, '2026-10-12', '2026-10-14', 1)->assertCreated();
    w8rBook($scope, $item, '2026-10-13', '2026-10-13', 2)->assertCreated();
    w8rBook($scope, $item, '2026-10-12', '2026-10-12', 1)->assertUnprocessable();
    w8rBook($scope, $item, '2026-10-14', '2026-10-13', 1)->assertUnprocessable();

    expect($this->getJson("/api/rental/availability?item_id={$item}&from=2026-10-10&to=2026-10-11")->json('data.free'))->toBe(1)
        ->and($this->getJson("/api/rental/availability?item_id={$item}&from=2026-10-20&to=2026-10-21")->json('data.free'))->toBe(3);

    // cancelling frees the days again
    $this->postJson("/api/rental/bookings/{$first}/cancel")->assertOk();
    w8rBook($scope, $item, '2026-10-10', '2026-10-11', 2)->assertCreated();

    $calendar = $this->getJson('/api/rental/calendar?from=2026-10-01&to=2026-10-31')->assertOk()->json('data.0');
    expect($calendar['units'])->toBe(3)->and(count($calendar['bookings']))->toBe(3);
});

test('a return works out late days, late and damage charges, applies the deposit and refunds the rest', function () {
    $scope = w8rWorld();
    Sanctum::actingAs(w8rStaff($scope));
    $item = w8rItem($scope);
    $id = (int) w8rBook($scope, $item, '2026-10-10', '2026-10-12', 1)->assertCreated()->json('data.id');

    $this->postJson("/api/rental/bookings/{$id}/return", ['condition' => 'good'])->assertUnprocessable();
    $this->postJson("/api/rental/bookings/{$id}/pickup", ['date' => '2026-10-10'])->assertOk();
    $this->postJson("/api/rental/bookings/{$id}/pickup")->assertUnprocessable();
    expect((float) DB::table('rental_bookings')->where('id', $id)->value('deposit_received'))->toBe(500.0);

    // on time and undamaged: rent 300 out of the 500 deposit, 200 refunded
    $ontime = $this->postJson("/api/rental/bookings/{$id}/return", ['returned_on' => '2026-10-12', 'condition' => 'good'])->assertOk()->json('data');
    expect($ontime)->toMatchArray(['late_days' => 0, 'charges' => 300.0, 'deposit_applied' => 300.0, 'deposit_refund' => 200.0, 'amount_due' => 0.0]);

    // 2 days late with damage: 300 + 300 + 400 = 1000, deposit 500 all applied, 500 due
    $late = (int) w8rBook($scope, $item, '2026-11-10', '2026-11-12', 1)->assertCreated()->json('data.id');
    $this->postJson("/api/rental/bookings/{$late}/pickup", ['date' => '2026-11-10'])->assertOk();
    $result = $this->postJson("/api/rental/bookings/{$late}/return", ['returned_on' => '2026-11-14', 'condition' => 'major_damage', 'damage_charge' => 400, 'notes' => 'Torn side panel'])->assertOk()->json('data');

    expect($result)->toMatchArray(['late_days' => 2, 'late_charge' => 300.0, 'damage_charge' => 400.0, 'charges' => 1000.0, 'deposit_applied' => 500.0, 'deposit_refund' => 0.0, 'amount_due' => 500.0])
        ->and(DB::table('rental_bookings')->where('id', $late)->value('condition'))->toBe('major_damage')
        ->and(DB::table('rental_bookings')->where('id', $late)->value('status'))->toBe('returned');
    $this->postJson("/api/rental/bookings/{$late}/return", ['condition' => 'good'])->assertUnprocessable();
});

test('an item that is still out stays taken after its end date until it is brought back', function () {
    $scope = w8rWorld();
    Sanctum::actingAs(w8rStaff($scope));
    $item = w8rItem($scope, ['units' => 1]);
    $id = (int) w8rBook($scope, $item, '2026-01-05', '2026-01-06')->assertCreated()->json('data.id');
    $this->postJson("/api/rental/bookings/{$id}/pickup", ['date' => '2026-01-05'])->assertOk();

    $today = now()->toDateString();
    w8rBook($scope, $item, $today, $today)->assertUnprocessable();
    expect(collect($this->getJson('/api/rental/calendar?from='.$today.'&to='.$today)->json('data.0.bookings'))->first()['overdue'])->toBeTrue();

    $this->postJson("/api/rental/bookings/{$id}/return", ['returned_on' => $today, 'condition' => 'good'])->assertOk();
    w8rBook($scope, $item, $today, $today)->assertCreated();
});

test('with posting off bookings, deposits, returns and charges leave vouchers, ledger and trial balance exactly as they were', function () {
    $scope = w8rWorld();
    Sanctum::actingAs(w8rStaff($scope));
    $before = [DB::table('t_accounts')->where('company_id', $scope['company_id'])->count(), DB::table('t_account_details')->count(), fcoTrialBalance($scope['company_id'])];

    $item = w8rItem($scope);
    $id = (int) w8rBook($scope, $item, '2026-10-10', '2026-10-12')->assertCreated()->json('data.id');
    $this->postJson("/api/rental/bookings/{$id}/pickup", ['date' => '2026-10-10', 'payment_account_id' => $scope['cash_id']])->assertOk();
    $this->postJson("/api/rental/bookings/{$id}/return", ['returned_on' => '2026-10-15', 'condition' => 'minor_damage', 'damage_charge' => 50])->assertOk();

    expect([DB::table('t_accounts')->where('company_id', $scope['company_id'])->count(), DB::table('t_account_details')->count(), fcoTrialBalance($scope['company_id'])])->toBe($before)
        ->and(DB::table('rental_bookings')->whereNotNull('pickup_voucher_id')->orWhereNotNull('return_voucher_id')->count())->toBe(0);
});

test('with posting on the deposit and the return post balanced entries, the customer ends at what is due, and a locked month refuses the return', function () {
    $scope = w8rWorld();
    Sanctum::actingAs(w8rStaff($scope));
    $item = w8rItem($scope);
    $id = (int) w8rBook($scope, $item, '2026-10-10', '2026-10-12')->assertCreated()->json('data.id');

    // switched on but not mapped
    DB::table('rental_settings')->insert(['company_id' => $scope['company_id'], 'settings' => json_encode(['posting_enabled' => true, 'accounts' => []]), 'created_at' => now(), 'updated_at' => now()]);
    $this->postJson("/api/rental/bookings/{$id}/pickup", ['date' => '2026-10-10', 'payment_account_id' => $scope['cash_id']])->assertUnprocessable()->assertJsonValidationErrors('accounts');
    w8rPostingOn($scope);

    $this->postJson("/api/rental/bookings/{$id}/pickup", ['date' => '2026-10-10'])->assertUnprocessable()->assertJsonValidationErrors('payment_account_id');
    $this->postJson("/api/rental/bookings/{$id}/pickup", ['date' => '2026-10-10', 'payment_account_id' => $scope['cash_id']])->assertOk();

    expect(fcoBalance($scope['cash_id']))->toBe(500.0)->and(fcoBalance($scope['accrued_id']))->toBe(-500.0);

    // the month is locked: the return is refused and nothing changes
    fcoLockThrough($scope['company_id'], '2026-10-31');
    $this->postJson("/api/rental/bookings/{$id}/return", ['returned_on' => '2026-10-12', 'condition' => 'good', 'refund_account_id' => $scope['cash_id']])->assertUnprocessable();
    expect(DB::table('rental_bookings')->where('id', $id)->value('status'))->toBe('out')->and(fcoBalance($scope['cash_id']))->toBe(500.0);
    DB::table('period_locks')->delete();

    // a refund account is needed when something is refunded
    $this->postJson("/api/rental/bookings/{$id}/return", ['returned_on' => '2026-10-12', 'condition' => 'good'])->assertUnprocessable()->assertJsonValidationErrors('refund_account_id');
    $this->postJson("/api/rental/bookings/{$id}/return", ['returned_on' => '2026-10-12', 'condition' => 'good', 'refund_account_id' => $scope['cash_id']])->assertOk();

    // rent 300 earned, deposit 500 released: 300 applied to the rent, 200 refunded; the customer owes nothing
    expect(fcoBalance($scope['revenue_id']))->toBe(-300.0)->and(fcoBalance($scope['accrued_id']))->toBe(0.0)->and(fcoBalance($scope['cash_id']))->toBe(300.0)
        ->and(fcoBalance($scope['customer_coa_id']))->toBe(0.0)->and(fcoTrialBalance($scope['company_id'])['debit'])->toBe(fcoTrialBalance($scope['company_id'])['credit']);

    // a damaged, late return leaves the customer with the amount due
    $second = (int) w8rBook($scope, $item, '2026-11-10', '2026-11-11')->assertCreated()->json('data.id');
    $this->postJson("/api/rental/bookings/{$second}/pickup", ['date' => '2026-11-10', 'payment_account_id' => $scope['cash_id']])->assertOk();
    $this->postJson("/api/rental/bookings/{$second}/return", ['returned_on' => '2026-11-12', 'condition' => 'minor_damage', 'damage_charge' => 600, 'refund_account_id' => $scope['cash_id']])->assertOk();

    // rent 200 + late 150 + damage 600 = 950, deposit 500 applied, 450 due from the customer
    expect(fcoBalance($scope['customer_coa_id']))->toBe(450.0)->and(fcoBalance($scope['insurance_id']))->toBe(-150.0)->and(fcoBalance($scope['deferred_id']))->toBe(-600.0)
        ->and(fcoTrialBalance($scope['company_id'])['debit'])->toBe(fcoTrialBalance($scope['company_id'])['credit']);
});

test('items, bookings and customers of another company are not reachable and every action needs its permission', function () {
    $scope = w8rWorld();
    $other = fcoOtherCompany();
    $theirs = $other + ['tent' => prdProduct($other, 'Their tent')];
    Sanctum::actingAs(w8rStaff($other));
    $theirItem = w8rItem($theirs);

    Sanctum::actingAs(w8rStaff($scope));
    $mine = w8rItem($scope);
    w8rBook($scope, $theirItem, '2026-10-10', '2026-10-11')->assertUnprocessable();
    $this->putJson("/api/rental/items/{$theirItem}", ['product_id' => $scope['tent']['product_id'], 'units' => 9, 'daily_rate' => 1])->assertNotFound();
    $this->postJson('/api/rental/items', ['product_id' => $theirs['tent']['product_id'], 'units' => 1, 'daily_rate' => 1])->assertUnprocessable();
    $this->postJson('/api/rental/items', ['product_id' => $scope['tent']['product_id'], 'units' => 1, 'daily_rate' => 1])->assertUnprocessable();
    $this->getJson("/api/rental/availability?item_id={$theirItem}&from=2026-10-10&to=2026-10-11")->assertNotFound();

    $theirContact = (int) DB::table('contacts')->where('company_id', $other['company_id'])->value('id') ?: 99999;
    w8rBook($scope, $mine, '2026-10-10', '2026-10-11', 1, $theirContact)->assertUnprocessable();

    $booking = (int) w8rBook($scope, $mine, '2026-10-10', '2026-10-11')->assertCreated()->json('data.id');
    Sanctum::actingAs(w8rStaff($other));
    $this->getJson("/api/rental/bookings/{$booking}")->assertNotFound();
    $this->postJson("/api/rental/bookings/{$booking}/pickup")->assertNotFound();
    $this->postJson("/api/rental/bookings/{$booking}/cancel")->assertNotFound();

    Sanctum::actingAs(w8rStaff($scope, ['/rental']));
    $this->getJson('/api/rental/items')->assertOk();
    $this->postJson('/api/rental/items', ['product_id' => 1, 'units' => 1, 'daily_rate' => 1])->assertForbidden();
    w8rBook($scope, $mine, '2026-10-20', '2026-10-21')->assertForbidden();
    $this->putJson('/api/rental/settings', ['posting_enabled' => true])->assertForbidden();

    Sanctum::actingAs(w8rStaff($scope));
    $this->putJson('/api/rental/settings', ['accounts' => ['rental_income' => 99999]])->assertUnprocessable();
    expect($this->getJson('/api/rental/settings')->json('data.posting_enabled'))->toBeFalse();
});
