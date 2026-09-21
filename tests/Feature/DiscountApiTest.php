<?php

use App\Models\Discount;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function dscCompany(string $code = 'DSC001'): int
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
function dscStaff(int $companyId, array $paths = []): User
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
function dscPayload(int $companyId, array $overrides = []): array
{
    return array_merge([
        'company_id' => $companyId,
        'name' => 'Eid Sale',
        'code' => 'EID10',
        'discount_type' => 'percentage',
        'value' => 10,
        'min_purchase_amount' => 500,
        'max_discount_amount' => 200,
        'starts_at' => '2026-01-01',
        'expires_at' => '2026-12-31',
        'is_active' => true,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function dscMake(int $companyId, array $attributes = []): Discount
{
    return Discount::query()->create(array_merge([
        'company_id' => $companyId,
        'name' => 'Existing Discount',
        'discount_type' => 'percentage',
        'value' => 10,
        'is_active' => true,
    ], $attributes));
}

test('discounts api creates a discount with the given fields', function () {
    $companyId = dscCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/discounts', dscPayload($companyId))->assertSuccessful();

    $discount = Discount::query()->where('company_id', $companyId)->firstOrFail();

    expect($discount->name)->toBe('Eid Sale')
        ->and($discount->code)->toBe('EID10')
        ->and($discount->discount_type)->toBe('percentage')
        ->and((float) $discount->value)->toBe(10.0)
        ->and((float) $discount->min_purchase_amount)->toBe(500.0)
        ->and((float) $discount->max_discount_amount)->toBe(200.0)
        ->and($discount->starts_at->toDateString())->toBe('2026-01-01')
        ->and($discount->expires_at->toDateString())->toBe('2026-12-31')
        ->and($discount->is_active)->toBeTrue();
});

test('discounts api trims the name, upper cases the code and stores blank optional fields as null', function () {
    $companyId = dscCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/discounts', dscPayload($companyId, [
        'name' => '  Summer Deal  ',
        'code' => ' summer-5 ',
        'min_purchase_amount' => '',
        'max_discount_amount' => '',
        'starts_at' => '',
        'expires_at' => null,
    ]))->assertSuccessful();

    $discount = Discount::query()->firstOrFail();

    expect($discount->name)->toBe('Summer Deal')
        ->and($discount->code)->toBe('SUMMER-5')
        ->and((float) $discount->min_purchase_amount)->toBe(0.0)
        ->and($discount->max_discount_amount)->toBeNull()
        ->and($discount->starts_at)->toBeNull()
        ->and($discount->expires_at)->toBeNull();
});

test('discounts api needs only a name, a type and a value, defaults to active, and the code is optional', function () {
    $companyId = dscCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/discounts', ['company_id' => $companyId, 'name' => 'Plain Discount', 'discount_type' => 'fixed', 'value' => 50])->assertSuccessful();

    $discount = Discount::query()->firstOrFail();

    expect($discount->is_active)->toBeTrue()
        ->and($discount->code)->toBeNull()
        ->and((float) $discount->min_purchase_amount)->toBe(0.0);
});

test('a fixed discount never keeps a cap', function () {
    $companyId = dscCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/discounts', dscPayload($companyId, ['discount_type' => 'fixed', 'value' => 75, 'max_discount_amount' => 20]))->assertSuccessful();

    expect(Discount::query()->firstOrFail()->max_discount_amount)->toBeNull();
});

