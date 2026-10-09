<?php

use App\Models\Budget;
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
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $overrides
 */
function budPayload(array $scope, array $overrides = []): array
{
    return array_merge(['company_id' => $scope['company_id'], 'year' => 2026, 'month' => 9, 'amount' => 1000], $overrides);
}

/**
 * @param  array<string, mixed>  $scope
 * @param  array<string, mixed>  $overrides
 */
function budCreate(array $scope, array $overrides = []): int
{
    return test()->postJson('/api/budgets', budPayload($scope, $overrides))->assertSuccessful()->json('data.id');
}

test('a budget is saved for a month or a whole year, with its branch, cost center and account', function () {
    $scope = faScope();
    $center = ccCreate($scope, 'CC-1', ['name' => 'Plant']);

    $monthly = $this->postJson('/api/budgets', budPayload($scope, ['branch_id' => $scope['branch_id'], 'cost_center_id' => $center, 'coa_id' => $scope['accounts']['expense'], 'note' => 'Running costs']))
        ->assertSuccessful()->json('data');
    $yearly = $this->postJson('/api/budgets', budPayload($scope, ['month' => null, 'amount' => 12000]))->assertSuccessful()->json('data');

    expect($monthly['period'])->toBe('2026-09')
        ->and($monthly['cost_center_name'])->toBe('CC-1 - Plant')
        ->and($monthly['account_name'])->toBe('401-00010 - Depreciation Expense')
        ->and($monthly['branch_name'])->toBe('Report Branch FA')
        ->and($yearly['period'])->toBe('2026')
        ->and($yearly['month'])->toBeNull()
        ->and($yearly['cost_center_id'])->toBeNull()
        ->and(Budget::query()->findOrFail($yearly['id'])->monthlyAmount())->toBe(1000.0)
        ->and(Budget::query()->findOrFail($monthly['id'])->monthlyAmount())->toBe(1000.0);

    expect($this->getJson('/api/budgets?year=2026')->json('data.data'))->toHaveCount(2)
        ->and($this->getJson('/api/budgets?year=2025')->json('data.data'))->toBeEmpty();

    // A budget is planning data only: nothing reaches the ledger.
    expect(DB::table('t_accounts')->count())->toBe(0)->and(DB::table('t_account_details')->count())->toBe(0);
});

test('budget input is validated: own company\'s branch, center and account, and the account must be an expense or revenue one', function () {
    $scope = faScope();
    $other = faScope('FB');
    $foreignCenter = ccCreate($other, 'CC-9');

    $this->postJson('/api/budgets', budPayload($scope, ['branch_id' => $other['branch_id']]))->assertUnprocessable()->assertJsonValidationErrors(['branch_id']);
    $this->postJson('/api/budgets', budPayload($scope, ['cost_center_id' => $foreignCenter]))->assertUnprocessable()->assertJsonValidationErrors(['cost_center_id']);
    $this->postJson('/api/budgets', budPayload($scope, ['coa_id' => $other['accounts']['expense']]))->assertUnprocessable()->assertJsonValidationErrors(['coa_id']);
    $this->postJson('/api/budgets', budPayload($scope, ['coa_id' => $scope['accounts']['bank']]))->assertUnprocessable()->assertJsonValidationErrors(['coa_id']);
    $this->postJson('/api/budgets', budPayload($scope, ['amount' => 0]))->assertUnprocessable()->assertJsonValidationErrors(['amount']);
    $this->postJson('/api/budgets', budPayload($scope, ['month' => 13]))->assertUnprocessable()->assertJsonValidationErrors(['month']);
    $this->postJson('/api/budgets', budPayload($scope, ['year' => 1999]))->assertUnprocessable()->assertJsonValidationErrors(['year']);

    expect(Budget::query()->count())->toBe(0);
});

test('one budget per scope and period, and a yearly one cannot sit beside monthly ones', function () {
    $scope = faScope();
    $center = ccCreate($scope, 'CC-1');

    budCreate($scope, ['cost_center_id' => $center]);

    $this->postJson('/api/budgets', budPayload($scope, ['cost_center_id' => $center]))->assertUnprocessable()->assertJsonValidationErrors(['budget']);
    $this->postJson('/api/budgets', budPayload($scope, ['cost_center_id' => $center, 'month' => 10]))->assertSuccessful();
    $this->postJson('/api/budgets', budPayload($scope, ['cost_center_id' => $center, 'month' => null]))->assertUnprocessable()->assertJsonValidationErrors(['budget']);

    // Another scope is free: no cost center, another account, another year.
    $this->postJson('/api/budgets', budPayload($scope))->assertSuccessful();
    $this->postJson('/api/budgets', budPayload($scope, ['cost_center_id' => $center, 'coa_id' => $scope['accounts']['expense']]))->assertSuccessful();
    $this->postJson('/api/budgets', budPayload($scope, ['cost_center_id' => $center, 'year' => 2027]))->assertSuccessful();

    $yearly = budCreate($scope, ['coa_id' => $scope['accounts']['expense'], 'month' => null, 'year' => 2027]);
    $this->postJson('/api/budgets', budPayload($scope, ['coa_id' => $scope['accounts']['expense'], 'month' => 3, 'year' => 2027]))->assertUnprocessable()->assertJsonValidationErrors(['budget']);

    // Editing a line to itself is not a clash.
    $this->putJson("/api/budgets/{$yearly}", budPayload($scope, ['coa_id' => $scope['accounts']['expense'], 'month' => null, 'year' => 2027, 'amount' => 24000]))->assertSuccessful()->assertJsonPath('data.amount', 24000);
});

