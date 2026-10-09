<?php

use App\Models\BankStatement;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
});

/**
 * A company whose bank account has: +1000 on 2 Sep, -400 on 5 Sep, +250 on 28 Sep (a deposit not yet on the
 * statement) and -100 on 29 Sep (a cheque not yet cashed). The ledger balance on 30 Sep is 750.
 *
 * @return array{company_id: int, branch_id: int, bank: string, detail: array<string, int>}
 */
function brsScope(): array
{
    $scope = trpScope('B');
    ldgAccount($scope, '202-00010', 'Main Bank', 't', 'dr');
    ldgAccount($scope, '301-00001', 'Clearing', 't', 'cr');

    $detail = [];

    foreach ([['dep1', '2026-09-02', 1000, 0], ['chq1', '2026-09-05', 0, 400], ['dep2', '2026-09-28', 250, 0], ['chq2', '2026-09-29', 0, 100]] as [$key, $date, $debit, $credit]) {
        $voucher = ldgVoucher($scope, strtoupper($key), $date, [
            ['202-00010', $debit, $credit],
            ['301-00001', $credit, $debit],
        ], ['cheque_no' => $key === 'chq1' ? 'CHQ1' : '']);

        $detail[$key] = (int) DB::table('t_account_details')->where('t_account_id', $voucher)->where('account_code', '202-00010')->value('id');
    }

    return $scope + ['bank' => '202-00010', 'detail' => $detail];
}

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $overrides
 */
function brsPayload(array $scope, array $overrides = []): array
{
    return array_merge([
        'company_id' => $scope['company_id'],
        'account_code' => $scope['bank'],
        'statement_from' => '2026-09-01',
        'statement_to' => '2026-09-30',
        'opening_balance' => 0,
        'closing_balance' => 585,
        'rows' => [
            ['txn_date' => '2026-09-03', 'description' => 'Deposit', 'reference' => 'DEP1', 'amount' => 1000],
            ['txn_date' => '2026-09-06', 'description' => 'Cheque cleared', 'reference' => 'CHQ1', 'amount' => -400],
            ['txn_date' => '2026-09-30', 'description' => 'Bank charges', 'reference' => '', 'amount' => -15],
        ],
    ], $overrides);
}

function brsStatement(array $scope, array $overrides = []): int
{
    return test()->postJson('/api/bank-reconciliations', brsPayload($scope, $overrides))->assertSuccessful()->json('id');
}

test('a statement is saved with its lines and validated', function () {
    $scope = brsScope();
    $id = brsStatement($scope);

    $detail = $this->getJson("/api/bank-reconciliations/{$id}")->assertSuccessful()->json('data');

    expect($detail['lines'])->toHaveCount(3)
        ->and($detail['status'])->toBe('draft')
        ->and($detail['summary']['matched_count'])->toBe(0)
        ->and($detail['summary']['lines_total_matches_closing'])->toBeTrue();

    $this->postJson('/api/bank-reconciliations', brsPayload($scope, ['account_code' => '999-99999']))->assertUnprocessable()->assertJsonValidationErrors(['account_code']);
    $this->postJson('/api/bank-reconciliations', brsPayload($scope, ['statement_to' => '2026-08-01']))->assertUnprocessable()->assertJsonValidationErrors(['statement_to']);
    $this->postJson('/api/bank-reconciliations', brsPayload($scope, ['rows' => []]))->assertUnprocessable()->assertJsonValidationErrors(['rows']);
});

