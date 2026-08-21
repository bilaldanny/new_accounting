<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function seedChartOfAccountScope(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'COA001',
        'name' => 'Chart Of Account Company',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $branchId = DB::table('branches')->insertGetId([
        'code' => 'COAB001',
        'company_id' => $companyId,
        'name' => 'Main Branch',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    parentChartOfAccount($companyId, $branchId);

    $assetsId = DB::table('chart_of_accounts')
        ->where('company_id', $companyId)
        ->where('branch_id', $branchId)
        ->where('code', '200-00000')
        ->value('id');

    return [
        'company_id' => $companyId,
        'branch_id' => $branchId,
        'assets_id' => $assetsId,
    ];
}

test('guests cannot access chart of accounts api', function () {
    $this->getJson('/api/chart-of-accounts')
        ->assertUnauthorized();
});

test('chart of account web route requires authentication', function () {
    $this->get(route('chart-of-account'))
        ->assertRedirect();
});

test('chart of accounts index returns hierarchical accounts', function () {
    $scope = seedChartOfAccountScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/chart-of-accounts?'.http_build_query([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'status' => 'all',
    ]));

    $response->assertSuccessful()
        ->assertJsonCount(6)
        ->assertJsonFragment(['name' => 'Assets', 'code' => '200-00000']);
});

test('chart of accounts store creates a child account', function () {
    $scope = seedChartOfAccountScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/chart-of-accounts', [
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'parent_id' => $scope['assets_id'],
        'code' => '201-00001',
        'name' => 'Petty Cash',
        'acc_type' => 't',
        'acc_nature' => 'dr',
        'active' => true,
        'pl' => false,
        'bs' => true,
    ])->assertSuccessful()
        ->assertJson(['message' => 'Successfully Saved']);

    $this->assertDatabaseHas('chart_of_accounts', [
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'parent_id' => $scope['assets_id'],
        'code' => '201-00001',
        'name' => 'Petty Cash',
        'acc_type' => 't',
        'acc_nature' => 'dr',
        'bs' => 1,
        'pl' => 0,
    ]);
});

test('chart of accounts store auto sets balance sheet flags from parent classification', function () {
    $scope = seedChartOfAccountScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/chart-of-accounts', [
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'parent_id' => $scope['assets_id'],
        'code' => '200-00001',
        'name' => 'Cash On Hand',
        'acc_type' => 't',
        'acc_nature' => 'dr',
        'active' => true,
        'pl' => true,
        'bs' => false,
    ])->assertSuccessful();

    $this->assertDatabaseHas('chart_of_accounts', [
        'code' => '200-00001',
        'bs' => 1,
        'pl' => 0,
    ]);
});

test('chart of accounts update changes editable fields only', function () {
    $scope = seedChartOfAccountScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $accountId = DB::table('chart_of_accounts')->insertGetId([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'parent_id' => $scope['assets_id'],
        'code' => '201-00002',
        'name' => 'Old Name',
        'acc_type' => 't',
        'acc_nature' => 'dr',
        'bs' => 1,
        'pl' => 0,
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->putJson("/api/chart-of-accounts/{$accountId}", [
        'parent_id' => $scope['assets_id'],
        'name' => 'Updated Name',
        'active' => false,
        'pl' => true,
        'bs' => false,
    ])->assertSuccessful()
        ->assertJson(['message' => 'Successfully Saved']);

    $this->assertDatabaseHas('chart_of_accounts', [
        'id' => $accountId,
        'name' => 'Updated Name',
        'code' => '201-00002',
        'acc_type' => 't',
        'acc_nature' => 'dr',
        'active' => 0,
        'pl' => 0,
        'bs' => 1,
    ]);
});

test('chart of accounts check code detects duplicates', function () {
    $scope = seedChartOfAccountScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/chart-of-accounts/check-code', [
        'code' => '200-00000',
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
    ])->assertSuccessful()
        ->assertJson(['code_taken' => true]);

    $this->postJson('/api/chart-of-accounts/check-code', [
        'code' => '299-99999',
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
    ])->assertSuccessful()
        ->assertJson(['code_taken' => false]);
});

test('chart of accounts generate code returns next child code', function () {
    $scope = seedChartOfAccountScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/chart-of-accounts/generate-code?'.http_build_query([
        'parent_id' => $scope['assets_id'],
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'acc_type' => 't',
    ]));

    $response->assertSuccessful()
        ->assertJson(['code' => '200-00001']);
});

