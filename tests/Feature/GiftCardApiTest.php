<?php

use App\Models\GiftCard;
use App\Models\GiftCardEntry;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function gcdCompany(string $code = 'GCD001'): int
{
    return DB::table('companies')->insertGetId([
        'code' => $code,
        'name' => 'Company '.$code,
        'address' => '1 Test Street',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  list<string>  $paths  the menu permissions the user's role is given
 */
function gcdStaff(int $companyId, array $paths = []): User
{
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => $companyId, 'is_active' => true]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path);
    }

    return createStaffUserForRole($role, ['company_id' => $companyId]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function gcdPayload(int $companyId, array $overrides = []): array
{
    return array_merge([
        'company_id' => $companyId,
        'code' => 'GIFT-100',
        'initial_value' => 1000,
        'expires_at' => now()->addYear()->toDateString(),
        'note' => 'Eid gift',
        'is_active' => true,
    ], $overrides);
}

/**
 * A card with its `issue` entry, the way the API creates one.
 *
 * @param  array<string, mixed>  $attributes
 */
function gcdMake(int $companyId, array $attributes = []): GiftCard
{
    $value = (float) ($attributes['initial_value'] ?? 100);

    $card = GiftCard::query()->create(array_merge([
        'company_id' => $companyId,
        'code' => 'CARD-'.strtoupper(substr(md5((string) microtime(true).random_int(1, 999999)), 0, 8)),
        'initial_value' => $value,
        'balance' => $value,
        'is_active' => true,
    ], $attributes));

    $card->entries()->create(['type' => 'issue', 'amount' => $value, 'balance_after' => (float) $card->balance]);

    return $card;
}

// --- create ------------------------------------------------------------------------------------

test('gift cards api issues a card with the given fields and an issue entry', function () {
    $companyId = gcdCompany('GCD001');
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/gift-cards', gcdPayload($companyId))
        ->assertSuccessful()
        ->assertJsonPath('code', 'GIFT-100');

    $card = GiftCard::query()->where('company_id', $companyId)->firstOrFail();

    expect($card->code)->toBe('GIFT-100')
        ->and((float) $card->initial_value)->toBe(1000.0)
        ->and((float) $card->balance)->toBe(1000.0)
        ->and($card->expires_at->toDateString())->toBe(now()->addYear()->toDateString())
        ->and($card->note)->toBe('Eid gift')
        ->and($card->is_active)->toBeTrue()
        ->and($card->entries)->toHaveCount(1)
        ->and($card->entries->first()->type)->toBe('issue')
        ->and((float) $card->entries->first()->amount)->toBe(1000.0)
        ->and((float) $card->entries->first()->balance_after)->toBe(1000.0);
});

test('a blank code is generated: GC- and ten unambiguous characters, different every time', function () {
    $companyId = gcdCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $codes = [];

    foreach (range(1, 5) as $ignored) {
        $codes[] = $this->postJson('/api/gift-cards', gcdPayload($companyId, ['code' => '']))->assertSuccessful()->json('code');
    }

    expect($codes)->each->toMatch('/^GC-[A-HJ-NP-Z2-9]{10}$/')
        ->and(array_unique($codes))->toHaveCount(5);
});

test('the code is trimmed and stored upper case; only a value is needed', function () {
    $companyId = gcdCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/gift-cards', ['company_id' => $companyId, 'code' => ' vip-7 ', 'initial_value' => 250.5])->assertSuccessful();

    $card = GiftCard::query()->firstOrFail();

    expect($card->code)->toBe('VIP-7')
        ->and((float) $card->balance)->toBe(250.5)
        ->and($card->expires_at)->toBeNull()
        ->and($card->note)->toBeNull()
        ->and($card->contact_id)->toBeNull()
        ->and($card->is_active)->toBeTrue();
});

test('gift cards api validates the fields', function (array $overrides, string $field) {
    $companyId = gcdCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/gift-cards', gcdPayload($companyId, $overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(GiftCard::query()->count())->toBe(0);
})->with([
    'no value' => [['initial_value' => null], 'initial_value'],
    'zero value' => [['initial_value' => 0], 'initial_value'],
    'negative value' => [['initial_value' => -5], 'initial_value'],
    'value not a number' => [['initial_value' => 'lots'], 'initial_value'],
    'value with three decimals' => [['initial_value' => 10.005], 'initial_value'],
    'value too large' => [['initial_value' => 10000000], 'initial_value'],
    'code with a space' => [['code' => 'GIFT 100'], 'code'],
    'code with symbols' => [['code' => 'GIFT#1'], 'code'],
    'code too long' => [['code' => str_repeat('A', 51)], 'code'],
    'expiry in the past' => [['expires_at' => '2020-01-01'], 'expires_at'],
    'expiry in a wrong format' => [['expires_at' => '31/12/2030'], 'expires_at'],
    'note too long' => [['note' => str_repeat('n', 501)], 'note'],
    'status not a boolean' => [['is_active' => 'maybe'], 'is_active'],
    'unknown holder' => [['contact_id' => 999999], 'contact_id'],
    'unknown company' => [['company_id' => 999999], 'company_id'],
    'no company for the superadmin' => [['company_id' => null], 'company_id'],
]);

test('the boundary values are accepted: a card that expires today, the smallest and the largest value', function () {
    $companyId = gcdCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/gift-cards', gcdPayload($companyId, ['code' => 'TODAY', 'expires_at' => now()->toDateString()]))->assertSuccessful();
    $this->postJson('/api/gift-cards', gcdPayload($companyId, ['code' => 'PENNY', 'initial_value' => 0.01]))->assertSuccessful();
    $this->postJson('/api/gift-cards', gcdPayload($companyId, ['code' => 'BIG', 'initial_value' => 9999999.99]))->assertSuccessful();

    expect(GiftCard::query()->count())->toBe(3);
});

test('a code is unique in a company, whatever its case and even against a trashed card, but another company may reuse it', function () {
    $companyId = gcdCompany('GCD001');
    $otherCompany = gcdCompany('GCD002');
    $trashed = gcdMake($companyId, ['code' => 'SAVED5']);
    $trashed->delete();
    gcdMake($companyId, ['code' => 'LIVE5']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/gift-cards', gcdPayload($companyId, ['code' => 'live5']))->assertUnprocessable()->assertJsonValidationErrors(['code']);
    $this->postJson('/api/gift-cards', gcdPayload($companyId, ['code' => 'SAVED5']))->assertUnprocessable()->assertJsonValidationErrors(['code']);
    $this->postJson('/api/gift-cards', gcdPayload($otherCompany, ['code' => 'LIVE5']))->assertSuccessful();
});

test('a holder has to be a live contact of the same company', function () {
    $scope = trpScope('GC1');
    $otherScope = trpScope('GC2');
    $trashed = trpContact($scope, 'Gone Traders', 'customer', 'CU-GONE');
    DB::table('contacts')->where('id', $trashed)->update(['deleted_at' => now()]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/gift-cards', gcdPayload($scope['company_id'], ['contact_id' => $otherScope['customer_id']]))->assertUnprocessable()->assertJsonValidationErrors(['contact_id']);
    $this->postJson('/api/gift-cards', gcdPayload($scope['company_id'], ['contact_id' => $trashed, 'code' => 'B']))->assertUnprocessable()->assertJsonValidationErrors(['contact_id']);
    $this->postJson('/api/gift-cards', gcdPayload($scope['company_id'], ['contact_id' => $scope['customer_id'], 'code' => 'C']))->assertSuccessful();

    expect(GiftCard::query()->firstOrFail()->contact_id)->toBe($scope['customer_id']);
});

// --- list, show --------------------------------------------------------------------------------

test('gift cards index lists cards with company and holder names', function () {
    $scope = trpScope('GC1');
    gcdMake($scope['company_id'], ['code' => 'HELD', 'contact_id' => $scope['customer_id']]);
    gcdMake($scope['company_id'], ['code' => 'BEARER']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->getJson('/api/gift-cards?sort_by=code&sort_type=asc')->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(2)
        ->and($response->json('data.data.0.code'))->toBe('BEARER')
        ->and($response->json('data.data.0.contact_name'))->toBeNull()
        ->and($response->json('data.data.1.contact_name'))->toBe('Acme Retail GC1')
        ->and($response->json('data.data.1.company_name'))->toBe('Report Company GC1')
        ->and($response->json('trash_count'))->toBe(0);
});

test('the holder is shown by first and last name when the contact has no business name', function () {
    $scope = trpScope('GC1');
    $person = trpContact($scope, null, 'customer', 'CU-P', 'Sara', 'Khan');
    gcdMake($scope['company_id'], ['contact_id' => $person]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    expect($this->getJson('/api/gift-cards')->json('data.data.0.contact_name'))->toBe('Sara Khan');
});

test('gift cards index searches the code, note and holder and filters by status', function () {
    $scope = trpScope('GC1');
    gcdMake($scope['company_id'], ['code' => 'ALPHA1', 'note' => 'wedding']);
    gcdMake($scope['company_id'], ['code' => 'BRAVO2', 'is_active' => false, 'contact_id' => $scope['customer_id']]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $codes = fn (array $query) => collect($this->getJson('/api/gift-cards?'.http_build_query($query))->assertSuccessful()->json('data.data'))->pluck('code')->sort()->values()->all();

    expect($codes(['search' => 'alpha']))->toBe(['ALPHA1'])
        ->and($codes(['search' => 'wedding']))->toBe(['ALPHA1'])
        ->and($codes(['search' => 'Acme']))->toBe(['BRAVO2'])
        ->and($codes(['status' => '0']))->toBe(['BRAVO2'])
        ->and($codes(['status' => '1']))->toBe(['ALPHA1'])
        ->and($codes(['status' => 'all']))->toHaveCount(2)
        ->and($codes(['search' => 'nothing matches']))->toBe([]);
});

test('gift cards index sorts by an allowed column and ignores any other sort column', function () {
    $companyId = gcdCompany();
    gcdMake($companyId, ['code' => 'C-CARD']);
    gcdMake($companyId, ['code' => 'A-CARD']);
    gcdMake($companyId, ['code' => 'B-CARD']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $sorted = $this->getJson('/api/gift-cards?sort_by=code&sort_type=asc')->assertSuccessful();

    expect(collect($sorted->json('data.data'))->pluck('code')->all())->toBe(['A-CARD', 'B-CARD', 'C-CARD']);

    $this->getJson('/api/gift-cards?sort_by='.urlencode('id; drop table gift_cards').'&sort_type=sideways')->assertSuccessful();
    $this->getJson('/api/gift-cards?sort_by=password')->assertSuccessful();

    expect(GiftCard::query()->count())->toBe(3);
});

test('gift cards show returns the card with its company, holder and ledger, newest entry first', function () {
    $scope = trpScope('GC1');
    $card = gcdMake($scope['company_id'], ['code' => 'SHOWN', 'initial_value' => 100, 'contact_id' => $scope['customer_id']]);
    $card->topUp(50, 'reload');
    $card->redeem(30, 'lunch');
    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->getJson('/api/gift-cards/'.$card->id)->assertSuccessful();

    expect($response->json('code'))->toBe('SHOWN')
        ->and($response->json('company.name'))->toBe('Report Company GC1')
        ->and($response->json('contact_name'))->toBe('Acme Retail GC1')
        ->and(collect($response->json('entries'))->pluck('type')->all())->toBe(['redeem', 'topup', 'issue'])
        ->and(collect($response->json('entries'))->pluck('balance_after')->map(fn ($v) => (float) $v)->all())->toBe([120.0, 150.0, 100.0]);

    $this->getJson('/api/gift-cards/999999')->assertNotFound();
});

// --- update, delete, status --------------------------------------------------------------------

test('an edit changes only the holder, expiry, note and status, never the code or the money', function () {
    $scope = trpScope('GC1');
    $card = gcdMake($scope['company_id'], ['code' => 'FIXED', 'initial_value' => 100]);
    $card->redeem(40);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->putJson('/api/gift-cards/'.$card->id, [
        'company_id' => gcdCompany('GCD009'),
        'code' => 'CHANGED',
        'initial_value' => 99999,
        'balance' => 99999,
        'contact_id' => $scope['customer_id'],
        'expires_at' => '2020-01-01',
        'note' => 'edited',
        'is_active' => false,
    ])->assertSuccessful();

    $card->refresh();

    expect($card->code)->toBe('FIXED')
        ->and((float) $card->initial_value)->toBe(100.0)
        ->and((float) $card->balance)->toBe(60.0)
        ->and($card->company_id)->toBe($scope['company_id'])
        ->and($card->contact_id)->toBe($scope['customer_id'])
        ->and($card->expires_at->toDateString())->toBe('2020-01-01')
        ->and($card->note)->toBe('edited')
        ->and($card->is_active)->toBeFalse()
        ->and($card->entries)->toHaveCount(2);
});

test('an edit can clear the holder, expiry and note', function () {
    $scope = trpScope('GC1');
    $card = gcdMake($scope['company_id'], ['contact_id' => $scope['customer_id'], 'expires_at' => '2030-01-01', 'note' => 'x']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->putJson('/api/gift-cards/'.$card->id, ['contact_id' => null, 'expires_at' => null, 'note' => ''])->assertSuccessful();

    $card->refresh();

    expect($card->contact_id)->toBeNull()
        ->and($card->expires_at)->toBeNull()
        ->and($card->note)->toBeNull()
        ->and($card->is_active)->toBeTrue();
});

test('gift cards api soft deletes, lists the trash, restores with the balance intact and permanently deletes with its ledger', function () {
    $companyId = gcdCompany();
    $card = gcdMake($companyId, ['code' => 'TRASH-ME', 'initial_value' => 80]);
    $card->redeem(10);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->deleteJson('/api/gift-cards/'.$card->id)->assertSuccessful();

    expect(GiftCard::query()->find($card->id))->toBeNull()
        ->and(GiftCard::onlyTrashed()->find($card->id))->not->toBeNull();

    $this->getJson('/api/gift-cards/trash')->assertSuccessful()->assertJsonPath('data.data.0.code', 'TRASH-ME');
    $this->getJson('/api/gift-cards')->assertJsonPath('trash_count', 1);

    $this->postJson('/api/gift-cards/restore_records', [$card->id])->assertSuccessful();
    expect((float) GiftCard::query()->findOrFail($card->id)->balance)->toBe(70.0);

    $this->postJson('/api/gift-cards/bulk_delete', [$card->id])->assertSuccessful();
    $this->postJson('/api/gift-cards/bulk_delete_per', [$card->id])->assertSuccessful();

    expect(GiftCard::withTrashed()->find($card->id))->toBeNull()
        ->and(GiftCardEntry::query()->where('gift_card_id', $card->id)->count())->toBe(0);
});

test('gift cards api toggles the status', function () {
    $card = gcdMake(gcdCompany());
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/gift-cards/statusupdate', ['ids' => [$card->id], 'status' => 0])->assertSuccessful();
    expect($card->refresh()->is_active)->toBeFalse();

    $this->postJson('/api/gift-cards/statusupdate', ['ids' => [$card->id]])->assertSuccessful();
    expect($card->refresh()->is_active)->toBeTrue();
});

test('gift cards fetch returns only cards that are on, unexpired and still hold money', function () {
    $companyId = gcdCompany();
    gcdMake($companyId, ['code' => 'GOOD']);
    gcdMake($companyId, ['code' => 'OFF', 'is_active' => false]);
    gcdMake($companyId, ['code' => 'OLD', 'expires_at' => now()->subDay()->toDateString()]);
    gcdMake($companyId, ['code' => 'LASTDAY', 'expires_at' => now()->toDateString()]);
    $spent = gcdMake($companyId, ['code' => 'SPENT', 'initial_value' => 10]);
    $spent->redeem(10);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $rows = collect($this->getJson('/api/fetchgiftcards?company_id='.$companyId)->assertSuccessful()->json())->pluck('text')->all();

    expect($rows)->toBe(['GOOD', 'LASTDAY']);
});

// --- lookup ------------------------------------------------------------------------------------

test('lookup returns the balance and whether the card can be spent', function () {
    $companyId = gcdCompany();
    gcdMake($companyId, ['code' => 'OPEN1', 'initial_value' => 500]);
    gcdMake($companyId, ['code' => 'OFF1', 'is_active' => false]);
    gcdMake($companyId, ['code' => 'OLD1', 'expires_at' => now()->subDay()->toDateString()]);
    Sanctum::actingAs(gcdStaff($companyId));

    $this->postJson('/api/gift-cards/lookup', ['code' => 'open1'])
        ->assertSuccessful()
        ->assertJson(['code' => 'OPEN1', 'balance' => 500.0, 'initial_value' => 500.0, 'usable' => true, 'reason' => null]);
    $this->postJson('/api/gift-cards/lookup', ['code' => 'OFF1'])->assertSuccessful()->assertJson(['usable' => false, 'reason' => 'inactive']);
    $this->postJson('/api/gift-cards/lookup', ['code' => 'OLD1'])->assertSuccessful()->assertJson(['usable' => false, 'reason' => 'expired']);
});

test('lookup does not find an unknown, trashed or other company card, and the superadmin names the company', function () {
    $companyId = gcdCompany('GCD001');
    $otherCompany = gcdCompany('GCD002');
    gcdMake($otherCompany, ['code' => 'THEIRS']);
    gcdMake($companyId, ['code' => 'GONE'])->delete();
    gcdMake($companyId, ['code' => 'MINE']);

    Sanctum::actingAs(gcdStaff($companyId));

    foreach (['NOSUCH', 'THEIRS', 'GONE'] as $code) {
        $this->postJson('/api/gift-cards/lookup', ['code' => $code])->assertUnprocessable()->assertJsonPath('reason', 'not_found');
    }

    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/gift-cards/lookup', ['code' => 'MINE'])->assertUnprocessable()->assertJsonPath('reason', 'not_found');
    $this->postJson('/api/gift-cards/lookup', ['code' => 'MINE', 'company_id' => $companyId])->assertSuccessful();
    $this->postJson('/api/gift-cards/lookup', [])->assertUnprocessable()->assertJsonValidationErrors(['code']);
});

// --- redeem and top up -------------------------------------------------------------------------

test('redeeming takes the amount off the balance and writes a ledger entry', function () {
    $scope = trpScope('GC1');
    $card = gcdMake($scope['company_id'], ['initial_value' => 100]);
    $saleId = trpDoc($scope, 'sell');
    $user = gcdStaff($scope['company_id'], ['/giftcard/redeem']);
    Sanctum::actingAs($user);

    $this->postJson('/api/gift-cards/'.$card->id.'/redeem', ['amount' => 35.5, 'note' => 'part payment', 'transaction_id' => $saleId])
        ->assertSuccessful()
        ->assertJson(['balance' => 64.5]);

    $entry = $card->entries()->where('type', 'redeem')->firstOrFail();

    expect((float) $card->refresh()->balance)->toBe(64.5)
        ->and((float) $entry->amount)->toBe(35.5)
        ->and((float) $entry->balance_after)->toBe(64.5)
        ->and($entry->transaction_id)->toBe($saleId)
        ->and($entry->user_id)->toBe($user->id)
        ->and($entry->note)->toBe('part payment');
});

test('a card can be spent down to exactly zero but not a cent further, and a rejected redeem changes nothing', function () {
    $card = gcdMake(gcdCompany(), ['initial_value' => 100]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/gift-cards/'.$card->id.'/redeem', ['amount' => 100.01])
        ->assertUnprocessable()
        ->assertJsonPath('reason', 'insufficient_balance');

    expect((float) $card->refresh()->balance)->toBe(100.0)
        ->and($card->entries)->toHaveCount(1);

    $this->postJson('/api/gift-cards/'.$card->id.'/redeem', ['amount' => 100])->assertSuccessful()->assertJson(['balance' => 0.0]);
    $this->postJson('/api/gift-cards/'.$card->id.'/redeem', ['amount' => 0.01])->assertUnprocessable()->assertJsonPath('reason', 'insufficient_balance');

    expect((float) $card->refresh()->balance)->toBe(0.0);
});

test('two redemptions that together exceed the balance cannot both succeed', function () {
    $card = gcdMake(gcdCompany(), ['initial_value' => 100]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/gift-cards/'.$card->id.'/redeem', ['amount' => 60])->assertSuccessful();
    $this->postJson('/api/gift-cards/'.$card->id.'/redeem', ['amount' => 60])->assertUnprocessable();

    expect((float) $card->refresh()->balance)->toBe(40.0)
        ->and($card->entries()->where('type', 'redeem')->count())->toBe(1);
});

test('a stale copy of the card cannot overspend: the balance is re-read under the lock', function () {
    $card = gcdMake(gcdCompany(), ['initial_value' => 100]);
    $stale = GiftCard::query()->findOrFail($card->id);

    $card->redeem(80);

    expect(fn () => $stale->redeem(80))->toThrow(RuntimeException::class, 'insufficient_balance')
        ->and((float) GiftCard::query()->findOrFail($card->id)->balance)->toBe(20.0);
});

test('redeem and top up reject an amount that is not a positive money value', function (array $body) {
    $card = gcdMake(gcdCompany(), ['initial_value' => 100]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    foreach (['redeem', 'topup'] as $action) {
        $this->postJson('/api/gift-cards/'.$card->id.'/'.$action, $body)->assertUnprocessable()->assertJsonValidationErrors(['amount']);
    }

    expect((float) $card->refresh()->balance)->toBe(100.0);
})->with([
    'missing' => [[]],
    'zero' => [['amount' => 0]],
    'negative' => [['amount' => -1]],
    'text' => [['amount' => 'ten']],
    'a fraction of a cent' => [['amount' => 0.004]],
    'three decimals' => [['amount' => 1.234]],
    'too large' => [['amount' => 10000000]],
]);

test('a redeem cannot name a sale of another company or one that does not exist', function () {
    $scope = trpScope('GC1');
    $otherScope = trpScope('GC2');
    $card = gcdMake($scope['company_id'], ['initial_value' => 100]);
    $foreignSale = trpDoc($otherScope, 'sell');
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/gift-cards/'.$card->id.'/redeem', ['amount' => 5, 'transaction_id' => $foreignSale])->assertUnprocessable()->assertJsonValidationErrors(['transaction_id']);
    $this->postJson('/api/gift-cards/'.$card->id.'/redeem', ['amount' => 5, 'transaction_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors(['transaction_id']);

    expect((float) $card->refresh()->balance)->toBe(100.0);
});

test('an inactive or expired card cannot be redeemed or topped up, but the last day still works', function () {
    $companyId = gcdCompany();
    $off = gcdMake($companyId, ['is_active' => false]);
    $old = gcdMake($companyId, ['expires_at' => now()->subDay()->toDateString()]);
    $lastDay = gcdMake($companyId, ['expires_at' => now()->toDateString()]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    foreach (['redeem', 'topup'] as $action) {
        $this->postJson('/api/gift-cards/'.$off->id.'/'.$action, ['amount' => 1])->assertUnprocessable()->assertJsonPath('reason', 'inactive');
        $this->postJson('/api/gift-cards/'.$old->id.'/'.$action, ['amount' => 1])->assertUnprocessable()->assertJsonPath('reason', 'expired');
        $this->postJson('/api/gift-cards/'.$lastDay->id.'/'.$action, ['amount' => 1])->assertSuccessful();
    }

    expect((float) $off->refresh()->balance)->toBe(100.0)
        ->and((float) $old->refresh()->balance)->toBe(100.0)
        ->and((float) $lastDay->refresh()->balance)->toBe(100.0);
});

test('topping up adds to the balance with its own ledger entry', function () {
    $card = gcdMake(gcdCompany(), ['initial_value' => 100]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/gift-cards/'.$card->id.'/topup', ['amount' => 25.25, 'note' => 'birthday'])
        ->assertSuccessful()
        ->assertJson(['balance' => 125.25]);

    $entry = $card->entries()->where('type', 'topup')->firstOrFail();

    expect((float) $card->refresh()->balance)->toBe(125.25)
        ->and((float) $entry->amount)->toBe(25.25)
        ->and((float) $entry->balance_after)->toBe(125.25)
        ->and($entry->note)->toBe('birthday')
        ->and($entry->transaction_id)->toBeNull();
});

test('the balance always equals the issued value plus top ups minus redemptions', function () {
    $card = gcdMake(gcdCompany(), ['initial_value' => 200]);

    foreach ([['redeem', 50.25], ['topup', 10.10], ['redeem', 99.99], ['topup', 0.01], ['redeem', 20]] as [$type, $amount]) {
        $type === 'redeem' ? $card->redeem($amount) : $card->topUp($amount);
    }

    $entries = $card->entries()->orderBy('id')->get();
    $signed = $entries->sum(fn (GiftCardEntry $entry) => $entry->type === 'redeem' ? -(float) $entry->amount : (float) $entry->amount);

    expect(round($signed, 2))->toBe(round((float) $card->refresh()->balance, 2))
        ->and((float) $card->balance)->toBe(39.87)
        ->and((float) $entries->last()->balance_after)->toBe(39.87);
});

test('redeem and top up answer 404 for a card that is not there or belongs to another company', function () {
    $companyId = gcdCompany('GCD001');
    $theirs = gcdMake(gcdCompany('GCD002'));
    Sanctum::actingAs(gcdStaff($companyId, ['/giftcard/redeem', '/giftcard/topup']));

    foreach (['redeem', 'topup'] as $action) {
        $this->postJson('/api/gift-cards/999999/'.$action, ['amount' => 1])->assertNotFound();
        $this->postJson('/api/gift-cards/'.$theirs->id.'/'.$action, ['amount' => 1])->assertNotFound();
    }

    expect((float) $theirs->refresh()->balance)->toBe(100.0);
});

// --- permissions and isolation -----------------------------------------------------------------

test('gift card write actions are forbidden without the menu permission', function () {
    $companyId = gcdCompany();
    $card = gcdMake($companyId);
    Sanctum::actingAs(gcdStaff($companyId));

    $this->postJson('/api/gift-cards', gcdPayload($companyId))->assertForbidden();
    $this->putJson('/api/gift-cards/'.$card->id, ['note' => 'Hijacked'])->assertForbidden();
    $this->postJson('/api/gift-cards/statusupdate', ['ids' => [$card->id], 'status' => 0])->assertForbidden();
    $this->postJson('/api/gift-cards/'.$card->id.'/redeem', ['amount' => 1])->assertForbidden();
    $this->postJson('/api/gift-cards/'.$card->id.'/topup', ['amount' => 1])->assertForbidden();

    // delete and restore answer "406" in place of doing anything
    $this->deleteJson('/api/gift-cards/'.$card->id)->assertSuccessful()->assertContent('"406"');
    $this->postJson('/api/gift-cards/bulk_delete', [$card->id])->assertSuccessful()->assertContent('"406"');

    $card->refresh();

    expect(GiftCard::query()->count())->toBe(1)
        ->and((float) $card->balance)->toBe(100.0)
        ->and($card->note)->toBeNull()
        ->and($card->is_active)->toBeTrue()
        ->and($card->trashed())->toBeFalse();
});

test('redeem and top up need their own permission, not each other\'s', function () {
    $companyId = gcdCompany();
    $card = gcdMake($companyId);
    Sanctum::actingAs(gcdStaff($companyId, ['/giftcard/redeem']));

    $this->postJson('/api/gift-cards/'.$card->id.'/redeem', ['amount' => 10])->assertSuccessful();
    $this->postJson('/api/gift-cards/'.$card->id.'/topup', ['amount' => 10])->assertForbidden();

    expect((float) $card->refresh()->balance)->toBe(90.0);
});

test('gift cards restore and permanent delete are forbidden without their permissions', function () {
    $companyId = gcdCompany();
    $card = gcdMake($companyId);
    $card->delete();
    Sanctum::actingAs(gcdStaff($companyId));

    $this->postJson('/api/gift-cards/restore_records', [$card->id])->assertContent('"406"');
    $this->postJson('/api/gift-cards/bulk_delete_per', [$card->id])->assertContent('"406"');

    expect(GiftCard::onlyTrashed()->find($card->id))->not->toBeNull();
});

test('a user with the menu permissions can issue, edit, delete and restore in their own company', function () {
    $companyId = gcdCompany();
    Sanctum::actingAs(gcdStaff($companyId, ['/giftcard/add', '/giftcard/:id/edit', '/giftcard/delete', '/giftcard/restore']));

    $this->postJson('/api/gift-cards', gcdPayload($companyId, ['company_id' => null]))->assertSuccessful();

    $card = GiftCard::query()->firstOrFail();

    $this->putJson('/api/gift-cards/'.$card->id, ['note' => 'Edited'])->assertSuccessful();
    expect($card->refresh()->note)->toBe('Edited');

    $this->deleteJson('/api/gift-cards/'.$card->id)->assertSuccessful()->assertJson(['message' => 'Successfully Deleted']);
    expect(GiftCard::query()->count())->toBe(0);

    $this->postJson('/api/gift-cards/restore_records', [$card->id])->assertSuccessful()->assertJson(['message' => 'Successfully Restored']);
    expect(GiftCard::query()->count())->toBe(1);
});

test('a company user always issues under their own company, whatever company the request names', function () {
    $ownCompany = gcdCompany('GCD001');
    $otherCompany = gcdCompany('GCD002');
    Sanctum::actingAs(gcdStaff($ownCompany, ['/giftcard/add']));

    $this->postJson('/api/gift-cards', gcdPayload($otherCompany))->assertSuccessful();

    expect(GiftCard::query()->firstOrFail()->company_id)->toBe($ownCompany);
});

test('a company user cannot see or change the gift cards of another company', function () {
    $ownCompany = gcdCompany('GCD001');
    $otherCompany = gcdCompany('GCD002');
    $mine = gcdMake($ownCompany, ['code' => 'MINE']);
    $theirs = gcdMake($otherCompany, ['code' => 'THEIRS']);
    Sanctum::actingAs(gcdStaff($ownCompany, ['/giftcard/:id/edit', '/giftcard/delete']));

    $listed = collect($this->getJson('/api/gift-cards')->assertSuccessful()->json('data.data'))->pluck('code')->all();

    expect($listed)->toBe(['MINE']);

    $this->getJson('/api/gift-cards/'.$theirs->id)->assertNotFound();
    $this->getJson('/api/gift-cards?company_id='.$otherCompany)->assertJsonCount(0, 'data.data');
    $this->getJson('/api/fetchgiftcards?company_id='.$otherCompany)->assertSuccessful()->assertJsonCount(0);

    $this->putJson('/api/gift-cards/'.$theirs->id, ['note' => 'Taken'])->assertNotFound();
    $this->postJson('/api/gift-cards/statusupdate', ['ids' => [$theirs->id], 'status' => 0]);
    $this->postJson('/api/gift-cards/bulk_delete', [$theirs->id, $mine->id])->assertSuccessful();

    expect($theirs->refresh()->note)->toBeNull()
        ->and($theirs->is_active)->toBeTrue()
        ->and($theirs->trashed())->toBeFalse()
        ->and($mine->refresh()->trashed())->toBeTrue();
});

test('gift cards api requires authentication', function () {
    $this->getJson('/api/gift-cards')->assertUnauthorized();
    $this->postJson('/api/gift-cards', ['initial_value' => 10])->assertUnauthorized();
    $this->postJson('/api/gift-cards/lookup', ['code' => 'X'])->assertUnauthorized();
    $this->postJson('/api/gift-cards/1/redeem', ['amount' => 1])->assertUnauthorized();
});
