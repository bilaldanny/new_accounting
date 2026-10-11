<?php

use App\Models\AccountBalance;
use App\Models\AuditLog;
use App\Models\PeriodLockEntry;
use App\Models\Role;
use App\Models\User;
use App\Services\PeriodLock;
use App\Services\Reports\BalanceSheetReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Year rollover: the closing balances of a locked year become the opening balances of the next one, the profit of the year goes to
 * retained earnings, nothing is posted, and it can be run again.
 */
beforeEach(function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
});

/**
 * A company with a 2026 year (active) and a 2027 year, five accounts and a year of activity: cash 1000 and capital 1000 to start,
 * a sale of 500.55 and an expense of 200, so the closing cash is 1300.55 and the profit 300.55.
 *
 * @return array<string, mixed>
 */
function yrScope(bool $mapRetained = true): array
{
    $scope = trpScope('YR');
    $from = prpFinancialYear($scope['company_id'], '2026-01-01', '2026-12-31', ['name' => 'FY 2026']);
    $to = prpFinancialYear($scope['company_id'], '2027-01-01', '2027-12-31', ['name' => 'FY 2027', 'status' => 0]);

    $accounts = [
        'cash' => ldgAccount($scope, '201-00001', 'Cash', 't', 'dr'),
        'capital' => ldgAccount($scope, '101-00001', 'Capital', 't', 'cr'),
        'retained' => ldgAccount($scope, '101-00002', 'Retained Earnings', 't', 'cr'),
        'sales' => ldgAccount($scope, '501-00001', 'Sales', 't', 'cr'),
        'rent' => ldgAccount($scope, '401-00001', 'Rent', 't', 'dr'),
    ];

    foreach ([['cash', 1000, 'dr'], ['capital', 1000, 'cr']] as [$key, $amount, $nature]) {
        DB::table('account_balances')->insert([
            'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'financial_id' => $from, 'coa_id' => $accounts[$key],
            'opening_balance' => $amount, 'acc_nature' => $nature, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    ldgVoucher($scope, 'JV-00001', '2026-03-10', [['201-00001', 500.55, 0], ['501-00001', 0, 500.55]]);
    ldgVoucher($scope, 'JV-00002', '2026-09-10', [['401-00001', 200, 0], ['201-00001', 0, 200]]);

    if ($mapRetained) {
        insertPurchaseAccountMapping($scope, 'Profit And Loss', 'pnl', $accounts['retained']);
    }

    return $scope + ['from' => $from, 'to' => $to, 'accounts' => $accounts];
}

function yrLock(array $scope, string $through): void
{
    PeriodLockEntry::query()->create(['company_id' => $scope['company_id'], 'locked_through' => $through, 'action' => 'lock', 'locked_by' => 1]);
}

/**
 * @return array<string, array{opening: float, nature: string}>
 */
function yrOpenings(array $scope): array
{
    return AccountBalance::query()->where('financial_id', $scope['to'])->with('account:id,code')->get()
        ->mapWithKeys(fn (AccountBalance $row): array => [(string) $row->account->code => ['opening' => (float) $row->opening_balance, 'nature' => $row->acc_nature]])
        ->sortKeys()->all();
}

/**
 * @return array<string, mixed>
 */
function yrQuery(array $scope): array
{
    return ['company_id' => $scope['company_id'], 'from_year_id' => $scope['from'], 'to_year_id' => $scope['to']];
}

// ------------------------------------------------------------------ what blocks it

test('the rollover waits for the lock, and for the retained earnings account, and writes nothing meanwhile', function () {
    $scope = yrScope(mapRetained: false);

    $unlocked = $this->getJson('/api/year-rollover/preview?'.http_build_query(yrQuery($scope)))->assertOk()->json('data');
    expect($unlocked['can_run'])->toBeFalse()
        ->and(implode(' ', $unlocked['blockers']))->toContain('Lock the books through 2026-12-31')->toContain('Profit And Loss');

    yrLock($scope, '2026-11-30');
    $this->postJson('/api/year-rollover', yrQuery($scope))->assertStatus(422);
    yrLock($scope, '2026-12-31');
    $this->postJson('/api/year-rollover', yrQuery($scope))->assertStatus(422);

    expect(AccountBalance::query()->where('financial_id', $scope['to'])->count())->toBe(0);
});

test('the new year must come after the closing year and belong to the same company', function () {
    $scope = yrScope();
    yrLock($scope, '2026-12-31');

    $this->getJson('/api/year-rollover/preview?'.http_build_query(['from_year_id' => $scope['to'], 'to_year_id' => $scope['from']] + yrQuery($scope)))->assertStatus(422);
    $this->getJson('/api/year-rollover/preview?'.http_build_query(['to_year_id' => 99999] + yrQuery($scope)))->assertStatus(422);
});

// ------------------------------------------------------------------ the rollover

test('the preview shows what will be written and the run writes exactly that, paisa kept', function () {
    $scope = yrScope();
    yrLock($scope, '2026-12-31');

    $preview = $this->getJson('/api/year-rollover/preview?'.http_build_query(yrQuery($scope)))->assertOk()->json('data');
    $rows = collect($preview['branches'][0]['rows'])->keyBy('code');

    expect($preview['can_run'])->toBeTrue()
        ->and($preview['branches'][0]['net_profit'])->toEqual(300.55)
        ->and($preview['branches'][0]['difference'])->toEqual(0)
        ->and($rows['201-00001']['opening'])->toEqual(1300.55)->and($rows['201-00001']['nature'])->toBe('dr')
        ->and($rows['101-00001']['opening'])->toEqual(1000)->and($rows['101-00001']['nature'])->toBe('cr')
        ->and($rows['101-00002']['opening'])->toEqual(300.55)->and($rows['101-00002']['nature'])->toBe('cr')
        ->and($rows->keys()->all())->not->toContain('501-00001')
        ->and($rows->keys()->all())->not->toContain('401-00001')
        ->and(AccountBalance::query()->where('financial_id', $scope['to'])->count())->toBe(0);

    $this->postJson('/api/year-rollover', yrQuery($scope))->assertSuccessful()->assertJsonPath('data.written', 3);

    expect(yrOpenings($scope))->toBe([
        '101-00001' => ['opening' => 1000.0, 'nature' => 'cr'],
        '101-00002' => ['opening' => 300.55, 'nature' => 'cr'],
        '201-00001' => ['opening' => 1300.55, 'nature' => 'dr'],
    ])->and(AuditLog::query()->where('event', 'year_rollover')->count())->toBe(1)
        ->and(DB::table('t_accounts')->count())->toBe(2);
});

test('running it again changes nothing, and after a late entry it is recomputed to the same rows plus the entry', function () {
    $scope = yrScope();
    yrLock($scope, '2026-12-31');
    $this->postJson('/api/year-rollover', yrQuery($scope))->assertSuccessful();

    $again = $this->postJson('/api/year-rollover', yrQuery($scope))->assertSuccessful()->json('data');
    expect($again['written'])->toBe(0)->and(AccountBalance::query()->where('financial_id', $scope['to'])->count())->toBe(3);

    // A late entry for 2026 is let in (reopen, post, lock again), then the rollover is run once more.
    PeriodLockEntry::query()->create(['company_id' => $scope['company_id'], 'locked_through' => '2026-11-30', 'action' => 'reopen', 'locked_by' => 1]);
    ldgVoucher($scope, 'JV-00003', '2026-12-20', [['201-00001', 100, 0], ['501-00001', 0, 100]]);
    yrLock($scope, '2026-12-31');

    $this->postJson('/api/year-rollover', yrQuery($scope))->assertSuccessful();

    expect(yrOpenings($scope))->toBe([
        '101-00001' => ['opening' => 1000.0, 'nature' => 'cr'],
        '101-00002' => ['opening' => 400.55, 'nature' => 'cr'],
        '201-00001' => ['opening' => 1400.55, 'nature' => 'dr'],
    ])->and(AccountBalance::query()->where('financial_id', $scope['to'])->count())->toBe(3);
});

test('a profit and loss account of the new year that already had an opening row is brought back to zero', function () {
    $scope = yrScope();
    yrLock($scope, '2026-12-31');
    DB::table('account_balances')->insert([
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'financial_id' => $scope['to'], 'coa_id' => $scope['accounts']['sales'],
        'opening_balance' => 777, 'acc_nature' => 'cr', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->postJson('/api/year-rollover', yrQuery($scope))->assertSuccessful();

    expect(yrOpenings($scope)['501-00001'])->toBe(['opening' => 0.0, 'nature' => 'dr']);
});

test('after the new year is made the active one the balance sheet opens on the carried balances', function () {
    $scope = yrScope();
    yrLock($scope, '2026-12-31');
    $this->postJson('/api/year-rollover', yrQuery($scope))->assertSuccessful();

    DB::table('financial_years')->where('id', $scope['from'])->update(['status' => 0]);
    DB::table('financial_years')->where('id', $scope['to'])->update(['status' => 1]);

    $sheet = app(BalanceSheetReport::class)->build($scope['company_id'], $scope['branch_id'], ['end_date' => '2027-01-02']);

    expect($sheet['summary']['total_assets'])->toBe(1300.55)
        ->and($sheet['summary']['total_equity'])->toBe(1300.55)
        ->and($sheet['summary']['difference'])->toBe(0.0);
});

test('opening balances keep their paisa now that the column is a decimal', function () {
    $scope = yrScope();

    $this->postJson('/api/account-balances', [
        'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'financial_id' => $scope['from'],
        'accounts' => [['id' => $scope['accounts']['cash'], 'opening_balance' => 1234.56, 'acc_nature' => 'dr']],
    ]);

    expect((float) DB::table('account_balances')->where('financial_id', $scope['from'])->where('coa_id', $scope['accounts']['cash'])->value('opening_balance'))->toBe(1234.56);
});

// ------------------------------------------------------------------ who may

test('only a role holding the rollover menu may preview or run it', function () {
    $scope = yrScope();
    yrLock($scope, '2026-12-31');
    $role = Role::query()->create(['name' => 'Accountant', 'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'is_active' => true]);
    $staff = createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'email_verified_at' => now()]);
    Sanctum::actingAs($staff);

    $query = ['from_year_id' => $scope['from'], 'to_year_id' => $scope['to']];
    $this->getJson('/api/year-rollover/preview?'.http_build_query($query))->assertForbidden();
    $this->postJson('/api/year-rollover', $query)->assertForbidden();

    grantMenuPermission((int) $role->id, '/period-lock/rollover');
    $this->getJson('/api/year-rollover/preview?'.http_build_query($query))->assertOk();
    $this->postJson('/api/year-rollover', $query)->assertSuccessful();
    expect(app(PeriodLock::class)->lockedThrough($scope['company_id']))->toBe('2026-12-31');
});

// ------------------------------------------------------------------ the year-end close on top of it

test('closing a year runs the rollover, records the closing statement and activates the new year, posting nothing', function () {
    $scope = yrScope();
    yrLock($scope, '2026-12-31');
    $vouchers = DB::table('t_accounts')->count();

    $preview = $this->getJson('/api/year-close/preview?'.http_build_query(yrQuery($scope)))->assertOk()->json('data');

    expect($preview['can_run'])->toBeTrue()
        ->and($preview['statement']['net_result'])->toEqual(300.55)
        ->and(collect($preview['statement']['branches'][0]['lines'])->pluck('code')->sort()->values()->all())->toBe(['401-00001', '501-00001']);

    $this->postJson('/api/year-close', yrQuery($scope))->assertOk();

    $closing = DB::table('year_closings')->where('from_year_id', $scope['from'])->first();
    expect($closing)->not->toBeNull()
        ->and((float) $closing->net_result)->toBe(300.55)
        ->and((bool) DB::table('financial_years')->where('id', $scope['to'])->value('status'))->toBeTrue()
        ->and((bool) DB::table('financial_years')->where('id', $scope['from'])->value('status'))->toBeFalse()
        ->and(yrOpenings($scope))->not->toBeEmpty()
        ->and(DB::table('t_accounts')->count())->toBe($vouchers);

    // run again: still one closing record
    $this->postJson('/api/year-close', yrQuery($scope))->assertOk();
    expect(DB::table('year_closings')->where('company_id', $scope['company_id'])->count())->toBe(1);

    $list = $this->getJson('/api/year-close?company_id='.$scope['company_id'])->assertOk()->json();
    expect($list['data'])->toHaveCount(1)->and($list['years'])->toHaveCount(2);
});

test('a year close waits for the lock and records nothing while it is blocked', function () {
    $scope = yrScope();

    $this->postJson('/api/year-close', yrQuery($scope))->assertStatus(422);
    yrLock($scope, '2026-11-30');
    $this->postJson('/api/year-close', yrQuery($scope))->assertStatus(422);

    expect(DB::table('year_closings')->count())->toBe(0)
        ->and((bool) DB::table('financial_years')->where('id', $scope['from'])->value('status'))->toBeTrue()
        ->and(AccountBalance::query()->where('financial_id', $scope['to'])->count())->toBe(0);
});
