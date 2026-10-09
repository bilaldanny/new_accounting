<?php

use App\Models\Brand;
use App\Models\Contact;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Confirmed in the 2026-09-22 audit and fixed here: several "fetch*" dropdown/lookup endpoints and several
 * `trash_count` figures read every company's rows instead of the signed-in user's own, because they filtered on
 * `company_id`/`branch_id` only "when the request supplied one" instead of first applying the model's own
 * `visibleToCurrentUser()` scope (the convention every other controller in this app already follows).
 */
function tssTwoCompanies(): array
{
    return ['mine' => tssCompany('TSS-MINE'), 'theirs' => tssCompany('TSS-THEIRS')];
}

function tssCompany(string $code): int
{
    return DB::table('companies')->insertGetId(['code' => $code, 'name' => 'Company '.$code, 'address' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
}

function tssBranch(int $companyId, string $name): int
{
    return DB::table('branches')->insertGetId(['code' => 'B'.strtoupper(substr(md5($name.$companyId), 0, 6)), 'company_id' => $companyId, 'name' => $name, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * A user of $companyId whose role holds exactly these menu paths (none needed for the endpoints under test,
 * which require only sign-in, but the harness still wants a role).
 *
 * @param  list<string>  $paths
 */
function tssUser(int $companyId, array $paths = []): User
{
    $role = Role::query()->create(['name' => 'staff'.uniqid(), 'company_id' => $companyId, 'is_active' => true]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path, ltrim(str_replace('/', '', $path), '/').uniqid());
    }

    return createStaffUserForRole($role, ['company_id' => $companyId]);
}

function tssContact(int $companyId, string $name, string $userType = 'customer'): int
{
    return Contact::query()->create([
        'company_id' => $companyId, 'business_name' => $name, 'first_name' => '', 'mobile' => '0300', 'address' => 'x',
        'code' => 'C-'.uniqid(), 'user_type' => $userType, 'type' => 'local', 'ntn_number' => '1234567', 'pay_type' => 'day', 'credit_limit' => 0, 'active' => true,
    ])->id;
}

function tssAccount(int $companyId, string $code, array $extra = []): int
{
    return DB::table('chart_of_accounts')->insertGetId(array_merge([
        'company_id' => $companyId, 'code' => $code, 'name' => 'Account '.$code, 'acc_type' => 't', 'acc_nature' => 'dr', 'active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ], $extra));
}

// --- item 1: unscoped fetch* dropdown endpoints ---------------------------------------------------

test('fetchcustomers never returns another company\'s customers, with or without a company_id filter', function () {
    $co = tssTwoCompanies();
    tssContact($co['mine'], 'My Customer');
    tssContact($co['theirs'], 'Their Customer');
    Sanctum::actingAs(tssUser($co['mine']));

    // no filter at all: today this returned every company's customers
    $names = fn (array $query = []) => collect($this->getJson('/api/fetchcustomers?'.http_build_query($query))->assertSuccessful()->json())->pluck('business_name')->all();

    expect($names())->toBe(['My Customer']);
    // even asking explicitly for the other company by id: a non-superadmin's own scope still wins, so nothing of
    // theirs is ever returned (the mismatched company_id filter yields no rows rather than leaking)
    expect($names(['company_id' => $co['theirs']]))->toBe([]);
});

test('fetchcustomers lets the superadmin pick any company', function () {
    $co = tssTwoCompanies();
    tssContact($co['mine'], 'My Customer');
    tssContact($co['theirs'], 'Their Customer');
    Sanctum::actingAs(User::query()->findOrFail(1));

    $names = fn (array $query = []) => collect($this->getJson('/api/fetchcustomers?'.http_build_query($query))->assertSuccessful()->json())->pluck('business_name')->all();

    expect($names(['company_id' => $co['theirs']]))->toBe(['Their Customer'])
        ->and($names())->toContain('My Customer', 'Their Customer');
});

test('fetchbranches never returns another company\'s branches, whatever company_id is asked for', function () {
    $co = tssTwoCompanies();
    tssBranch($co['mine'], 'My Branch');
    tssBranch($co['theirs'], 'Their Branch');
    Sanctum::actingAs(tssUser($co['mine']));

    $names = fn (int $companyId) => collect($this->getJson('/api/fetchbranches?company_id='.$companyId)->assertSuccessful()->json())->pluck('name')->all();

    expect($names($co['mine']))->toBe(['My Branch'])
        ->and($names($co['theirs']))->toBe([]);
});

test('every chart-of-accounts fetch* dropdown is scoped to the signed-in company', function (string $uri, array $accountExtra) {
    $co = tssTwoCompanies();
    tssAccount($co['mine'], '211-00001', $accountExtra);
    tssAccount($co['theirs'], '211-00002', $accountExtra);
    Sanctum::actingAs(tssUser($co['mine']));

    $codes = fn (array $extra) => collect($this->getJson($uri.'?'.http_build_query($extra))->assertSuccessful()->json())->pluck('code')->all();

    expect($codes([]))->toBe(['211-00001'])
        ->and($codes(['company_id' => $co['theirs']]))->toBe([]);
})->with([
    'fetchallaccounts' => ['/api/fetchallaccounts', []],
    'fetchparentaccounts' => ['/api/fetchparentaccounts', ['acc_type' => 'c']],
    'fetchcontrolaccounts' => ['/api/fetchcontrolaccounts', ['acc_type' => 'c']],
    'fetchchildaccounts' => ['/api/fetchchildaccounts', ['acc_type' => 't']],
    'fetchobaccounts' => ['/api/fetchobaccounts', ['bs' => 1]],
]);

test('fetchparentsaleaccounts and fetchparentpurchaseaccounts are scoped too', function (string $uri) {
    $co = tssTwoCompanies();
    $accountId = tssAccount($co['mine'], '311-00001');
    $mine = DB::table('chart_of_accounts')->insertGetId([
        'company_id' => $co['mine'], 'parent_id' => $accountId, 'code' => '311-00002', 'name' => 'Mine child', 'acc_type' => 't', 'acc_nature' => 'dr', 'active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $foreignParent = tssAccount($co['theirs'], '311-00003');
    $theirs = DB::table('chart_of_accounts')->insertGetId([
        'company_id' => $co['theirs'], 'parent_id' => $foreignParent, 'code' => '311-00004', 'name' => 'Theirs child', 'acc_type' => 't', 'acc_nature' => 'dr', 'active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    Sanctum::actingAs(tssUser($co['mine']));

    $codes = fn (int $parentId) => collect($this->getJson($uri.'?parent_id='.$parentId)->assertSuccessful()->json())->pluck('code')->all();

    // asking with our own parent_id: only ours
    expect($codes($accountId))->toBe(['311-00002'])
        // asking with the OTHER company's parent_id: nothing of theirs leaks even though parent_id alone would have matched it
        ->and($codes($foreignParent))->toBe([]);
})->with(['fetchparentsaleaccounts' => ['/api/fetchparentsaleaccounts'], 'fetchparentpurchaseaccounts' => ['/api/fetchparentpurchaseaccounts']]);

test('a superadmin can still pick any company on the chart-of-accounts dropdowns', function () {
    $co = tssTwoCompanies();
    tssAccount($co['mine'], '211-00001');
    tssAccount($co['theirs'], '211-00002');
    Sanctum::actingAs(User::query()->findOrFail(1));

    expect(collect($this->getJson('/api/fetchallaccounts?company_id='.$co['theirs'])->json())->pluck('code')->all())->toBe(['211-00002']);
});

// --- item 3: unscoped trash_count -------------------------------------------------------------------

// each model's own list-page permission path is granted here so this test keeps proving trash_count scoping
// (rather than merely proving a 403) once the index() methods are also gated for read-permission enforcement.
test('trash_count on the list APIs counts only the signed-in company\'s own trashed rows', function (string $model, string $uri, string $permissionPath) {
    $co = tssTwoCompanies();
    $make = function (int $companyId) use ($model) {
        $class = 'App\\Models\\'.$model;
        $class::query()->create(tssTrashSeedAttributes($model, $companyId))->delete();
    };
    $make($co['mine']);
    $make($co['mine']);
    $make($co['theirs']);
    Sanctum::actingAs(tssUser($co['mine'], [$permissionPath]));

    $trashCount = $this->getJson($uri.'?company_id='.$co['mine'])->assertSuccessful()->json('trash_count');

    expect($trashCount)->toBe(2);
})->with([
    'Brand' => ['Brand', '/api/brands', '/brand'],
    'Category' => ['Category', '/api/categories', '/category'],
    'CustomerGroup' => ['CustomerGroup', '/api/customer-groups', '/customer-group'],
    'Department' => ['Department', '/api/departments', '/department'],
    'ItemType' => ['ItemType', '/api/item-types', '/itemtype'],
    'Product' => ['Product', '/api/products', '/product'],
    'Role' => ['Role', '/api/roles', '/role'],
    'Unit' => ['Unit', '/api/units', '/unit'],
    'Variation' => ['Variation', '/api/variations', '/variation'],
    'Warranty' => ['Warranty', '/api/warranties', '/warranty'],
]);

/**
 * The minimum attributes each trashable model needs to be created directly, keyed by model name.
 *
 * @return array<string, mixed>
 */
function tssTrashSeedAttributes(string $model, int $companyId): array
{
    static $unitId = null;

    return match ($model) {
        'Brand' => ['company_id' => $companyId, 'name' => 'Brand '.uniqid(), 'active' => true],
        'Category' => ['company_id' => $companyId, 'name' => 'Category '.uniqid(), 'active' => true],
        'CustomerGroup' => ['company_id' => $companyId, 'name' => 'Group '.uniqid(), 'is_active' => true],
        'Department' => ['company_id' => $companyId, 'name' => 'Dept '.uniqid(), 'active' => true],
        'ItemType' => ['company_id' => $companyId, 'name' => 'Type '.uniqid(), 'active' => true],
        'Product' => (function () use ($companyId, &$unitId): array {
            $unitId ??= DB::table('units')->insertGetId(['company_id' => $companyId, 'name' => 'Unit', 'short_name' => 'u', 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);

            return ['company_id' => $companyId, 'unit_id' => $unitId, 'name' => 'Product '.uniqid(), 'sku' => 'SKU-'.uniqid(), 'active' => true, 'type' => 'single'];
        })(),
        'Role' => ['company_id' => $companyId, 'name' => 'Role '.uniqid(), 'is_active' => true],
        'Unit' => ['company_id' => $companyId, 'name' => 'Unit '.uniqid(), 'short_name' => 'u'.uniqid(), 'active' => true],
        'Variation' => ['company_id' => $companyId, 'name' => 'Variation '.uniqid(), 'active' => true],
        'Warranty' => ['company_id' => $companyId, 'name' => 'Warranty '.uniqid(), 'duration' => 1, 'active' => true],
        default => throw new InvalidArgumentException("no seed attributes for {$model}"),
    };
}

test('a superadmin still sees every company\'s trashed rows when no company is picked', function () {
    $co = tssTwoCompanies();
    Brand::query()->create(['company_id' => $co['mine'], 'name' => 'Mine', 'active' => true])->delete();
    Brand::query()->create(['company_id' => $co['theirs'], 'name' => 'Theirs', 'active' => true])->delete();
    Sanctum::actingAs(User::query()->findOrFail(1));

    expect($this->getJson('/api/brands')->json('trash_count'))->toBe(2);
});
