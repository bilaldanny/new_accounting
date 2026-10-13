<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\Company\CompanyBootstrap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * @return array<string, int>
 */
function bootstrapFootprint(int $companyId): array
{
    return [
        'accounts' => DB::table('chart_of_accounts')->where('company_id', $companyId)->count(),
        'accounts_sum' => (int) DB::table('chart_of_accounts')->where('company_id', $companyId)->sum('id'),
        'mappings' => DB::table('chart_of_account_mappings')->where('company_id', $companyId)->count(),
        'mapped' => DB::table('chart_of_account_mappings')->where('company_id', $companyId)->whereNotNull('value')->count(),
        'years' => DB::table('financial_years')->where('company_id', $companyId)->count(),
        'units' => DB::table('units')->where('company_id', $companyId)->count(),
        'brands' => DB::table('brands')->where('company_id', $companyId)->count(),
        'categories' => DB::table('categories')->where('company_id', $companyId)->count(),
        'item_types' => DB::table('item_types')->where('company_id', $companyId)->count(),
    ];
}

it('does nothing to a company that already has its chart, mappings, year and lists', function () {
    $scope = seedSellScope();
    $company = Company::query()->findOrFail($scope['company_id']);
    $branch = Branch::query()->findOrFail($scope['branch_id']);
    DB::table('financial_years')->insert(['company_id' => $company->id, 'name' => 'FY 2025-2026', 'start_date' => '2025-07-01', 'end_date' => '2026-06-30', 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('units')->insert(['company_id' => $company->id, 'name' => 'Kg', 'short_name' => 'kg', 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $role = Role::query()->create(['name' => 'companyadmin', 'company_id' => $company->id, 'is_active' => true]);

    $before = bootstrapFootprint((int) $company->id);
    $mappingValues = DB::table('chart_of_account_mappings')->where('company_id', $company->id)->orderBy('id')->pluck('value', 'key')->all();

    $result = app(CompanyBootstrap::class)->run($company, $branch, $role);

    expect($result['financial_year'])->toBeFalse()->and($result['chart'])->toBeFalse()
        ->and(bootstrapFootprint((int) $company->id)['accounts'])->toBe($before['accounts'])
        ->and(DB::table('chart_of_account_mappings')->where('company_id', $company->id)->orderBy('id')->pluck('value', 'key')->all())->toBe($mappingValues);

    // only the empty catalogue lists were filled; the unit that was there is the only unit
    expect(DB::table('units')->where('company_id', $company->id)->count())->toBe($before['units']);
});

it('is idempotent on a company it has just bootstrapped', function () {
    config(['landing.registration_enabled' => true]);
    $this->artisan('install:baseline-menus')->assertSuccessful();
    $this->post('/register', [
        'company_name' => 'Twice Traders', 'first_name' => 'Ali', 'username' => 'ali.twice', 'email' => 'ali@twice.test', 'password' => 'Str0ng!Pass', 'password_confirmation' => 'Str0ng!Pass',
    ])->assertRedirect();

    $user = User::query()->where('email', 'ali@twice.test')->firstOrFail();
    $companyId = (int) $user->company_id;
    $before = bootstrapFootprint($companyId);
    $permissions = DB::table('permissions')->count();

    $result = app(CompanyBootstrap::class)->run(Company::query()->findOrFail($companyId), Branch::query()->where('company_id', $companyId)->firstOrFail(), Role::query()->findOrFail($user->role_id));

    expect($result)->toBe(['financial_year' => false, 'chart' => false, 'defaults' => false, 'role_menus' => 0])
        ->and(bootstrapFootprint($companyId))->toBe($before)
        ->and(DB::table('permissions')->count())->toBe($permissions);
});

it('gives a company made on the Company page the shared admin role, the starter roles and the same set-up', function () {
    $this->artisan('install:baseline-menus')->assertSuccessful();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $this->postJson('/api/companies', [
        'name' => 'Manual Traders', 'email' => 'info@manual.test', 'password' => 'Str0ng!Pass', 'password_confirmation' => 'Str0ng!Pass', 'admin_name' => 'Hina Raza',
        'admin_username' => 'hina.manual', 'admin_email' => 'hina@manual.test', 'max_users' => 5, 'max_branches' => 1,
    ])->assertSuccessful();

    $admin = User::query()->where('email', 'hina@manual.test')->firstOrFail();
    $companyId = (int) $admin->company_id;
    $role = Role::query()->findOrFail($admin->role_id);
    $globalRole = Role::query()->whereNull('company_id')->where('name', 'companyadmin')->firstOrFail();

    // not an empty role of its own: the shared role the menu migrations keep current
    expect($role->id)->toBe($globalRole->id)->and(DB::table('permissions')->where('role_id', $role->id)->count())->toBeGreaterThan(300)
        ->and(DB::table('roles')->where('company_id', $companyId)->pluck('name')->all())->toContain('Accountant', 'Cashier')
        ->and(DB::table('chart_of_accounts')->where('company_id', $companyId)->count())->toBeGreaterThan(30)
        ->and(DB::table('chart_of_account_mappings')->where('company_id', $companyId)->whereNull('value')->pluck('key')->all())->toBe([])
        ->and(DB::table('financial_years')->where('company_id', $companyId)->where('status', 1)->count())->toBe(1)
        ->and(DB::table('item_types')->where('company_id', $companyId)->count())->toBe(1);

    Sanctum::actingAs($admin, ['*']);
    $this->getJson('/api/products')->assertSuccessful();
});

it('keeps the platform menus away from a company admin role of its own', function () {
    $this->artisan('install:baseline-menus')->assertSuccessful();
    $role = Role::query()->whereNull('company_id')->where('name', 'companyadmin')->firstOrFail();
    $paths = DB::table('permissions as p')->join('menus as m', 'm.id', '=', 'p.menu_id')->where('p.role_id', $role->id)->pluck('m.route_path')->all();

    expect($paths)->toContain('/purchase', '/journalentry/:id/approve')
        ->and(collect($paths)->filter(fn ($p) => str_starts_with((string) $p, '/tenants') || str_starts_with((string) $p, '/saasadmin') || str_starts_with((string) $p, '/subscriptionplans'))->all())->toBe([]);
});
