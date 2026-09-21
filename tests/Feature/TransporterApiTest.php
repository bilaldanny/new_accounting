<?php

use App\Models\Role;
use App\Models\Transporter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function tpCompany(string $code = 'TPR001'): int
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
function tpStaff(int $companyId, array $paths = []): User
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
function tpPayload(int $companyId, array $overrides = []): array
{
    return array_merge([
        'company_id' => $companyId,
        'name' => 'Ali Goods Transport',
        'phone' => '0300-1234567',
        'address' => 'Truck Stand, Lahore',
        'vehicle_no' => 'LEA-1234',
        'is_active' => true,
    ], $overrides);
}

function tpMake(int $companyId, array $attributes = []): Transporter
{
    return Transporter::query()->create(array_merge([
        'company_id' => $companyId,
        'name' => 'Existing Transporter',
        'is_active' => true,
    ], $attributes));
}

test('transporters api creates a transporter with the given fields', function () {
    $companyId = tpCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/transporters', tpPayload($companyId))->assertSuccessful();

    $transporter = Transporter::query()->where('company_id', $companyId)->firstOrFail();

    expect($transporter->name)->toBe('Ali Goods Transport')
        ->and($transporter->phone)->toBe('0300-1234567')
        ->and($transporter->address)->toBe('Truck Stand, Lahore')
        ->and($transporter->vehicle_no)->toBe('LEA-1234')
        ->and($transporter->is_active)->toBeTrue();
});

test('transporters api trims the name and stores blank optional fields as null', function () {
    $companyId = tpCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/transporters', tpPayload($companyId, [
        'name' => '  Bilal Cargo  ',
        'phone' => '',
        'address' => null,
        'vehicle_no' => '   ',
    ]))->assertSuccessful();

    $transporter = Transporter::query()->firstOrFail();

    expect($transporter->name)->toBe('Bilal Cargo')
        ->and($transporter->phone)->toBeNull()
        ->and($transporter->address)->toBeNull()
        ->and($transporter->vehicle_no)->toBeNull();
});

test('transporters api only needs a name and defaults to active', function () {
    $companyId = tpCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/transporters', ['company_id' => $companyId, 'name' => 'Only Name'])->assertSuccessful();

    expect(Transporter::query()->firstOrFail()->is_active)->toBeTrue();
});

test('transporters api validates the fields', function (array $overrides, string $field) {
    $companyId = tpCompany();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/transporters', tpPayload($companyId, $overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(Transporter::query()->count())->toBe(0);
})->with([
    'no name' => [['name' => ''], 'name'],
    'name too short' => [['name' => 'ab'], 'name'],
    'name too long' => [['name' => str_repeat('a', 201)], 'name'],
    'phone with letters' => [['phone' => '03OO-abc'], 'phone'],
    'phone too long' => [['phone' => str_repeat('1', 31)], 'phone'],
    'vehicle number too long' => [['vehicle_no' => str_repeat('A', 51)], 'vehicle_no'],
    'address too long' => [['address' => str_repeat('a', 501)], 'address'],
    'status not a boolean' => [['is_active' => 'maybe'], 'is_active'],
    'unknown company' => [['company_id' => 999999], 'company_id'],
    'no company for the superadmin' => [['company_id' => null], 'company_id'],
]);