test('chart of accounts generate code increments suffix for each transactional child', function () {
    $scope = seedChartOfAccountScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    DB::table('chart_of_accounts')->insert([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'parent_id' => $scope['assets_id'],
        'code' => '200-00001',
        'name' => 'Existing Asset Account',
        'acc_type' => 't',
        'acc_nature' => 'dr',
        'bs' => 1,
        'pl' => 0,
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->getJson('/api/chart-of-accounts/generate-code?'.http_build_query([
        'parent_id' => $scope['assets_id'],
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'acc_type' => 't',
    ]));

    $response->assertSuccessful()
        ->assertJson(['code' => '200-00002']);
});

test('chart of accounts generate code uses control prefix for control accounts', function () {
    $scope = seedChartOfAccountScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/chart-of-accounts/generate-code?'.http_build_query([
        'parent_id' => $scope['assets_id'],
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'acc_type' => 'c',
    ]));

    $response->assertSuccessful()
        ->assertJson(['code' => '210-00000']);
});

test('chart of accounts resolve from parent returns classification metadata', function () {
    $scope = seedChartOfAccountScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/chart-of-accounts/resolve-from-parent?'.http_build_query([
        'parent_id' => $scope['assets_id'],
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'acc_type' => 't',
    ]));

    $response->assertSuccessful()
        ->assertJson([
            'label' => 'Asset',
            'financial_statement' => 'Balance Sheet',
            'default_nature' => 'dr',
            'allow_transactions' => true,
            'bs' => true,
            'pl' => false,
        ]);
});

test('chart of accounts store rejects parent from another company', function () {
    $scope = seedChartOfAccountScope();
    $otherCompanyId = DB::table('companies')->insertGetId([
        'code' => 'COA002',
        'name' => 'Other Company',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $otherBranchId = DB::table('branches')->insertGetId([
        'code' => 'COAB002',
        'company_id' => $otherCompanyId,
        'name' => 'Other Branch',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    parentChartOfAccount($otherCompanyId, $otherBranchId);

    $otherAssetsId = DB::table('chart_of_accounts')
        ->where('company_id', $otherCompanyId)
        ->where('code', '200-00000')
        ->value('id');

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/chart-of-accounts', [
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'parent_id' => $otherAssetsId,
        'code' => '200-00001',
        'name' => 'Invalid Parent Account',
        'acc_type' => 't',
        'acc_nature' => 'dr',
        'active' => true,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['parent_id']);
});

test('chart of accounts store accepts string boolean values from vueform', function () {
    $scope = seedChartOfAccountScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/chart-of-accounts', [
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'parent_id' => $scope['assets_id'],
        'code' => '200-00003',
        'name' => 'Vueform Active Test',
        'acc_type' => 't',
        'acc_nature' => 'dr',
        'active' => 'true',
        'bs' => 'true',
        'pl' => 'false',
    ])->assertSuccessful();

    $this->assertDatabaseHas('chart_of_accounts', [
        'code' => '200-00003',
        'active' => 1,
        'bs' => 1,
        'pl' => 0,
    ]);
});

test('chart of accounts index returns opening balance for transactional accounts', function () {
    $scope = seedChartOfAccountScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/chart-of-accounts', [
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'parent_id' => $scope['assets_id'],
        'code' => '201-00001',
        'name' => 'Cash In Hand',
        'acc_type' => 't',
        'acc_nature' => 'dr',
        'active' => true,
        'pl' => false,
        'bs' => true,
    ])->assertSuccessful();

    $coaId = DB::table('chart_of_accounts')
        ->where('company_id', $scope['company_id'])
        ->where('branch_id', $scope['branch_id'])
        ->where('code', '201-00001')
        ->value('id');

    $financialYearId = DB::table('financial_years')->insertGetId([
        'company_id' => $scope['company_id'],
        'name' => 'FY 2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('account_balances')->insert([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'financial_id' => $financialYearId,
        'coa_id' => $coaId,
        'opening_balance' => 2500,
        'acc_nature' => 'dr',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->getJson('/api/chart-of-accounts?'.http_build_query([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'status' => 'all',
    ]));

    $response->assertSuccessful();

    $assetsNode = collect($response->json())
        ->firstWhere('code', '200-00000');

    $transactionalNode = collect($assetsNode['children'] ?? [])
        ->firstWhere('code', '201-00001');

    expect($transactionalNode['opening_balance'])->toBe(2500);
});

test('fetch control accounts returns active control accounts', function () {
    $scope = seedChartOfAccountScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/fetchcontrolaccounts?'.http_build_query([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
    ]));

    $response->assertSuccessful();

    expect(collect($response->json())->every(fn (array $row) => $row['acc_type'] === 'c'))->toBeTrue();
});
