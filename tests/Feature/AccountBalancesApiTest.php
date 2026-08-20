<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function seedOpeningBalanceScope(): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'OB001',
        'name' => 'Opening Balance Company',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $branchId = DB::table('branches')->insertGetId([
        'code' => 'OBB001',
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

    $cashId = DB::table('chart_of_accounts')->insertGetId([
        'company_id' => $companyId,
        'branch_id' => $branchId,
        'parent_id' => $assetsId,
        'name' => 'Cash On Hand',
        'code' => '201-00001',
        'bs' => 1,
        'acc_nature' => 'dr',
        'acc_type' => 't',
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $financialId = DB::table('financial_years')->insertGetId([
        'company_id' => $companyId,
        'name' => 'FY 2025',
        'start_date' => '2025-01-01',
        'end_date' => '2025-12-31',
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'company_id' => $companyId,
        'branch_id' => $branchId,
        'assets_id' => $assetsId,
        'cash_id' => $cashId,
        'financial_id' => $financialId,
    ];
}

test('guests cannot access account balances api', function () {
    $this->getJson('/api/account-balances/fetch-balance')
        ->assertUnauthorized();
});

test('opening balance web route requires authentication', function () {
    $this->get(route('opening-balance'))
        ->assertRedirect();
});

test('fetch balance returns transaction accounts under selected parent', function () {
    $scope = seedOpeningBalanceScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/account-balances/fetch-balance?'.http_build_query([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'financial_id' => $scope['financial_id'],
        'account_id' => $scope['assets_id'],
    ]));

    $response->assertSuccessful()
        ->assertJsonCount(1)
        ->assertJsonFragment([
            'id' => $scope['cash_id'],
            'code' => '201-00001',
            'name' => 'Cash On Hand',
            'opening_balance' => 0,
            'acc_nature' => 'dr',
        ]);
});

test('account balances store upserts opening balances', function () {
    $scope = seedOpeningBalanceScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/account-balances', [
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'financial_id' => $scope['financial_id'],
        'accounts' => [
            [
                'id' => $scope['cash_id'],
                'opening_balance' => 1500,
                'acc_nature' => 'dr',
            ],
        ],
    ])->assertSuccessful()
        ->assertJson(['message' => 'Successfully Saved']);

    $this->assertDatabaseHas('account_balances', [
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'financial_id' => $scope['financial_id'],
        'coa_id' => $scope['cash_id'],
        'opening_balance' => 1500,
        'acc_nature' => 'dr',
    ]);

    $this->postJson('/api/account-balances', [
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'financial_id' => $scope['financial_id'],
        'accounts' => [
            [
                'id' => $scope['cash_id'],
                'opening_balance' => 2500,
                'acc_nature' => 'cr',
            ],
        ],
    ])->assertSuccessful();

    $this->assertDatabaseHas('account_balances', [
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'financial_id' => $scope['financial_id'],
        'coa_id' => $scope['cash_id'],
        'opening_balance' => 2500,
        'acc_nature' => 'cr',
    ]);

    $this->assertDatabaseCount('account_balances', 1);
});

test('fetch ob accounts returns balance sheet accounts only', function () {
    $scope = seedOpeningBalanceScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/fetchobaccounts?'.http_build_query([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
    ]));

    $response->assertSuccessful();

    $codes = collect($response->json())->pluck('code');

    expect($codes)->toContain('200-00000', '100-00000', '300-00000')
        ->not->toContain('400-00000', '500-00000', '600-00000');
});

test('opening balance menu migration updates route path', function () {
    $menuId = DB::table('menus')->insertGetId([
        'parent_id' => null,
        'name' => 'Opening Balance',
        'icon' => 'Scale',
        'route_name' => 'openingbalance',
        'route_path' => '/openingbalance',
        'menu_color' => '#000000',
        'sort_order' => 0,
        'is_hidden' => 1,
        'is_active' => 1,
        'is_admin' => 0,
        'is_permission' => 0,
        'type' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    /** @var object{up: callable(): void} $migration */
    $migration = require database_path('migrations/2026_08_21_000002_update_opening_balance_menu_route.php');
    $migration->up();

    $menu = DB::table('menus')->where('id', $menuId)->first();

    expect($menu->route_name)->toBe('opening-balance')
        ->and($menu->route_path)->toBe('/opening-balance')
        ->and($menu->is_hidden)->toBe(0);
});