test('transporters index lists transporters with company names', function () {
    $companyId = tpCompany();
    tpMake($companyId, ['name' => 'Ali Goods Transport', 'vehicle_no' => 'LEA-1234']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->getJson('/api/transporters')->assertSuccessful();

    expect($response->json('data.data'))->toHaveCount(1)
        ->and($response->json('data.data.0.company_name'))->toBe('Company TPR001')
        ->and($response->json('data.data.0.name'))->toBe('Ali Goods Transport')
        ->and($response->json('trash_count'))->toBe(0);
});

test('transporters index searches the name, phone and vehicle number and filters by status', function () {
    $companyId = tpCompany();
    tpMake($companyId, ['name' => 'Ali Goods Transport', 'phone' => '0300-1111111', 'vehicle_no' => 'LEA-1111']);
    tpMake($companyId, ['name' => 'Bilal Cargo', 'phone' => '0321-2222222', 'vehicle_no' => 'KHI-2222', 'is_active' => false]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $names = fn (array $query) => collect($this->getJson('/api/transporters?'.http_build_query($query))->assertSuccessful()->json('data.data'))->pluck('name')->all();

    expect($names(['search' => 'Bilal']))->toBe(['Bilal Cargo'])
        ->and($names(['search' => '0300']))->toBe(['Ali Goods Transport'])
        ->and($names(['search' => 'KHI-2222']))->toBe(['Bilal Cargo'])
        ->and($names(['status' => '0']))->toBe(['Bilal Cargo'])
        ->and($names(['status' => '1']))->toBe(['Ali Goods Transport'])
        ->and($names(['status' => 'all']))->toHaveCount(2);
});

test('transporters index sorts by an allowed column and ignores any other sort column', function () {
    $companyId = tpCompany();
    tpMake($companyId, ['name' => 'Charlie Cargo']);
    tpMake($companyId, ['name' => 'Alpha Cargo']);
    tpMake($companyId, ['name' => 'Bravo Cargo']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $sorted = $this->getJson('/api/transporters?sort_by=name&sort_type=asc')->assertSuccessful();

    expect(collect($sorted->json('data.data'))->pluck('name')->all())->toBe(['Alpha Cargo', 'Bravo Cargo', 'Charlie Cargo']);

    $this->getJson('/api/transporters?sort_by='.urlencode('id; drop table transporters').'&sort_type=sideways')
        ->assertSuccessful();
    $this->getJson('/api/transporters?sort_by=password')->assertSuccessful();

    expect(Transporter::query()->count())->toBe(3);
});

test('transporters show returns one transporter with its company', function () {
    $companyId = tpCompany();
    $transporter = tpMake($companyId, ['vehicle_no' => 'LEA-1234']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->getJson('/api/transporters/'.$transporter->id)
        ->assertSuccessful()
        ->assertJsonPath('vehicle_no', 'LEA-1234')
        ->assertJsonPath('company.name', 'Company TPR001');

    $this->getJson('/api/transporters/999999')->assertNotFound();
});

test('transporters api updates a transporter', function () {
    $companyId = tpCompany();
    $transporter = tpMake($companyId, ['phone' => '0300-0000000', 'vehicle_no' => 'OLD-1']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->putJson('/api/transporters/'.$transporter->id, tpPayload($companyId, [
        'name' => 'Renamed Transport',
        'phone' => '',
        'vehicle_no' => 'NEW-2',
        'is_active' => false,
    ]))->assertSuccessful();

    $transporter->refresh();

    expect($transporter->name)->toBe('Renamed Transport')
        ->and($transporter->phone)->toBeNull()
        ->and($transporter->vehicle_no)->toBe('NEW-2')
        ->and($transporter->is_active)->toBeFalse();
});

test('transporters api soft deletes, lists the trash, restores and permanently deletes', function () {
    $companyId = tpCompany();
    $transporter = tpMake($companyId, ['name' => 'Trash Me Transport']);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->deleteJson('/api/transporters/'.$transporter->id)->assertSuccessful();

    expect(Transporter::query()->find($transporter->id))->toBeNull()
        ->and(Transporter::onlyTrashed()->find($transporter->id))->not->toBeNull();

    $this->getJson('/api/transporters/trash')
        ->assertSuccessful()
        ->assertJsonPath('data.data.0.name', 'Trash Me Transport');
    $this->getJson('/api/transporters')->assertJsonPath('trash_count', 1);

    $this->postJson('/api/transporters/restore_records', [$transporter->id])->assertSuccessful();
    expect(Transporter::query()->find($transporter->id))->not->toBeNull();

    $this->postJson('/api/transporters/bulk_delete', [$transporter->id])->assertSuccessful();
    $this->postJson('/api/transporters/bulk_delete_per', [$transporter->id])->assertSuccessful();

    expect(Transporter::withTrashed()->find($transporter->id))->toBeNull();
});

test('transporters api toggles the status', function () {
    $transporter = tpMake(tpCompany());
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/transporters/statusupdate', ['ids' => [$transporter->id], 'status' => 0])->assertSuccessful();
    expect($transporter->refresh()->is_active)->toBeFalse();

    $this->postJson('/api/transporters/statusupdate', ['ids' => [$transporter->id]])->assertSuccessful();
    expect($transporter->refresh()->is_active)->toBeTrue();
});

test('transporters fetch returns only the active transporters, named for a dropdown', function () {
    $companyId = tpCompany();
    tpMake($companyId, ['name' => 'Active Transport']);
    tpMake($companyId, ['name' => 'Retired Transport', 'is_active' => false]);
    Sanctum::actingAs(User::query()->findOrFail(1));

    $rows = $this->getJson('/api/fetchtransporters?company_id='.$companyId)->assertSuccessful()->json();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['text'])->toBe('Active Transport');
});

test('transporters write actions are forbidden without the menu permission', function () {
    $companyId = tpCompany();
    $transporter = tpMake($companyId);
    Sanctum::actingAs(tpStaff($companyId));

    $this->postJson('/api/transporters', tpPayload($companyId))->assertForbidden();
    $this->putJson('/api/transporters/'.$transporter->id, tpPayload($companyId, ['name' => 'Hijacked']))->assertForbidden();
    $this->postJson('/api/transporters/statusupdate', ['ids' => [$transporter->id], 'status' => 0])->assertForbidden();

    // delete and restore answer "406" in place of doing anything
    $this->deleteJson('/api/transporters/'.$transporter->id)->assertSuccessful()->assertContent('"406"');
    $this->postJson('/api/transporters/bulk_delete', [$transporter->id])->assertSuccessful()->assertContent('"406"');

    $transporter->refresh();

    expect(Transporter::query()->count())->toBe(1)
        ->and($transporter->name)->toBe('Existing Transporter')
        ->and($transporter->is_active)->toBeTrue()
        ->and($transporter->trashed())->toBeFalse();
});

test('transporters restore and permanent delete are forbidden without their permissions', function () {
    $companyId = tpCompany();
    $transporter = tpMake($companyId);
    $transporter->delete();
    Sanctum::actingAs(tpStaff($companyId));

    $this->postJson('/api/transporters/restore_records', [$transporter->id])->assertContent('"406"');
    $this->postJson('/api/transporters/bulk_delete_per', [$transporter->id])->assertContent('"406"');

    expect(Transporter::onlyTrashed()->find($transporter->id))->not->toBeNull();
});

test('a user with the menu permissions can add, edit, delete and restore in their own company', function () {
    $companyId = tpCompany();
    Sanctum::actingAs(tpStaff($companyId, [
        '/transporter/add', '/transporter/:id/edit', '/transporter/delete', '/transporter/restore',
    ]));

    $this->postJson('/api/transporters', tpPayload($companyId, ['company_id' => null]))->assertSuccessful();

    $transporter = Transporter::query()->firstOrFail();

    $this->putJson('/api/transporters/'.$transporter->id, tpPayload($companyId, ['name' => 'Edited Transport']))->assertSuccessful();
    expect($transporter->refresh()->name)->toBe('Edited Transport');

    $this->deleteJson('/api/transporters/'.$transporter->id)->assertSuccessful()->assertJson(['message' => 'Successfully Deleted']);
    expect(Transporter::query()->count())->toBe(0);

    $this->postJson('/api/transporters/restore_records', [$transporter->id])->assertSuccessful()->assertJson(['message' => 'Successfully Restored']);
    expect(Transporter::query()->count())->toBe(1);
});

test('a company user always saves under their own company, whatever company the request names', function () {
    $ownCompany = tpCompany('TPR001');
    $otherCompany = tpCompany('TPR002');
    Sanctum::actingAs(tpStaff($ownCompany, ['/transporter/add', '/transporter/:id/edit']));

    $this->postJson('/api/transporters', tpPayload($otherCompany))->assertSuccessful();

    $transporter = Transporter::query()->firstOrFail();

    expect($transporter->company_id)->toBe($ownCompany);

    $this->putJson('/api/transporters/'.$transporter->id, tpPayload($otherCompany, ['name' => 'Still Mine']))->assertSuccessful();

    expect($transporter->refresh()->company_id)->toBe($ownCompany)
        ->and($transporter->name)->toBe('Still Mine');
});

test('a company user cannot see or change the transporters of another company', function () {
    $ownCompany = tpCompany('TPR001');
    $otherCompany = tpCompany('TPR002');
    $mine = tpMake($ownCompany, ['name' => 'Mine']);
    $theirs = tpMake($otherCompany, ['name' => 'Theirs']);
    Sanctum::actingAs(tpStaff($ownCompany, ['/transporter/:id/edit', '/transporter/delete']));

    $listed = collect($this->getJson('/api/transporters')->assertSuccessful()->json('data.data'))->pluck('name')->all();

    expect($listed)->toBe(['Mine']);

    $this->getJson('/api/transporters/'.$theirs->id)->assertNotFound();
    $this->getJson('/api/transporters?company_id='.$otherCompany)->assertJsonCount(0, 'data.data');
    $this->getJson('/api/fetchtransporters?company_id='.$otherCompany)->assertSuccessful()->assertJsonCount(0);

    $this->putJson('/api/transporters/'.$theirs->id, tpPayload($ownCompany, ['name' => 'Taken']))->assertNotFound();
    $this->postJson('/api/transporters/statusupdate', ['ids' => [$theirs->id], 'status' => 0]);
    $this->postJson('/api/transporters/bulk_delete', [$theirs->id, $mine->id])->assertSuccessful();

    expect($theirs->refresh()->name)->toBe('Theirs')
        ->and($theirs->is_active)->toBeTrue()
        ->and($theirs->trashed())->toBeFalse()
        ->and($mine->refresh()->trashed())->toBeTrue();
});

test('transporters api requires authentication', function () {
    $this->getJson('/api/transporters')->assertUnauthorized();
    $this->postJson('/api/transporters', ['name' => 'Nobody Transport'])->assertUnauthorized();
});