test('auto match pairs lines by amount, side and date and leaves what the books lack', function () {
    $scope = brsScope();
    $id = brsStatement($scope);

    $response = $this->postJson("/api/bank-reconciliations/{$id}/auto-match")->assertSuccessful();
    $lines = collect($response->json('data.lines'));

    expect($response->json('matched'))->toBe(2)
        ->and($lines->firstWhere('reference', 'DEP1')['matched_detail_id'])->toBe($scope['detail']['dep1'])
        ->and($lines->firstWhere('reference', 'CHQ1')['matched_detail_id'])->toBe($scope['detail']['chq1'])
        ->and($lines->firstWhere('description', 'Bank charges')['matched_detail_id'])->toBeNull();

    $summary = $response->json('data.summary');

    expect($summary['ledger_balance'])->toEqual(750)
        ->and($summary['outstanding_ledger_net'])->toEqual(150)
        ->and(collect($summary['outstanding_ledger'])->pluck('id')->sort()->values()->all())->toBe(collect([$scope['detail']['dep2'], $scope['detail']['chq2']])->sort()->values()->all())
        ->and($summary['unmatched_statement_net'])->toEqual(-15)
        ->and($summary['difference'])->toEqual(0)
        ->and($summary['is_balanced'])->toBeTrue();
});

test('a reference that agrees wins over the nearest date', function () {
    $scope = brsScope();
    $id = brsStatement($scope, ['rows' => [['txn_date' => '2026-09-06', 'reference' => 'CHQ1', 'amount' => -400]], 'closing_balance' => -400]);
    $twin = ldgVoucher($scope, 'CHQ-TWIN', '2026-09-06', [['202-00010', 0, 400], ['301-00001', 400, 0]]);
    $twinDetail = (int) DB::table('t_account_details')->where('t_account_id', $twin)->where('account_code', '202-00010')->value('id');

    $line = $this->postJson("/api/bank-reconciliations/{$id}/auto-match")->assertSuccessful()->json('data.lines.0');

    expect($line['matched_detail_id'])->toBe($scope['detail']['chq1'])->and($line['matched_detail_id'])->not->toBe($twinDetail);
});

test('a statement that does not reconcile is refused, and one that does is locked', function () {
    $scope = brsScope();
    $bad = brsStatement($scope, ['closing_balance' => 590]);
    $this->postJson("/api/bank-reconciliations/{$bad}/auto-match")->assertSuccessful();

    $this->postJson("/api/bank-reconciliations/{$bad}/reconcile")->assertUnprocessable()->assertJsonValidationErrors(['difference']);
    expect(BankStatement::query()->findOrFail($bad)->status)->toBe('draft');

    DB::table('bank_statements')->where('id', $bad)->delete();
    DB::table('bank_statement_lines')->where('bank_statement_id', $bad)->delete();

    $id = brsStatement($scope);
    $this->postJson("/api/bank-reconciliations/{$id}/auto-match")->assertSuccessful();
    $this->postJson("/api/bank-reconciliations/{$id}/reconcile")->assertSuccessful()->assertJsonPath('data.status', 'reconciled');

    $lineId = $this->getJson("/api/bank-reconciliations/{$id}")->json('data.lines.0.id');

    $this->postJson("/api/bank-reconciliations/{$id}/lines/{$lineId}/unmatch")->assertUnprocessable();
    $this->postJson("/api/bank-reconciliations/{$id}/auto-match")->assertUnprocessable();
    $this->postJson("/api/bank-reconciliations/{$id}/reconcile")->assertUnprocessable();
    $this->deleteJson("/api/bank-reconciliations/{$id}")->assertUnprocessable();
});

test('manual matching checks amount, side and that a ledger line is used once', function () {
    $scope = brsScope();
    $id = brsStatement($scope);
    $lines = collect($this->getJson("/api/bank-reconciliations/{$id}")->json('data.lines'));
    $deposit = $lines->firstWhere('reference', 'DEP1')['id'];
    $cheque = $lines->firstWhere('reference', 'CHQ1')['id'];

    $this->postJson("/api/bank-reconciliations/{$id}/lines/{$deposit}/match", ['detail_id' => $scope['detail']['chq1']])->assertUnprocessable()->assertJsonValidationErrors(['detail_id']);
    $this->postJson("/api/bank-reconciliations/{$id}/lines/{$deposit}/match", ['detail_id' => 999999])->assertUnprocessable();

    $this->postJson("/api/bank-reconciliations/{$id}/lines/{$deposit}/match", ['detail_id' => $scope['detail']['dep1']])->assertSuccessful();
    $this->postJson("/api/bank-reconciliations/{$id}/lines/{$cheque}/match", ['detail_id' => $scope['detail']['dep1']])->assertUnprocessable();

    $this->postJson("/api/bank-reconciliations/{$id}/lines/{$deposit}/unmatch")->assertSuccessful();

    expect(collect($this->getJson("/api/bank-reconciliations/{$id}")->json('data.lines'))->firstWhere('id', $deposit)['matched_detail_id'])->toBeNull();
});

