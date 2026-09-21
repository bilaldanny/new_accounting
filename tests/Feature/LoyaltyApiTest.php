<?php

use App\Models\Contact;
use App\Models\LoyaltyPointEntry;
use App\Models\LoyaltySetting;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LoyaltyPoints;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * @param  list<string>  $paths  the menu permissions the user's role is given
 */
function loyStaff(int $companyId, array $paths = []): User
{
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => $companyId, 'is_active' => true]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path);
    }

    return createStaffUserForRole($role, ['company_id' => $companyId]);
}

/**
 * Switches the programme on for a company: 1 point per 100 spent, a point worth 2, no minimum.
 *
 * @param  array<string, mixed>  $overrides
 */
function loyEnable(int $companyId, array $overrides = []): LoyaltySetting
{
    return LoyaltySetting::query()->updateOrCreate(['company_id' => $companyId], array_merge([
        'is_enabled' => true, 'amount_per_point' => 100, 'point_value' => 2, 'min_redeem_points' => 0,
    ], $overrides));
}

/**
 * A ledger line for a customer, keeping balance_after honest.
 */
function loyEntry(int $contactId, int $points, string $type = 'adjust', ?int $transactionId = null): LoyaltyPointEntry
{
    $contact = Contact::query()->findOrFail($contactId);
    $balance = (int) LoyaltyPointEntry::query()->where('contact_id', $contactId)->sum('points');

    return LoyaltyPointEntry::query()->create([
        'company_id' => $contact->company_id, 'contact_id' => $contactId, 'type' => $type, 'points' => $points,
        'balance_after' => $balance + $points, 'transaction_id' => $transactionId, 'note' => 'seed',
    ]);
}

function loyBalance(int $contactId): int
{
    return (int) LoyaltyPointEntry::query()->where('contact_id', $contactId)->sum('points');
}

// --- settings ----------------------------------------------------------------------------------

test('a company that never saved settings gets a switched-off default', function () {
    $scope = trpScope('LY1');
    Sanctum::actingAs(loyStaff($scope['company_id']));

    $this->getJson('/api/loyalty/settings')
        ->assertSuccessful()
        ->assertJson(['is_enabled' => false, 'amount_per_point' => 100.0, 'point_value' => 1.0, 'min_redeem_points' => 0]);

    expect(LoyaltySetting::query()->count())->toBe(0);
});

test('settings are saved per company and read back', function () {
    $scope = trpScope('LY1');
    Sanctum::actingAs(loyStaff($scope['company_id'], ['/loyalty/settings']));

    $this->putJson('/api/loyalty/settings', ['is_enabled' => true, 'amount_per_point' => 50, 'point_value' => 0.5, 'min_redeem_points' => 20])
        ->assertSuccessful()
        ->assertJson(['is_enabled' => true, 'amount_per_point' => 50.0, 'point_value' => 0.5, 'min_redeem_points' => 20]);

    $this->getJson('/api/loyalty/settings')->assertJson(['is_enabled' => true, 'amount_per_point' => 50.0, 'point_value' => 0.5, 'min_redeem_points' => 20]);

    // saving again updates the same row
    $this->putJson('/api/loyalty/settings', ['is_enabled' => false, 'amount_per_point' => 50, 'point_value' => 0.5])->assertSuccessful();

    expect(LoyaltySetting::query()->count())->toBe(1)
        ->and(LoyaltySetting::query()->firstOrFail()->is_enabled)->toBeFalse()
        ->and(LoyaltySetting::query()->firstOrFail()->min_redeem_points)->toBe(0);
});