test('discounts api validates the fields', function (array $overrides, string $field) {
    $companyId = dscCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/discounts', dscPayload($companyId, $overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(Discount::query()->count())->toBe(0);
})->with([
    'no name' => [['name' => ''], 'name'],
    'name too short' => [['name' => 'ab'], 'name'],
    'name too long' => [['name' => str_repeat('a', 201)], 'name'],
    'code with a space' => [['code' => 'EID 10'], 'code'],
    'code with symbols' => [['code' => 'EID%10'], 'code'],
    'code too long' => [['code' => str_repeat('A', 51)], 'code'],
    'no type' => [['discount_type' => ''], 'discount_type'],
    'unknown type' => [['discount_type' => 'bogus'], 'discount_type'],
    'no value' => [['value' => null], 'value'],
    'zero value' => [['value' => 0], 'value'],
    'negative value' => [['value' => -5], 'value'],
    'value not a number' => [['value' => 'ten'], 'value'],
    'percentage over 100' => [['value' => 100.01], 'value'],
    'fixed amount too large' => [['discount_type' => 'fixed', 'value' => 10000000], 'value'],
    'negative minimum purchase' => [['min_purchase_amount' => -1], 'min_purchase_amount'],
    'minimum purchase too large' => [['min_purchase_amount' => 10000000], 'min_purchase_amount'],
    'zero cap' => [['max_discount_amount' => 0], 'max_discount_amount'],
    'start date in a wrong format' => [['starts_at' => '01/02/2026'], 'starts_at'],
    'impossible expiry date' => [['expires_at' => '2026-02-30'], 'expires_at'],
    'expiry before the start' => [['starts_at' => '2026-06-01', 'expires_at' => '2026-05-31'], 'expires_at'],
    'status not a boolean' => [['is_active' => 'maybe'], 'is_active'],
    'unknown company' => [['company_id' => 999999], 'company_id'],
    'no company for the superadmin' => [['company_id' => null], 'company_id'],
]);

test('the boundary values are accepted: 100 percent, the largest amount, a single day of validity', function () {
    $companyId = dscCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/discounts', dscPayload($companyId, ['code' => 'FULL', 'value' => 100, 'starts_at' => '2026-03-03', 'expires_at' => '2026-03-03']))->assertSuccessful();
    $this->postJson('/api/discounts', dscPayload($companyId, ['code' => 'BIG', 'discount_type' => 'fixed', 'value' => 9999999.99, 'min_purchase_amount' => 0]))->assertSuccessful();

    expect(Discount::query()->count())->toBe(2);
});