test('a budget can be edited, deleted and restored, and a restore that would clash is refused', function () {
    $scope = faScope();
    $id = budCreate($scope);

    $this->putJson("/api/budgets/{$id}", budPayload($scope, ['amount' => 2500, 'note' => 'Raised']))->assertSuccessful()->assertJsonPath('data.amount', 2500)->assertJsonPath('data.note', 'Raised');

    $this->deleteJson("/api/budgets/{$id}")->assertSuccessful();
    expect($this->getJson('/api/budgets')->json('data.data'))->toBeEmpty()
        ->and($this->getJson('/api/budgets?trashed=1')->json('data.data.0.deleted'))->toBeTrue()
        ->and(DB::table('budgets')->where('id', $id)->whereNotNull('deleted_at')->exists())->toBeTrue();

    $replacement = budCreate($scope);
    $this->postJson("/api/budgets/{$id}/restore")->assertUnprocessable()->assertJsonValidationErrors(['budget']);

    $this->deleteJson("/api/budgets/{$replacement}")->assertSuccessful();
    $this->postJson("/api/budgets/{$id}/restore")->assertSuccessful()->assertJsonPath('data.deleted', false);
    expect($this->getJson('/api/budgets')->json('data.data'))->toHaveCount(1);
});

test('a cost center with a budget cannot be deleted', function () {
    $scope = faScope();
    $center = ccCreate($scope, 'CC-1');
    budCreate($scope, ['cost_center_id' => $center]);

    $this->deleteJson("/api/cost-centers/{$center}")->assertUnprocessable()->assertJsonValidationErrors(['cost_center']);
});

test('another company\'s budgets are invisible and each action needs its own permission', function () {
    $scope = faScope();
    $other = faScope('FB');
    $mine = budCreate($scope);
    $theirs = budCreate($other);

    $role = Role::query()->create(['name' => 'planner', 'company_id' => $scope['company_id'], 'is_active' => true]);
    Sanctum::actingAs(createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']]));

    $this->getJson('/api/budgets')->assertForbidden();
    $this->postJson('/api/budgets', budPayload($scope, ['month' => 1]))->assertForbidden();

    grantMenuPermission($role->id, '/budget');
    $this->getJson('/api/budgets')->assertSuccessful()->assertJsonCount(1, 'data.data');
    $this->getJson("/api/budgets/{$theirs}")->assertNotFound();
    $this->putJson("/api/budgets/{$mine}", budPayload($scope))->assertForbidden();
    $this->deleteJson("/api/budgets/{$mine}")->assertForbidden();
    $this->postJson("/api/budgets/{$mine}/restore")->assertForbidden();

    grantMenuPermission($role->id, '/budget/add');
    $this->postJson('/api/budgets', budPayload($scope, ['month' => 1]))->assertSuccessful();
    $this->postJson('/api/budgets', budPayload($other, ['month' => 2]))->assertSuccessful();
    expect(Budget::query()->where('company_id', $scope['company_id'])->count())->toBe(3)
        ->and(Budget::query()->where('company_id', $other['company_id'])->count())->toBe(1);
});

test('the budget menu migration adds the page next to Cost Centers with its hidden rows and grants them to companyadmin', function () {
    DB::table('permissions')->delete();
    DB::table('menus')->delete();
    $group = DB::table('menus')->insertGetId([
        'parent_id' => null, 'name' => 'Accounts', 'icon' => '', 'route_name' => 'accgroup', 'route_path' => '', 'menu_color' => '#000', 'sort_order' => 1,
        'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 0, 'type' => 2, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('menus')->insert([
        'parent_id' => $group, 'name' => 'Cost Centers', 'icon' => '', 'route_name' => 'costcenter', 'route_path' => '/costcenter', 'menu_color' => '#000', 'sort_order' => 1,
        'is_hidden' => 0, 'is_active' => 1, 'is_admin' => 0, 'is_permission' => 1, 'type' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $roleId = DB::table('roles')->insertGetId(['name' => 'companyadmin', 'company_id' => null, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    (require database_path('migrations/2026_10_17_100100_add_budget_menu.php'))->up();

    expect(DB::table('menus')->where('route_path', 'like', '/budget%')->orderBy('route_path')->pluck('route_path')->all())->toBe(['/budget', '/budget/:id/edit', '/budget/add', '/budget/delete', '/budget/restore'])
        ->and((int) DB::table('menus')->where('route_path', '/budget')->value('parent_id'))->toBe($group)
        ->and(DB::table('permissions')->where('role_id', $roleId)->count())->toBe(5);
});