test('settings validate their fields', function (array $overrides, string $field) {
    $scope = trpScope('LY1');
    Sanctum::actingAs(loyStaff($scope['company_id'], ['/loyalty/settings']));

    $this->putJson('/api/loyalty/settings', array_merge(['is_enabled' => true, 'amount_per_point' => 100, 'point_value' => 1, 'min_redeem_points' => 0], $overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(LoyaltySetting::query()->count())->toBe(0);
})->with([
    'no switch' => [['is_enabled' => null], 'is_enabled'],
    'switch not a boolean' => [['is_enabled' => 'maybe'], 'is_enabled'],
    'no spend per point' => [['amount_per_point' => null], 'amount_per_point'],
    'zero spend per point' => [['amount_per_point' => 0], 'amount_per_point'],
    'negative spend per point' => [['amount_per_point' => -1], 'amount_per_point'],
    'spend per point with three decimals' => [['amount_per_point' => 1.005], 'amount_per_point'],
    'spend per point too large' => [['amount_per_point' => 10000000], 'amount_per_point'],
    'zero point value' => [['point_value' => 0], 'point_value'],
    'point value not a number' => [['point_value' => 'lots'], 'point_value'],
    'negative minimum' => [['min_redeem_points' => -1], 'min_redeem_points'],
    'fractional minimum' => [['min_redeem_points' => 1.5], 'min_redeem_points'],
    'minimum too large' => [['min_redeem_points' => 1000001], 'min_redeem_points'],
]);

test('a company user always saves their own company settings and cannot touch another company', function () {
    $own = trpScope('LY1');
    $other = trpScope('LY2');
    Sanctum::actingAs(loyStaff($own['company_id'], ['/loyalty/settings']));

    $this->putJson('/api/loyalty/settings', ['company_id' => $other['company_id'], 'is_enabled' => true, 'amount_per_point' => 10, 'point_value' => 1])->assertSuccessful();

    expect(LoyaltySetting::query()->where('company_id', $own['company_id'])->exists())->toBeTrue()
        ->and(LoyaltySetting::query()->where('company_id', $other['company_id'])->exists())->toBeFalse();

    $this->getJson('/api/loyalty/settings?company_id='.$other['company_id'])->assertJson(['company_id' => $own['company_id'], 'amount_per_point' => 10.0]);
});

test('the superadmin has to name the company for settings', function () {
    $scope = trpScope('LY1');
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->getJson('/api/loyalty/settings')->assertUnprocessable();
    $this->putJson('/api/loyalty/settings', ['is_enabled' => true, 'amount_per_point' => 10, 'point_value' => 1])->assertUnprocessable()->assertJsonValidationErrors(['company_id']);
    $this->putJson('/api/loyalty/settings', ['company_id' => 999999, 'is_enabled' => true, 'amount_per_point' => 10, 'point_value' => 1])->assertUnprocessable()->assertJsonValidationErrors(['company_id']);

    $this->putJson('/api/loyalty/settings', ['company_id' => $scope['company_id'], 'is_enabled' => true, 'amount_per_point' => 10, 'point_value' => 1])->assertSuccessful();
    $this->getJson('/api/loyalty/settings?company_id='.$scope['company_id'])->assertJson(['is_enabled' => true]);
});

test('saving settings is forbidden without the permission', function () {
    $scope = trpScope('LY1');
    Sanctum::actingAs(loyStaff($scope['company_id']));

    $this->putJson('/api/loyalty/settings', ['is_enabled' => true, 'amount_per_point' => 10, 'point_value' => 1])->assertForbidden();

    expect(LoyaltySetting::query()->count())->toBe(0);
});

// --- the calculation ---------------------------------------------------------------------------

test('a sale earns whole points: its amount divided by the spend per point, rounded down', function (float $perPoint, float $amount, int $expected) {
    $setting = new LoyaltySetting(['amount_per_point' => $perPoint, 'point_value' => 1]);

    expect($setting->pointsFor($amount))->toBe($expected);
})->with([
    'just under one point' => [100.0, 99.99, 0],
    'exactly one point' => [100.0, 100.0, 1],
    'rounded down' => [100.0, 259.99, 2],
    'a large sale' => [100.0, 123456.78, 1234],
    'a small step' => [0.5, 3.3, 6],
    'float noise does not lose a point' => [0.1, 0.3, 3],
    'nothing spent' => [100.0, 0.0, 0],
    'a negative amount' => [100.0, -500.0, 0],
]);

test('points are worth their value to two decimals', function () {
    $setting = new LoyaltySetting(['amount_per_point' => 100, 'point_value' => 0.25]);

    expect($setting->valueOf(10))->toBe(2.5)
        ->and($setting->valueOf(3))->toBe(0.75)
        ->and($setting->valueOf(0))->toBe(0.0);
});

// --- earn --------------------------------------------------------------------------------------

test('awarding points for a sale writes an earn entry once', function () {
    $scope = trpScope('LY1');
    loyEnable($scope['company_id']);
    $sale = trpDoc($scope, 'sell', ['final_amount' => 259.99]);
    $user = loyStaff($scope['company_id'], ['/loyalty/earn']);
    Sanctum::actingAs($user);

    $this->postJson('/api/loyalty/earn', ['transaction_id' => $sale])
        ->assertSuccessful()
        ->assertJson(['points' => 2, 'balance' => 2, 'amount' => 259.99]);

    $entry = LoyaltyPointEntry::query()->firstOrFail();

    expect($entry->type)->toBe('earn')
        ->and($entry->contact_id)->toBe($scope['customer_id'])
        ->and($entry->transaction_id)->toBe($sale)
        ->and($entry->user_id)->toBe($user->id)
        ->and($entry->balance_after)->toBe(2);

    $this->postJson('/api/loyalty/earn', ['transaction_id' => $sale])->assertUnprocessable()->assertJsonPath('reason', 'already_awarded');

    expect(LoyaltyPointEntry::query()->count())->toBe(1);
});

test('points are refused for a sale that is not finished, is not a sale, has no customer or is too small', function (string $type, array $attributes, string $reason) {
    $scope = trpScope('LY1');
    loyEnable($scope['company_id']);
    $sale = trpDoc($scope, $type, array_merge(['final_amount' => 500], $attributes));
    Sanctum::actingAs(loyStaff($scope['company_id'], ['/loyalty/earn']));

    $this->postJson('/api/loyalty/earn', ['transaction_id' => $sale])->assertUnprocessable()->assertJsonPath('reason', $reason);

    expect(LoyaltyPointEntry::query()->count())->toBe(0);
})->with([
    'a draft' => ['sell', ['status' => 'draft'], 'not_a_sale'],
    'a quotation' => ['sell', ['status' => 'quotation'], 'not_a_sale'],
    'a purchase' => ['purchaseorder', [], 'not_a_sale'],
    'a sell return' => ['sellreturn', [], 'not_a_sale'],
    'no customer' => ['sell', ['contact_id' => null], 'no_customer'],
    'a sale under one point' => ['sell', ['final_amount' => 99.99], 'no_points'],
    'a free sale' => ['sell', ['final_amount' => 0], 'no_points'],
]);

test('nothing is awarded while the programme is switched off', function () {
    $scope = trpScope('LY1');
    loyEnable($scope['company_id'], ['is_enabled' => false]);
    $sale = trpDoc($scope, 'sell', ['final_amount' => 500]);
    Sanctum::actingAs(loyStaff($scope['company_id'], ['/loyalty/earn']));

    $this->postJson('/api/loyalty/earn', ['transaction_id' => $sale])->assertUnprocessable()->assertJsonPath('reason', 'disabled');

    // and a company with no settings at all is off too
    $other = trpScope('LY2');
    $otherSale = trpDoc($other, 'sell', ['final_amount' => 500]);
    Sanctum::actingAs(loyStaff($other['company_id'], ['/loyalty/earn']));

    $this->postJson('/api/loyalty/earn', ['transaction_id' => $otherSale])->assertUnprocessable()->assertJsonPath('reason', 'disabled');

    expect(LoyaltyPointEntry::query()->count())->toBe(0);
});

test('a company user cannot award points for the sale of another company, the superadmin can for any', function () {
    $own = trpScope('LY1');
    $other = trpScope('LY2');
    loyEnable($own['company_id']);
    loyEnable($other['company_id']);
    $theirSale = trpDoc($other, 'sell', ['final_amount' => 300]);
    Sanctum::actingAs(loyStaff($own['company_id'], ['/loyalty/earn']));

    $this->postJson('/api/loyalty/earn', ['transaction_id' => $theirSale])->assertNotFound();
    $this->postJson('/api/loyalty/earn', ['transaction_id' => 999999])->assertNotFound();
    $this->postJson('/api/loyalty/earn', [])->assertUnprocessable()->assertJsonValidationErrors(['transaction_id']);

    expect(LoyaltyPointEntry::query()->count())->toBe(0);

    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/loyalty/earn', ['transaction_id' => $theirSale])->assertSuccessful()->assertJson(['points' => 3]);

    expect(loyBalance($other['customer_id']))->toBe(3);
});

test('earning is forbidden without the permission', function () {
    $scope = trpScope('LY1');
    loyEnable($scope['company_id']);
    $sale = trpDoc($scope, 'sell', ['final_amount' => 500]);
    Sanctum::actingAs(loyStaff($scope['company_id']));

    $this->postJson('/api/loyalty/earn', ['transaction_id' => $sale])->assertForbidden();

    expect(LoyaltyPointEntry::query()->count())->toBe(0);
});

// --- redeem ------------------------------------------------------------------------------------

test('redeeming spends points and records their value', function () {
    $scope = trpScope('LY1');
    loyEnable($scope['company_id'], ['point_value' => 2.5]);
    loyEntry($scope['customer_id'], 40);
    $sale = trpDoc($scope, 'sell');
    $user = loyStaff($scope['company_id'], ['/loyalty/redeem']);
    Sanctum::actingAs($user);

    $this->postJson('/api/loyalty/redeem', ['contact_id' => $scope['customer_id'], 'points' => 12, 'note' => 'discount on bill', 'transaction_id' => $sale])
        ->assertSuccessful()
        ->assertJson(['points' => -12, 'balance' => 28, 'amount' => 30.0]);

    $entry = LoyaltyPointEntry::query()->where('type', 'redeem')->firstOrFail();

    expect(loyBalance($scope['customer_id']))->toBe(28)
        ->and($entry->transaction_id)->toBe($sale)
        ->and($entry->user_id)->toBe($user->id)
        ->and($entry->note)->toBe('discount on bill');
});

test('a customer can spend the whole balance but not one point more, and a refused redeem changes nothing', function () {
    $scope = trpScope('LY1');
    loyEnable($scope['company_id']);
    loyEntry($scope['customer_id'], 10);
    Sanctum::actingAs(loyStaff($scope['company_id'], ['/loyalty/redeem']));

    $this->postJson('/api/loyalty/redeem', ['contact_id' => $scope['customer_id'], 'points' => 11])->assertUnprocessable()->assertJsonPath('reason', 'insufficient_points');

    expect(loyBalance($scope['customer_id']))->toBe(10)
        ->and(LoyaltyPointEntry::query()->count())->toBe(1);

    $this->postJson('/api/loyalty/redeem', ['contact_id' => $scope['customer_id'], 'points' => 10])->assertSuccessful()->assertJson(['balance' => 0]);
    $this->postJson('/api/loyalty/redeem', ['contact_id' => $scope['customer_id'], 'points' => 1])->assertUnprocessable()->assertJsonPath('reason', 'insufficient_points');
});

test('the minimum redemption is enforced at its boundary', function () {
    $scope = trpScope('LY1');
    loyEnable($scope['company_id'], ['min_redeem_points' => 50]);
    loyEntry($scope['customer_id'], 200);
    Sanctum::actingAs(loyStaff($scope['company_id'], ['/loyalty/redeem']));

    $this->postJson('/api/loyalty/redeem', ['contact_id' => $scope['customer_id'], 'points' => 49])->assertUnprocessable()->assertJsonPath('reason', 'below_minimum');
    $this->postJson('/api/loyalty/redeem', ['contact_id' => $scope['customer_id'], 'points' => 50])->assertSuccessful();
});

test('nothing is redeemed while the programme is switched off', function () {
    $scope = trpScope('LY1');
    loyEnable($scope['company_id'], ['is_enabled' => false]);
    loyEntry($scope['customer_id'], 100);
    Sanctum::actingAs(loyStaff($scope['company_id'], ['/loyalty/redeem']));

    $this->postJson('/api/loyalty/redeem', ['contact_id' => $scope['customer_id'], 'points' => 10])->assertUnprocessable()->assertJsonPath('reason', 'disabled');

    expect(loyBalance($scope['customer_id']))->toBe(100);
});

test('redeem validates its input', function (array $body, string $field) {
    $scope = trpScope('LY1');
    loyEnable($scope['company_id']);
    loyEntry($scope['customer_id'], 100);
    Sanctum::actingAs(loyStaff($scope['company_id'], ['/loyalty/redeem']));

    $this->postJson('/api/loyalty/redeem', array_merge(['contact_id' => $scope['customer_id'], 'points' => 5], $body))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(loyBalance($scope['customer_id']))->toBe(100);
})->with([
    'no points' => [['points' => null], 'points'],
    'zero points' => [['points' => 0], 'points'],
    'negative points' => [['points' => -5], 'points'],
    'fractional points' => [['points' => 2.5], 'points'],
    'too many points' => [['points' => 1000001], 'points'],
    'no customer' => [['contact_id' => null], 'contact_id'],
    'note too long' => [['note' => str_repeat('n', 501)], 'note'],
    'unknown sale' => [['transaction_id' => 999999], 'transaction_id'],
]);

test('a redeem cannot name the sale of another company, and a foreign or supplier contact is not found', function () {
    $own = trpScope('LY1');
    $other = trpScope('LY2');
    loyEnable($own['company_id']);
    loyEntry($own['customer_id'], 100);
    $foreignSale = trpDoc($other, 'sell');
    Sanctum::actingAs(loyStaff($own['company_id'], ['/loyalty/redeem', '/loyalty/adjust']));

    $this->postJson('/api/loyalty/redeem', ['contact_id' => $own['customer_id'], 'points' => 5, 'transaction_id' => $foreignSale])->assertUnprocessable()->assertJsonValidationErrors(['transaction_id']);
    $this->postJson('/api/loyalty/redeem', ['contact_id' => $other['customer_id'], 'points' => 5])->assertNotFound();
    $this->postJson('/api/loyalty/redeem', ['contact_id' => $own['supplier_id'], 'points' => 5])->assertNotFound();
    $this->postJson('/api/loyalty/adjust', ['contact_id' => $own['supplier_id'], 'points' => 5, 'note' => 'nope'])->assertNotFound();

    expect(loyBalance($own['customer_id']))->toBe(100)
        ->and(LoyaltyPointEntry::query()->count())->toBe(1);
});

test('a stale request cannot overspend: the balance is re-read under the lock', function () {
    $scope = trpScope('LY1');
    loyEnable($scope['company_id']);
    loyEntry($scope['customer_id'], 100);
    $contact = Contact::query()->findOrFail($scope['customer_id']);
    $service = app(LoyaltyPoints::class);

    $service->redeem($contact, 80);

    expect(fn () => $service->redeem($contact, 80))->toThrow(RuntimeException::class, 'insufficient_points')
        ->and(loyBalance($scope['customer_id']))->toBe(20);
});

test('redeeming is forbidden without the permission', function () {
    $scope = trpScope('LY1');
    loyEnable($scope['company_id']);
    loyEntry($scope['customer_id'], 100);
    Sanctum::actingAs(loyStaff($scope['company_id'], ['/loyalty/earn', '/loyalty/adjust']));

    $this->postJson('/api/loyalty/redeem', ['contact_id' => $scope['customer_id'], 'points' => 5])->assertForbidden();

    expect(loyBalance($scope['customer_id']))->toBe(100);
});

// --- adjust ------------------------------------------------------------------------------------

test('a manual adjustment adds or removes points, even with the programme off', function () {
    $scope = trpScope('LY1');
    Sanctum::actingAs(loyStaff($scope['company_id'], ['/loyalty/adjust']));

    $this->postJson('/api/loyalty/adjust', ['contact_id' => $scope['customer_id'], 'points' => 30, 'note' => 'welcome bonus'])
        ->assertSuccessful()
        ->assertJson(['points' => 30, 'balance' => 30, 'amount' => null]);
    $this->postJson('/api/loyalty/adjust', ['contact_id' => $scope['customer_id'], 'points' => -12, 'note' => 'correction'])
        ->assertSuccessful()
        ->assertJson(['points' => -12, 'balance' => 18]);

    expect(loyBalance($scope['customer_id']))->toBe(18)
        ->and(LoyaltyPointEntry::query()->where('type', 'adjust')->count())->toBe(2);
});

test('an adjustment may empty the balance but never take it below zero', function () {
    $scope = trpScope('LY1');
    loyEntry($scope['customer_id'], 10);
    Sanctum::actingAs(loyStaff($scope['company_id'], ['/loyalty/adjust']));

    $this->postJson('/api/loyalty/adjust', ['contact_id' => $scope['customer_id'], 'points' => -11, 'note' => 'too much'])->assertUnprocessable()->assertJsonPath('reason', 'negative_balance');
    $this->postJson('/api/loyalty/adjust', ['contact_id' => $scope['customer_id'], 'points' => -10, 'note' => 'all of it'])->assertSuccessful()->assertJson(['balance' => 0]);

    expect(LoyaltyPointEntry::query()->count())->toBe(2);
});

test('adjust validates its input', function (array $body, string $field) {
    $scope = trpScope('LY1');
    Sanctum::actingAs(loyStaff($scope['company_id'], ['/loyalty/adjust']));

    $this->postJson('/api/loyalty/adjust', array_merge(['contact_id' => $scope['customer_id'], 'points' => 5, 'note' => 'a reason'], $body))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(LoyaltyPointEntry::query()->count())->toBe(0);
})->with([
    'no points' => [['points' => null], 'points'],
    'zero points' => [['points' => 0], 'points'],
    'fractional points' => [['points' => 1.5], 'points'],
    'too many points' => [['points' => 1000001], 'points'],
    'too few points' => [['points' => -1000001], 'points'],
    'no reason' => [['note' => ''], 'note'],
    'a reason that is too short' => [['note' => 'ab'], 'note'],
    'a reason that is too long' => [['note' => str_repeat('n', 501)], 'note'],
    'no customer' => [['contact_id' => null], 'contact_id'],
]);

test('adjusting is forbidden without the permission', function () {
    $scope = trpScope('LY1');
    Sanctum::actingAs(loyStaff($scope['company_id'], ['/loyalty/redeem']));

    $this->postJson('/api/loyalty/adjust', ['contact_id' => $scope['customer_id'], 'points' => 5, 'note' => 'a reason'])->assertForbidden();

    expect(LoyaltyPointEntry::query()->count())->toBe(0);
});

test('the balance always equals the sum of the ledger and every line records the balance after it', function () {
    $scope = trpScope('LY1');
    loyEnable($scope['company_id']);
    $contact = Contact::query()->findOrFail($scope['customer_id']);
    $service = app(LoyaltyPoints::class);

    $service->adjust($contact, 100, 'opening');
    $service->redeem($contact, 30);
    $service->earnForSale(Transaction::query()->findOrFail(trpDoc($scope, 'sell', ['final_amount' => 1234])));
    $service->adjust($contact, -5, 'fix');

    $entries = LoyaltyPointEntry::query()->orderBy('id')->get();

    expect($entries->pluck('balance_after')->all())->toBe([100, 70, 82, 77])
        ->and($service->balance($contact->id))->toBe(77)
        ->and($entries->sum('points'))->toBe(77);
});

// --- list and show -----------------------------------------------------------------------------

test('the list shows customers that have points activity with their balance, richest first', function () {
    $scope = trpScope('LY1');
    $second = trpContact($scope, 'Beta Traders', 'customer', 'CU-B');
    $third = trpContact($scope, 'Gamma Traders', 'both', 'CU-G');
    trpContact($scope, 'Idle Traders', 'customer', 'CU-I');
    loyEntry($scope['customer_id'], 40);
    loyEntry($second, 90);
    loyEntry($second, -20);
    loyEntry($third, 5);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $rows = $this->getJson('/api/loyalty')->assertSuccessful()->json('data.data');

    expect(collect($rows)->pluck('name')->all())->toBe(['Beta Traders', 'Acme Retail LY1', 'Gamma Traders'])
        ->and(collect($rows)->pluck('points_balance')->all())->toBe([70, 40, 5])
        ->and($rows[0]['company_name'])->toBe('Report Company LY1');
});

test('the list searches by name, mobile and code, sorts by an allowed column and ignores any other', function () {
    $scope = trpScope('LY1');
    $second = trpContact($scope, 'Beta Traders', 'customer', 'CU-B');
    DB::table('contacts')->where('id', $second)->update(['mobile' => '0345-5551234']);
    loyEntry($scope['customer_id'], 40);
    loyEntry($second, 90);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $names = fn (array $query) => collect($this->getJson('/api/loyalty?'.http_build_query($query))->assertSuccessful()->json('data.data'))->pluck('name')->all();

    expect($names(['search' => 'Beta']))->toBe(['Beta Traders'])
        ->and($names(['search' => '5551234']))->toBe(['Beta Traders'])
        ->and($names(['search' => 'CU-LY1']))->toBe(['Acme Retail LY1'])
        ->and($names(['search' => 'nothing matches']))->toBe([])
        ->and($names(['sort_by' => 'points_balance', 'sort_type' => 'asc']))->toBe(['Acme Retail LY1', 'Beta Traders'])
        ->and($names(['sort_by' => 'business_name', 'sort_type' => 'asc']))->toBe(['Acme Retail LY1', 'Beta Traders']);

    $this->getJson('/api/loyalty?sort_by='.urlencode('id; drop table contacts').'&sort_type=sideways')->assertSuccessful();
    $this->getJson('/api/loyalty?sort_by=password')->assertSuccessful();

    expect(Contact::query()->count())->toBeGreaterThan(0);
});

test('a company user sees only the customers of their own company', function () {
    $own = trpScope('LY1');
    $other = trpScope('LY2');
    loyEntry($own['customer_id'], 10);
    loyEntry($other['customer_id'], 20);
    Sanctum::actingAs(loyStaff($own['company_id']));

    expect(collect($this->getJson('/api/loyalty')->assertSuccessful()->json('data.data'))->pluck('name')->all())->toBe(['Acme Retail LY1']);

    $this->getJson('/api/loyalty?company_id='.$other['company_id'])->assertSuccessful()->assertJsonCount(0, 'data.data');
    $this->getJson('/api/loyalty/'.$other['customer_id'])->assertNotFound();
    $this->getJson('/api/loyalty/'.$own['supplier_id'])->assertNotFound();
    $this->getJson('/api/loyalty/999999')->assertNotFound();
});

test('a customer detail has the balance, its value, the lifetime totals, the settings and the ledger newest first', function () {
    $scope = trpScope('LY1');
    loyEnable($scope['company_id'], ['point_value' => 2]);
    $sale = trpDoc($scope, 'sell');
    loyEntry($scope['customer_id'], 100, 'earn', $sale);
    loyEntry($scope['customer_id'], 20, 'adjust');
    loyEntry($scope['customer_id'], -30, 'redeem');
    loyEntry($scope['customer_id'], -5, 'adjust');
    Sanctum::actingAs(loyStaff($scope['company_id']));

    $response = $this->getJson('/api/loyalty/'.$scope['customer_id'])->assertSuccessful();

    expect($response->json('name'))->toBe('Acme Retail LY1')
        ->and($response->json('balance'))->toBe(85)
        ->and($response->json('balance_value'))->toBe(170)
        ->and($response->json('earned_total'))->toBe(120)
        ->and($response->json('redeemed_total'))->toBe(30)
        ->and($response->json('settings.is_enabled'))->toBeTrue()
        ->and(collect($response->json('entries'))->pluck('points')->all())->toBe([-5, -30, 20, 100]);
});

test('a customer detail works for a company that never saved settings', function () {
    $scope = trpScope('LY1');
    loyEntry($scope['customer_id'], 10);
    Sanctum::actingAs(loyStaff($scope['company_id']));

    $this->getJson('/api/loyalty/'.$scope['customer_id'])->assertSuccessful()->assertJsonPath('settings.is_enabled', false)->assertJsonPath('balance', 10);
});

test('the loyalty api requires authentication', function () {
    $this->getJson('/api/loyalty')->assertUnauthorized();
    $this->getJson('/api/loyalty/1')->assertUnauthorized();
    $this->getJson('/api/loyalty/settings')->assertUnauthorized();
    $this->putJson('/api/loyalty/settings', [])->assertUnauthorized();
    $this->postJson('/api/loyalty/earn', [])->assertUnauthorized();
    $this->postJson('/api/loyalty/redeem', [])->assertUnauthorized();
    $this->postJson('/api/loyalty/adjust', [])->assertUnauthorized();
});

// --- pages -------------------------------------------------------------------------------------

test('guests are sent away from the loyalty pages', function (string $routeName, array $parameters) {
    $this->get(route($routeName, $parameters))->assertRedirect();
})->with([
    'list' => ['loyalty', []],
    'settings' => ['loyalty.settings', []],
    'view' => ['loyalty.view', [3]],
]);

test('the superadmin can open every loyalty page', function (string $routeName, array $parameters, string $component) {
    $this->actingAs(User::query()->findOrFail(1))
        ->get(route($routeName, $parameters))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component($component));
})->with([
    'list' => ['loyalty', [], 'loyalty/index'],
    'settings' => ['loyalty.settings', [], 'loyalty/settings'],
    'view' => ['loyalty.view', [3], 'loyalty/view'],
]);

test('the view page passes the customer id', function () {
    $this->actingAs(User::query()->findOrFail(1))->get(route('loyalty.view', 12))
        ->assertInertia(fn ($page) => $page->component('loyalty/view')->where('id', '12'));
});

test('a user is let into exactly the loyalty pages their menu permissions name', function () {
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true]);
    grantMenuPermission($role->id, '/loyalty');
    grantMenuPermission($role->id, '/loyalty/:id/view');
    $user = createStaffUserForRole($role);

    $this->actingAs($user)->get(route('loyalty'))->assertSuccessful();
    $this->actingAs($user)->get(route('loyalty.view', 3))->assertSuccessful();
    $this->actingAs($user)->get(route('loyalty.settings'))->assertForbidden();

    $bare = createStaffUserForRole(Role::query()->create(['name' => 'companyadmin', 'company_id' => null, 'is_active' => true]));

    foreach (['loyalty' => [], 'loyalty.settings' => [], 'loyalty.view' => [3]] as $name => $parameters) {
        $this->actingAs($bare)->get(route($name, $parameters))->assertForbidden();
    }
});
