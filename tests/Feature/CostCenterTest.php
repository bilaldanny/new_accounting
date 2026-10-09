<?php

use App\Models\AssetEvent;
use App\Models\FixedAsset;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
});

function ccDepartment(int $companyId, string $name = 'Operations'): int
{
    return DB::table('departments')->insertGetId(['company_id' => $companyId, 'name' => $name, 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $overrides
 */
function ccCreate(array $scope, string $code, array $overrides = []): int
{
    return test()->postJson('/api/cost-centers', array_merge(['company_id' => $scope['company_id'], 'code' => $code, 'name' => 'Center '.$code, 'type' => 'cost'], $overrides))
        ->assertSuccessful()->json('data.id');
}

test('a cost center is saved with its type, parent, branch and department and listed with them', function () {
    $scope = faScope();
    $department = ccDepartment($scope['company_id']);
    $parent = ccCreate($scope, 'CC-1', ['name' => 'Head office', 'type' => 'cost']);
    $child = ccCreate($scope, 'CC-2', ['name' => 'Shop floor', 'type' => 'profit', 'parent_id' => $parent, 'branch_id' => $scope['branch_id'], 'department_id' => $department]);

    $center = $this->getJson("/api/cost-centers/{$child}")->assertSuccessful()->json('data');

    expect($center['type'])->toBe('profit')
        ->and($center['parent_name'])->toBe('Head office')
        ->and($center['branch_id'])->toBe($scope['branch_id'])
        ->and($center['department_name'])->toBe('Operations')
        ->and($center['text'])->toBe('CC-2 - Shop floor')
        ->and($center['used'])->toBeFalse();

    expect($this->getJson('/api/cost-centers')->json('data.data'))->toHaveCount(2)
        ->and($this->getJson('/api/cost-centers?search=Shop')->json('data.data'))->toHaveCount(1)
        ->and($this->getJson('/api/cost-centers?type=profit')->json('data.data.0.code'))->toBe('CC-2');
});

test('a cost center is validated: code unique per company even after a delete, own company\'s parent, branch and department, no loops', function () {
    $scope = faScope();
    $other = faScope('FB');
    $parent = ccCreate($scope, 'CC-1');
    $child = ccCreate($scope, 'CC-2', ['parent_id' => $parent]);

    $this->postJson('/api/cost-centers', ['company_id' => $scope['company_id'], 'code' => 'CC-1', 'name' => 'Again', 'type' => 'cost'])->assertUnprocessable()->assertJsonValidationErrors(['code']);
    $this->postJson('/api/cost-centers', ['company_id' => $scope['company_id'], 'code' => 'CC-3', 'name' => 'x', 'type' => 'other'])->assertUnprocessable()->assertJsonValidationErrors(['type']);
    $this->postJson('/api/cost-centers', ['company_id' => $scope['company_id'], 'code' => 'CC-3', 'name' => 'x', 'type' => 'cost', 'branch_id' => $other['branch_id']])->assertUnprocessable()->assertJsonValidationErrors(['branch_id']);
    $this->postJson('/api/cost-centers', ['company_id' => $scope['company_id'], 'code' => 'CC-3', 'name' => 'x', 'type' => 'cost', 'department_id' => ccDepartment($other['company_id'])])->assertUnprocessable()->assertJsonValidationErrors(['department_id']);
    $this->postJson('/api/cost-centers', ['company_id' => $scope['company_id'], 'code' => 'CC-3', 'name' => 'x', 'type' => 'cost', 'parent_id' => ccCreate($other, 'CC-9')])->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);

    // The same code in another company is fine; under itself or its own child is not.
    $this->postJson('/api/cost-centers', ['company_id' => $other['company_id'], 'code' => 'CC-1', 'name' => 'Other', 'type' => 'cost'])->assertSuccessful();
    $this->putJson("/api/cost-centers/{$parent}", ['code' => 'CC-1', 'name' => 'Head office', 'type' => 'cost', 'parent_id' => $child])->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);
    $this->putJson("/api/cost-centers/{$parent}", ['code' => 'CC-1', 'name' => 'Head office', 'type' => 'cost', 'parent_id' => $parent])->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);

    $this->deleteJson("/api/cost-centers/{$child}")->assertSuccessful();
    $this->postJson('/api/cost-centers', ['company_id' => $scope['company_id'], 'code' => 'CC-2', 'name' => 'Reuse', 'type' => 'cost'])->assertUnprocessable()->assertJsonValidationErrors(['code']);
});