test('a coupon code is unique among the live discounts of a company, whatever its case', function () {
    $companyId = dscCompany('DSC001');
    $otherCompany = dscCompany('DSC002');
    $existing = dscMake($companyId, ['code' => 'SAVE5']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/discounts', dscPayload($companyId, ['code' => 'save5']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);

    // another company may reuse it
    $this->postJson('/api/discounts', dscPayload($otherCompany, ['code' => 'SAVE5']))->assertSuccessful();

    // a rule may keep its own code when it is edited
    $this->putJson('/api/discounts/'.$existing->id, dscPayload($companyId, ['code' => 'Save5', 'name' => 'Renamed']))->assertSuccessful();

    // a trashed rule frees its code
    $existing->delete();
    $this->postJson('/api/discounts', dscPayload($companyId, ['code' => 'SAVE5']))->assertSuccessful();

    // but two live rules of one company cannot share it after an edit either
    $second = dscMake($companyId, ['code' => 'OTHER1']);
    $this->putJson('/api/discounts/'.$second->id, dscPayload($companyId, ['code' => 'SAVE5']))->assertUnprocessable();
});

test('discounts index lists discounts with company names', function () {
    $companyId = dscCompany();
    dscMake($companyId, ['name' => 'Eid Sale', 'code' => 'EID10']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->getJson('/api/discounts')->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.company_name'))->toBe('Company DSC001')
        ->and($response->json('data.data.0.name'))->toBe('Eid Sale')
        ->and($response->json('trash_count'))->toBe(0);
});

test('discounts index searches the name and code and filters by status', function () {
    $companyId = dscCompany();
    dscMake($companyId, ['name' => 'Eid Sale', 'code' => 'EID10']);
    dscMake($companyId, ['name' => 'Winter Clearance', 'code' => 'WINTER', 'is_active' => false]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $names = fn (array $query) => collect($this->getJson('/api/discounts?'.http_build_query($query))->assertSuccessful()->json('data.data'))->pluck('name')->all();

    expect($names(['search' => 'Winter']))->toBe(['Winter Clearance'])
        ->and($names(['search' => 'EID1']))->toBe(['Eid Sale'])
        ->and($names(['status' => '0']))->toBe(['Winter Clearance'])
        ->and($names(['status' => '1']))->toBe(['Eid Sale'])
        ->and($names(['status' => 'all']))->toHaveCount(2)
        ->and($names(['search' => 'nothing matches']))->toBe([]);
});

test('discounts index sorts by an allowed column and ignores any other sort column', function () {
    $companyId = dscCompany();
    dscMake($companyId, ['name' => 'Charlie Deal']);
    dscMake($companyId, ['name' => 'Alpha Deal']);
    dscMake($companyId, ['name' => 'Bravo Deal']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $sorted = $this->getJson('/api/discounts?sort_by=name&sort_type=asc')->assertSuccessful();

    expect(collect($sorted->json('data.data'))->pluck('name')->all())->toBe(['Alpha Deal', 'Bravo Deal', 'Charlie Deal']);

    $this->getJson('/api/discounts?sort_by='.urlencode('id; drop table discounts').'&sort_type=sideways')->assertSuccessful();
    $this->getJson('/api/discounts?sort_by=password')->assertSuccessful();

    expect(Discount::query()->count())->toBe(3);
});

test('discounts show returns one discount with its company', function () {
    $companyId = dscCompany();
    $discount = dscMake($companyId, ['code' => 'EID10', 'min_purchase_amount' => 500]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->getJson('/api/discounts/'.$discount->id)
        ->assertSuccessful()
        ->assertJsonPath('code', 'EID10')
        ->assertJsonPath('company.name', 'Company DSC001');

    $this->getJson('/api/discounts/999999')->assertNotFound();
});

test('discounts api updates a discount', function () {
    $companyId = dscCompany();
    $discount = dscMake($companyId, ['code' => 'OLD1', 'max_discount_amount' => 50]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->putJson('/api/discounts/'.$discount->id, dscPayload($companyId, [
        'name' => 'Renamed Discount',
        'code' => '',
        'discount_type' => 'fixed',
        'value' => 25,
        'is_active' => false,
    ]))->assertSuccessful();

    $discount->refresh();

    expect($discount->name)->toBe('Renamed Discount')
        ->and($discount->code)->toBeNull()
        ->and($discount->discount_type)->toBe('fixed')
        ->and((float) $discount->value)->toBe(25.0)
        ->and($discount->max_discount_amount)->toBeNull()
        ->and($discount->is_active)->toBeFalse();
});

test('discounts api soft deletes, lists the trash, restores and permanently deletes', function () {
    $companyId = dscCompany();
    $discount = dscMake($companyId, ['name' => 'Trash Me Discount']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->deleteJson('/api/discounts/'.$discount->id)->assertSuccessful();

    expect(Discount::query()->find($discount->id))->toBeNull()
        ->and(Discount::onlyTrashed()->find($discount->id))->not->toBeNull();

    $this->getJson('/api/discounts/trash')
        ->assertSuccessful()
        ->assertJsonPath('data.data.0.name', 'Trash Me Discount');
    $this->getJson('/api/discounts')->assertJsonPath('trash_count', 1);

    $this->postJson('/api/discounts/restore_records', [$discount->id])->assertSuccessful();
    expect(Discount::query()->find($discount->id))->not->toBeNull();

    $this->postJson('/api/discounts/bulk_delete', [$discount->id])->assertSuccessful();
    $this->postJson('/api/discounts/bulk_delete_per', [$discount->id])->assertSuccessful();

    expect(Discount::withTrashed()->find($discount->id))->toBeNull();
});

test('discounts api toggles the status', function () {
    $discount = dscMake(dscCompany());
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/discounts/statusupdate', ['ids' => [$discount->id], 'status' => 0])->assertSuccessful();
    expect($discount->refresh()->is_active)->toBeFalse();

    $this->postJson('/api/discounts/statusupdate', ['ids' => [$discount->id]])->assertSuccessful();
    expect($discount->refresh()->is_active)->toBeTrue();
});

test('discounts fetch returns only the discounts usable today, named for a dropdown', function () {
    $companyId = dscCompany();
    dscMake($companyId, ['name' => 'Open Ended']);
    dscMake($companyId, ['name' => 'Switched Off', 'is_active' => false]);
    dscMake($companyId, ['name' => 'Expired', 'expires_at' => now()->subDay()->toDateString()]);
    dscMake($companyId, ['name' => 'Future', 'starts_at' => now()->addDay()->toDateString()]);
    dscMake($companyId, ['name' => 'Last Day', 'expires_at' => now()->toDateString()]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $rows = collect($this->getJson('/api/fetchdiscounts?company_id='.$companyId)->assertSuccessful()->json())->pluck('text')->all();

    expect($rows)->toBe(['Last Day', 'Open Ended']);
});

test('discounts write actions are forbidden without the menu permission', function () {
    $companyId = dscCompany();
    $discount = dscMake($companyId);
    Sanctum::actingAs(dscStaff($companyId));

    $this->postJson('/api/discounts', dscPayload($companyId))->assertForbidden();
    $this->putJson('/api/discounts/'.$discount->id, dscPayload($companyId, ['name' => 'Hijacked']))->assertForbidden();
    $this->postJson('/api/discounts/statusupdate', ['ids' => [$discount->id], 'status' => 0])->assertForbidden();

    // delete and restore answer "406" in place of doing anything
    $this->deleteJson('/api/discounts/'.$discount->id)->assertSuccessful()->assertContent('"406"');
    $this->postJson('/api/discounts/bulk_delete', [$discount->id])->assertSuccessful()->assertContent('"406"');

    $discount->refresh();

    expect(Discount::query()->count())->toBe(1)
        ->and($discount->name)->toBe('Existing Discount')
        ->and($discount->is_active)->toBeTrue()
        ->and($discount->trashed())->toBeFalse();
});

test('discounts restore and permanent delete are forbidden without their permissions', function () {
    $companyId = dscCompany();
    $discount = dscMake($companyId);
    $discount->delete();
    Sanctum::actingAs(dscStaff($companyId));

    $this->postJson('/api/discounts/restore_records', [$discount->id])->assertContent('"406"');
    $this->postJson('/api/discounts/bulk_delete_per', [$discount->id])->assertContent('"406"');

    expect(Discount::onlyTrashed()->find($discount->id))->not->toBeNull();
});

test('a user with the menu permissions can add, edit, delete and restore in their own company', function () {
    $companyId = dscCompany();
    Sanctum::actingAs(dscStaff($companyId, [
        '/discount/add', '/discount/:id/edit', '/discount/delete', '/discount/restore',
    ]));

    $this->postJson('/api/discounts', dscPayload($companyId, ['company_id' => null]))->assertSuccessful();

    $discount = Discount::query()->firstOrFail();

    $this->putJson('/api/discounts/'.$discount->id, dscPayload($companyId, ['name' => 'Edited Discount']))->assertSuccessful();
    expect($discount->refresh()->name)->toBe('Edited Discount');

    $this->deleteJson('/api/discounts/'.$discount->id)->assertSuccessful()->assertJson(['message' => 'Successfully Deleted']);
    expect(Discount::query()->count())->toBe(0);

    $this->postJson('/api/discounts/restore_records', [$discount->id])->assertSuccessful()->assertJson(['message' => 'Successfully Restored']);
    expect(Discount::query()->count())->toBe(1);
});

test('a company user always saves under their own company, whatever company the request names', function () {
    $ownCompany = dscCompany('DSC001');
    $otherCompany = dscCompany('DSC002');
    Sanctum::actingAs(dscStaff($ownCompany, ['/discount/add', '/discount/:id/edit']));

    $this->postJson('/api/discounts', dscPayload($otherCompany))->assertSuccessful();

    $discount = Discount::query()->firstOrFail();

    expect($discount->company_id)->toBe($ownCompany);

    $this->putJson('/api/discounts/'.$discount->id, dscPayload($otherCompany, ['name' => 'Still Mine']))->assertSuccessful();

    expect($discount->refresh()->company_id)->toBe($ownCompany)
        ->and($discount->name)->toBe('Still Mine');
});

test('a company user cannot see or change the discounts of another company', function () {
    $ownCompany = dscCompany('DSC001');
    $otherCompany = dscCompany('DSC002');
    $mine = dscMake($ownCompany, ['name' => 'Mine']);
    $theirs = dscMake($otherCompany, ['name' => 'Theirs']);
    Sanctum::actingAs(dscStaff($ownCompany, ['/discount/:id/edit', '/discount/delete']));

    $listed = collect($this->getJson('/api/discounts')->assertSuccessful()->json('data.data'))->pluck('name')->all();

    expect($listed)->toBe(['Mine']);

    $this->getJson('/api/discounts/'.$theirs->id)->assertNotFound();
    $this->getJson('/api/discounts?company_id='.$otherCompany)->assertJsonCount(0, 'data.data');
    $this->getJson('/api/fetchdiscounts?company_id='.$otherCompany)->assertSuccessful()->assertJsonCount(0);

    $this->putJson('/api/discounts/'.$theirs->id, dscPayload($ownCompany, ['name' => 'Taken']))->assertNotFound();
    $this->postJson('/api/discounts/statusupdate', ['ids' => [$theirs->id], 'status' => 0]);
    $this->postJson('/api/discounts/bulk_delete', [$theirs->id, $mine->id])->assertSuccessful();

    expect($theirs->refresh()->name)->toBe('Theirs')
        ->and($theirs->is_active)->toBeTrue()
        ->and($theirs->trashed())->toBeFalse()
        ->and($mine->refresh()->trashed())->toBeTrue();
});

test('discounts api requires authentication', function () {
    $this->getJson('/api/discounts')->assertUnauthorized();
    $this->postJson('/api/discounts', ['name' => 'Nobody Discount'])->assertUnauthorized();
    $this->postJson('/api/discounts/apply', ['code' => 'X', 'subtotal' => 100])->assertUnauthorized();
});

// --- calculation -------------------------------------------------------------------------------

test('a percentage discount takes that share off, capped by the maximum, and never below zero', function (array $attributes, float $subtotal, float $expected) {
    $discount = new Discount(array_merge(['discount_type' => 'percentage', 'value' => 10, 'min_purchase_amount' => 0], $attributes));

    expect($discount->discountFor($subtotal))->toBe($expected);
})->with([
    'plain 10 percent' => [[], 200.0, 20.0],
    'rounds to two decimals' => [['value' => 12.5], 33.33, 4.17],
    'capped by the maximum' => [['max_discount_amount' => 15], 200.0, 15.0],
    'cap not reached' => [['max_discount_amount' => 50], 200.0, 20.0],
    '100 percent is the whole sale' => [['value' => 100], 80.0, 80.0],
    'below the minimum purchase' => [['min_purchase_amount' => 500], 499.99, 0.0],
    'exactly the minimum purchase' => [['min_purchase_amount' => 500], 500.0, 50.0],
    'an empty sale' => [[], 0.0, 0.0],
    'a negative sale' => [[], -10.0, 0.0],
]);

test('a fixed discount takes its amount off but never more than the sale', function (array $attributes, float $subtotal, float $expected) {
    $discount = new Discount(array_merge(['discount_type' => 'fixed', 'value' => 50, 'min_purchase_amount' => 0], $attributes));

    expect($discount->discountFor($subtotal))->toBe($expected);
})->with([
    'plain fixed amount' => [[], 200.0, 50.0],
    'more than the sale' => [[], 30.0, 30.0],
    'below the minimum purchase' => [['min_purchase_amount' => 100], 99.0, 0.0],
    'a cap is ignored on a fixed amount' => [['max_discount_amount' => 10], 200.0, 50.0],
]);

test('rejection reasons follow the flag, the window (both ends inclusive) and the minimum purchase', function () {
    $discount = new Discount([
        'discount_type' => 'percentage', 'value' => 10, 'min_purchase_amount' => 100, 'is_active' => true,
        'starts_at' => '2026-05-10', 'expires_at' => '2026-05-20',
    ]);

    expect($discount->rejectionReason(100, '2026-05-09'))->toBe('not_started')
        ->and($discount->rejectionReason(100, '2026-05-10'))->toBeNull()
        ->and($discount->rejectionReason(100, '2026-05-20'))->toBeNull()
        ->and($discount->rejectionReason(100, '2026-05-21'))->toBe('expired')
        ->and($discount->rejectionReason(99.99, '2026-05-15'))->toBe('min_purchase');

    $discount->is_active = false;

    expect($discount->rejectionReason(100, '2026-05-15'))->toBe('inactive');
});

test('a discount without dates is valid on any day', function () {
    $discount = new Discount(['discount_type' => 'fixed', 'value' => 5, 'min_purchase_amount' => 0, 'is_active' => true]);

    expect($discount->rejectionReason(1, '1999-01-01'))->toBeNull()
        ->and($discount->rejectionReason(1, '2099-12-31'))->toBeNull();
});

// --- apply -------------------------------------------------------------------------------------

test('apply prices a coupon code against a subtotal', function () {
    $companyId = dscCompany();
    $discount = dscMake($companyId, ['name' => 'Eid Sale', 'code' => 'EID10', 'min_purchase_amount' => 500, 'max_discount_amount' => 200]);
    Sanctum::actingAs(dscStaff($companyId));

    $this->postJson('/api/discounts/apply', ['code' => 'eid10', 'subtotal' => 1000])
        ->assertSuccessful()
        ->assertJson([
            'discount_id' => $discount->id,
            'name' => 'Eid Sale',
            'code' => 'EID10',
            'discount_type' => 'percentage',
            'discount_amount' => 100.0,
            'total_after_discount' => 900.0,
        ]);

    $this->postJson('/api/discounts/apply', ['code' => 'EID10', 'subtotal' => 5000])
        ->assertSuccessful()
        ->assertJsonPath('discount_amount', 200)
        ->assertJsonPath('total_after_discount', 4800);
});

test('apply says why a coupon cannot be used', function (array $attributes, float $subtotal, string $reason) {
    $companyId = dscCompany();
    dscMake($companyId, array_merge(['code' => 'EID10', 'min_purchase_amount' => 500], $attributes));
    Sanctum::actingAs(dscStaff($companyId));

    $this->postJson('/api/discounts/apply', ['code' => 'EID10', 'subtotal' => $subtotal])
        ->assertUnprocessable()
        ->assertJsonPath('reason', $reason);
})->with([
    'switched off' => [['is_active' => false], 1000.0, 'inactive'],
    'expired yesterday' => [['expires_at' => '2000-01-01'], 1000.0, 'expired'],
    'starts tomorrow' => [['starts_at' => '2999-01-01'], 1000.0, 'not_started'],
    'below the minimum purchase' => [[], 499.0, 'min_purchase'],
]);

test('apply does not find an unknown, trashed or other company coupon', function () {
    $companyId = dscCompany('DSC001');
    $otherCompany = dscCompany('DSC002');
    dscMake($otherCompany, ['code' => 'THEIRS']);
    dscMake($companyId, ['code' => 'GONE'])->delete();
    Sanctum::actingAs(dscStaff($companyId));

    foreach (['NOSUCH', 'THEIRS', 'GONE'] as $code) {
        $this->postJson('/api/discounts/apply', ['code' => $code, 'subtotal' => 100])
            ->assertUnprocessable()
            ->assertJsonPath('reason', 'not_found');
    }
});

test('apply validates its input and lets the superadmin choose the company', function () {
    $companyId = dscCompany();
    dscMake($companyId, ['code' => 'EID10']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/discounts/apply', ['subtotal' => 100])->assertUnprocessable()->assertJsonValidationErrors(['code']);
    $this->postJson('/api/discounts/apply', ['code' => 'EID10'])->assertUnprocessable()->assertJsonValidationErrors(['subtotal']);
    $this->postJson('/api/discounts/apply', ['code' => 'EID10', 'subtotal' => -1])->assertUnprocessable()->assertJsonValidationErrors(['subtotal']);
    $this->postJson('/api/discounts/apply', ['code' => 'EID10', 'subtotal' => 'lots'])->assertUnprocessable()->assertJsonValidationErrors(['subtotal']);

    // the superadmin has no company of their own, so one has to be named
    $this->postJson('/api/discounts/apply', ['code' => 'EID10', 'subtotal' => 100])->assertUnprocessable()->assertJsonPath('reason', 'not_found');
    $this->postJson('/api/discounts/apply', ['code' => 'EID10', 'subtotal' => 100, 'company_id' => $companyId])->assertSuccessful()->assertJsonPath('discount_amount', 10);
});