test('an outstanding cheque carries over and clears on the next statement', function () {
    $scope = brsScope();
    $september = brsStatement($scope);
    $this->postJson("/api/bank-reconciliations/{$september}/auto-match")->assertSuccessful();
    $this->postJson("/api/bank-reconciliations/{$september}/reconcile")->assertSuccessful();

    $october = brsStatement($scope, [
        'statement_from' => '2026-10-01',
        'statement_to' => '2026-10-31',
        'opening_balance' => 585,
        'closing_balance' => 485 + 250,
        'rows' => [
            ['txn_date' => '2026-10-02', 'reference' => '', 'amount' => 250],
            ['txn_date' => '2026-10-03', 'reference' => '', 'amount' => -100],
            ['txn_date' => '2026-10-05', 'reference' => '', 'amount' => 0.0],
        ],
    ]);

    $before = $this->getJson("/api/bank-reconciliations/{$october}")->json('data.summary');

    expect(collect($before['outstanding_ledger'])->pluck('voucher_no')->sort()->values()->all())->toBe(['CHQ2', 'DEP2']);

    $this->postJson("/api/bank-reconciliations/{$october}/auto-match", ['days' => 10])->assertSuccessful()->assertJsonPath('matched', 2);

    $after = $this->getJson("/api/bank-reconciliations/{$october}")->json('data.summary');

    expect($after['outstanding_ledger'])->toBe([])->and($after['difference'])->toEqual(-15);
});

test('a company only sees its own statements and a user needs the permission', function () {
    $scope = brsScope();
    $id = brsStatement($scope);
    $other = trpScope('D');

    $role = Role::query()->create(['name' => 'accountant', 'company_id' => $other['company_id'], 'is_active' => true]);
    Sanctum::actingAs(createStaffUserForRole($role, ['company_id' => $other['company_id'], 'branch_id' => $other['branch_id']]));

    $this->getJson('/api/bank-reconciliations')->assertForbidden();

    grantMenuPermission($role->id, '/bankreconciliation', 'bankreconciliation'.uniqid());

    $this->getJson('/api/bank-reconciliations')->assertSuccessful()->assertJsonCount(0, 'data.data');
    $this->getJson("/api/bank-reconciliations/{$id}")->assertNotFound();
});

test('the menu migration adds the page next to the chart of accounts and grants companyadmin', function () {
    DB::table('permissions')->delete();
    DB::table('menus')->delete();
    $group = DB::table('menus')->insertGetId([
        'parent_id' => null, 'name' => 'Accounts', 'icon' => '', 'route_name' => 'accgroup', 'route_path' => '', 'menu_color' => '#000', 'sort_order' => 1,
        'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 0, 'type' => 2, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('menus')->insert([
        'parent_id' => $group, 'name' => 'Chart', 'icon' => '', 'route_name' => 'coa', 'route_path' => '/chart-of-account', 'menu_color' => '#000', 'sort_order' => 1,
        'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 0, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $roleId = DB::table('roles')->insertGetId(['name' => 'companyadmin', 'company_id' => null, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    (require database_path('migrations/2026_10_07_200100_add_bank_reconciliation_menu.php'))->up();

    expect(DB::table('menus')->where('route_path', 'like', '/bankreconciliation%')->count())->toBe(5)
        ->and((int) DB::table('menus')->where('route_path', '/bankreconciliation')->value('parent_id'))->toBe($group)
        ->and(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(5);
});