test('a cost center in use or with sub-centers cannot be deleted', function () {
    $scope = faScope();
    $parent = ccCreate($scope, 'CC-1');
    $child = ccCreate($scope, 'CC-2', ['parent_id' => $parent]);

    $this->deleteJson("/api/cost-centers/{$parent}")->assertUnprocessable()->assertJsonValidationErrors(['cost_center']);

    $voucher = ldgVoucher($scope, 'JV-00001', '2026-09-05', [['401-00010', 100, 0], ['202-00010', 0, 100]]);
    DB::table('t_account_details')->where('t_account_id', $voucher)->where('account_code', '401-00010')->update(['cost_center_id' => $child]);

    $this->deleteJson("/api/cost-centers/{$child}")->assertUnprocessable()->assertJsonValidationErrors(['cost_center']);
    expect($this->getJson("/api/cost-centers/{$child}")->json('data.used'))->toBeTrue();

    $free = ccCreate($scope, 'CC-3');
    $this->deleteJson("/api/cost-centers/{$free}")->assertSuccessful();
});

test('the picker lists the company\'s active centers without needing the menu permission and hides other companies\'', function () {
    $scope = faScope();
    $other = faScope('FB');
    ccCreate($scope, 'CC-1');
    $inactive = ccCreate($scope, 'CC-2');
    ccCreate($other, 'CC-9');
    $this->putJson("/api/cost-centers/{$inactive}", ['code' => 'CC-2', 'name' => 'Old', 'type' => 'cost', 'active' => false])->assertSuccessful();

    $role = Role::query()->create(['name' => 'accountant', 'company_id' => $scope['company_id'], 'is_active' => true]);
    Sanctum::actingAs(createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']]));

    expect(collect($this->getJson('/api/fetchcostcenters?company_id='.$scope['company_id'])->assertSuccessful()->json())->pluck('code')->all())->toBe(['CC-1'])
        ->and($this->getJson('/api/fetchcostcenters?company_id='.$other['company_id'])->json())->toBeEmpty();

    $this->getJson('/api/cost-centers')->assertForbidden();
    $this->postJson('/api/cost-centers', ['code' => 'CC-5', 'name' => 'x', 'type' => 'cost'])->assertForbidden();
});

test('a journal line and an expense line can carry a cost center, which comes back when the voucher is opened', function () {
    $scope = jeaScope();
    jeaSetting($scope['company_id'], 'journal_entry', true);
    jeaSetting($scope['company_id'], 'expense_approval', true);
    $centerId = ccCreate($scope, 'CC-1');

    foreach ([['/api/journal-entries', 'JV'], ['/api/expenses', 'EXP']] as [$endpoint, $type]) {
        $payload = jeaPayload($scope, 500, $type);
        $payload['taccountdetails'][0]['cost_center_id'] = $centerId;

        $id = $this->postJson($endpoint, $payload)->assertSuccessful()->json('id') ?? DB::table('t_accounts')->where('voucher_no', 'like', $type.'-%')->value('id');
        $lines = DB::table('t_account_details')->where('t_account_id', $id)->orderBy('id')->get();

        expect((int) $lines[0]->cost_center_id)->toBe($centerId)->and($lines[1]->cost_center_id)->toBeNull();
    }

    $voucherId = (int) DB::table('t_accounts')->where('voucher_no', 'like', 'JV-%')->value('id');
    $form = $this->getJson("/api/journal-entries/{$voucherId}")->assertSuccessful()->json();
    expect(collect($form['taccountdetails'])->pluck('cost_center_id')->all())->toBe([$centerId, null]);
});

test('a line cannot use another company\'s cost center and a voucher without one still saves as before', function () {
    $scope = jeaScope();
    $otherCompany = faScope('FB');
    jeaSetting($scope['company_id'], 'journal_entry', true);
    $foreign = ccCreate($otherCompany, 'CC-9');

    $payload = jeaPayload($scope, 500);
    $payload['taccountdetails'][0]['cost_center_id'] = $foreign;

    $this->postJson('/api/journal-entries', $payload)->assertUnprocessable()->assertJsonValidationErrors(['taccountdetails']);
    expect(DB::table('t_accounts')->count())->toBe(0);

    $this->postJson('/api/journal-entries', jeaPayload($scope, 500))->assertSuccessful();
    expect(DB::table('t_account_details')->whereNotNull('cost_center_id')->count())->toBe(0);
});

/**
 * Cost centers A and C (cost) and B (profit) in two departments, with approved vouchers spread over them.
 *
 * @return array{scope: array<string, mixed>, centers: array<string, int>, branch2: int, departments: array<string, int>}
 */
function ccBooks(): array
{
    $scope = faScope();
    $branch2 = trpBranch($scope['company_id'], 'Second Branch');
    ldgAccount($scope, '501-00001', 'Service Revenue', 't', 'cr');
    $d1 = ccDepartment($scope['company_id'], 'Operations');
    $d2 = ccDepartment($scope['company_id'], 'Sales');
    $centers = [
        'A' => ccCreate($scope, 'CC-A', ['name' => 'Plant', 'department_id' => $d1]),
        'B' => ccCreate($scope, 'CC-B', ['name' => 'Studio', 'type' => 'profit', 'department_id' => $d1]),
        'C' => ccCreate($scope, 'CC-C', ['name' => 'Office', 'department_id' => $d2]),
    ];

    $post = function (string $no, string $date, string $code, float $debit, float $credit, ?int $center, array $attributes = [], ?array $scopeOverride = null) use ($scope): void {
        $use = $scopeOverride ?? $scope;
        $voucher = ldgVoucher($use, $no, $date, [[$code, $debit, $credit], ['202-00010', $credit, $debit]], $attributes);

        if ($center !== null) {
            DB::table('t_account_details')->where('t_account_id', $voucher)->where('account_code', $code)->update(['cost_center_id' => $center]);
        }
    };

    $post('JV-1', '2026-09-05', '401-00010', 300, 0, $centers['A']);
    $post('JV-2', '2026-09-06', '401-00010', 200, 0, $centers['B']);
    $post('JV-3', '2026-09-07', '501-00001', 0, 1000, $centers['B']);
    $post('JV-4', '2026-09-08', '401-00010', 50, 0, $centers['C']);
    $post('JV-5', '2026-09-09', '401-00010', 25, 0, null);
    $post('JV-6', '2026-09-10', '401-00010', 40, 0, $centers['A'], ['branch_id' => $branch2], array_merge($scope, ['branch_id' => $branch2]));
    $post('JV-7', '2026-09-11', '401-00010', 999, 0, $centers['A'], ['status' => 'pending']);
    $post('JV-8', '2026-08-01', '401-00010', 888, 0, $centers['A']);

    return ['scope' => $scope, 'centers' => $centers, 'branch2' => $branch2, 'departments' => ['d1' => $d1, 'd2' => $d2]];
}

/**
 * @param  array<string, mixed>  $books
 * @return Collection<int, array<string, mixed>>
 */
function ccReport(array $books, string $group): Collection
{
    return prpRows(prpGet('cost-center-analysis', ['show_record' => 100, 'company_id' => $books['scope']['company_id'], 'status' => $group, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30']))->keyBy('name');
}

test('the analysis groups approved vouchers by cost center and leaves out pending ones and other dates', function () {
    $books = ccBooks();

    $rows = ccReport($books, 'cost_center');

    expect($rows->keys()->sort()->values()->all())->toBe(['CC-A - Plant', 'CC-B - Studio', 'CC-C - Office', 'Unallocated'])
        ->and($rows['CC-A - Plant']['expenses'])->toEqual(340)
        ->and($rows['CC-B - Studio']['revenue'])->toEqual(1000)
        ->and($rows['CC-B - Studio']['expenses'])->toEqual(200)
        ->and($rows['CC-B - Studio']['net'])->toEqual(800)
        ->and($rows['CC-B - Studio']['kind'])->toBe('profit')
        ->and($rows['CC-C - Office']['expenses'])->toEqual(50)
        ->and($rows['Unallocated']['expenses'])->toEqual(25);

    $summary = prpGet('cost-center-analysis', ['company_id' => $books['scope']['company_id'], 'start_date' => '2026-09-01', 'end_date' => '2026-09-30'])->json('summary');
    expect($summary['revenue'])->toEqual(1000)->and($summary['expenses'])->toEqual(615)->and($summary['net'])->toEqual(385);
});

test('the same books grouped by department and by branch give the department-wise and branch-wise view', function () {
    $books = ccBooks();

    $byDepartment = ccReport($books, 'department');
    expect($byDepartment->keys()->sort()->values()->all())->toBe(['No department', 'Operations', 'Sales'])
        ->and($byDepartment['Operations']['revenue'])->toEqual(1000)
        ->and($byDepartment['Operations']['expenses'])->toEqual(540)
        ->and($byDepartment['Operations']['net'])->toEqual(460)
        ->and($byDepartment['Sales']['expenses'])->toEqual(50)
        ->and($byDepartment['No department']['expenses'])->toEqual(25);

    $byBranch = ccReport($books, 'branch');
    expect($byBranch['Second Branch']['expenses'])->toEqual(40)
        ->and($byBranch['Report Branch FA']['expenses'])->toEqual(575)
        ->and($byBranch['Report Branch FA']['revenue'])->toEqual(1000);

    $oneBranch = prpRows(prpGet('cost-center-analysis', ['show_record' => 100, 'company_id' => $books['scope']['company_id'], 'branch_id' => $books['branch2'], 'start_date' => '2026-09-01', 'end_date' => '2026-09-30']));
    expect($oneBranch)->toHaveCount(1)->and($oneBranch[0]['expenses'])->toEqual(40);
});

test('the analysis needs a company and its own permission', function () {
    $books = ccBooks();

    expect(prpGet('cost-center-analysis', ['show_record' => 100])->json('needs_company'))->toBeTrue();

    $role = Role::query()->create(['name' => 'analyst', 'company_id' => $books['scope']['company_id'], 'is_active' => true]);
    Sanctum::actingAs(createStaffUserForRole($role, ['company_id' => $books['scope']['company_id'], 'branch_id' => $books['scope']['branch_id']]));

    prpGet('cost-center-analysis', ['company_id' => $books['scope']['company_id']])->assertForbidden();

    grantMenuPermission($role->id, '/report/cost-center-analysis');
    prpGet('cost-center-analysis', ['company_id' => $books['scope']['company_id']])->assertSuccessful();
});

test('depreciation tags its expense line with the asset\'s cost center and books one voucher per center', function () {
    $scope = faScope();
    $one = ccCreate($scope, 'CC-1');
    $two = ccCreate($scope, 'CC-2');
    $categoryId = faCategory($scope);
    $first = fdAsset($scope, $categoryId, ['cost_center_id' => $one]);
    $second = fdAsset($scope, $categoryId, ['name' => 'Second', 'cost_center_id' => $two]);
    $none = fdAsset($scope, $categoryId, ['name' => 'Third']);

    $result = test()->postJson('/api/depreciation/run', ['company_id' => $scope['company_id'], 'period' => '2026-01'])->assertSuccessful()->json('data');

    expect($result['vouchers'])->toBe(3)->and($result['count'])->toBe(3);

    $expenseLines = DB::table('t_account_details')->where('account_code', '401-00010')->orderBy('id')->get();
    $accumulatedLines = DB::table('t_account_details')->where('account_code', '201-00011')->get();

    expect($expenseLines->pluck('cost_center_id')->map(fn ($id) => $id === null ? null : (int) $id)->sort()->values()->all())->toBe([null, $one, $two])
        ->and($accumulatedLines->whereNotNull('cost_center_id'))->toHaveCount(0);

    expect((int) FixedAsset::query()->findOrFail($first)->cost_center_id)->toBe($one)
        ->and(FixedAsset::query()->findOrFail($none)->cost_center_id)->toBeNull();
});

test('an asset takes only its own company\'s cost center, and an impairment charge carries it', function () {
    $scope = faScope();
    $other = faScope('FB');
    $categoryId = faCategory($scope);
    $center = ccCreate($scope, 'CC-1');

    $this->postJson('/api/fixed-assets', array_merge(faAssetPayload($scope, $categoryId), ['cost_center_id' => ccCreate($other, 'CC-9')]))->assertUnprocessable()->assertJsonValidationErrors(['cost_center_id']);

    $id = fdAsset($scope, $categoryId, ['cost_center_id' => $center]);
    $eventId = $this->postJson("/api/fixed-assets/{$id}/impair", ['date' => '2026-01-15', 'amount' => 500, 'reason' => 'Dent'])->assertSuccessful()->json('data.id');
    $this->postJson("/api/asset-approvals/{$eventId}/approve")->assertSuccessful();

    expect((int) DB::table('t_account_details')->where('account_code', '401-00011')->value('cost_center_id'))->toBe($center)
        ->and(DB::table('t_account_details')->where('account_code', '201-00011')->whereNotNull('cost_center_id')->count())->toBe(0);
});

test('a transfer can move the asset to another cost center', function () {
    $scope = faScope();
    $second = trpBranch($scope['company_id'], 'Second Branch');
    $from = ccCreate($scope, 'CC-1');
    $to = ccCreate($scope, 'CC-2');
    $id = fdAsset($scope, faCategory($scope), ['cost_center_id' => $from]);

    $this->postJson("/api/fixed-assets/{$id}/transfer", ['to_branch_id' => $second, 'to_cost_center_id' => $to, 'date' => '2026-02-01'])->assertSuccessful()
        ->assertJsonPath('data.cost_center_id', $to);

    $payload = AssetEvent::query()->where('type', 'transfer')->firstOrFail()->payload;
    expect($payload['from_cost_center_id'])->toBe($from)->and($payload['to_cost_center_id'])->toBe($to);

    $this->postJson("/api/fixed-assets/{$id}/transfer", ['to_branch_id' => $scope['branch_id'], 'to_cost_center_id' => 999999, 'date' => '2026-03-01'])->assertUnprocessable()->assertJsonValidationErrors(['to_cost_center_id']);
});

test('the cost center menu migration adds the page, its hidden rows and the report, and grants them to companyadmin', function () {
    DB::table('permissions')->delete();
    DB::table('menus')->delete();
    $accounts = DB::table('menus')->insertGetId([
        'parent_id' => null, 'name' => 'Accounts', 'icon' => '', 'route_name' => 'accgroup', 'route_path' => '', 'menu_color' => '#000', 'sort_order' => 1,
        'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 0, 'type' => 2, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $reports = DB::table('menus')->insertGetId([
        'parent_id' => null, 'name' => 'Reports', 'icon' => '', 'route_name' => 'reportsgroup', 'route_path' => '', 'menu_color' => '#000', 'sort_order' => 2,
        'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 0, 'type' => 2, 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ([[$accounts, 'Journal Entries', '/journalentry'], [$reports, 'Stock', '/report/stock']] as [$parent, $name, $path]) {
        DB::table('menus')->insert([
            'parent_id' => $parent, 'name' => $name, 'icon' => '', 'route_name' => ltrim($path, '/'), 'route_path' => $path, 'menu_color' => '#000', 'sort_order' => 1,
            'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $roleId = DB::table('roles')->insertGetId(['name' => 'companyadmin', 'company_id' => null, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    (require database_path('migrations/2026_10_16_100100_add_cost_center_menus.php'))->up();

    expect(DB::table('menus')->where('route_path', 'like', '/costcenter%')->orderBy('route_path')->pluck('route_path')->all())->toBe(['/costcenter', '/costcenter/:id/edit', '/costcenter/add', '/costcenter/delete'])
        ->and((int) DB::table('menus')->where('route_path', '/costcenter')->value('parent_id'))->toBe($accounts)
        ->and((int) DB::table('menus')->where('route_path', '/report/cost-center-analysis')->value('parent_id'))->toBe($reports)
        ->and(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(5);
});
