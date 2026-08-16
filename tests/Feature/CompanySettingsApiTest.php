<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('guests cannot access company settings api', function () {
    $this->getJson('/api/company-settings/1')
        ->assertUnauthorized();
});

test('company setting web route requires authentication', function () {
    $this->get(route('company.setting'))
        ->assertRedirect();
});

test('setting menu route renders company settings page', function () {
    $superadmin = User::query()->findOrFail(1);

    $this->actingAs($superadmin)
        ->get(route('setting'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('company/setting'));
});

test('new company settings include default prefixes', function () {
    $company = Company::query()->create([
        'code' => 'CO-00010',
        'name' => 'Prefix Defaults Corp',
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 2,
    ]);

    CompanySetting::createCompanySettings($company->id, $company->name);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->getJson("/api/company-settings/{$company->id}")
        ->assertSuccessful()
        ->assertJsonPath('companySetting.purchase_order', 'PO')
        ->assertJsonPath('companySetting.purchase_return', 'PR')
        ->assertJsonPath('companySetting.stock_transfer', 'ST')
        ->assertJsonPath('companySetting.stock_adjustment', 'SA')
        ->assertJsonPath('companySetting.sell_return', 'SR')
        ->assertJsonPath('companySetting.invoice', 'INV')
        ->assertJsonPath('companySetting.expenses', 'EXP')
        ->assertJsonPath('companySetting.supplier', 'SU')
        ->assertJsonPath('companySetting.customer', 'CU')
        ->assertJsonPath('companySetting.bank', 'BA')
        ->assertJsonPath('companySetting.product', 'PRO')
        ->assertJsonPath('companySetting.purchase_payment', 'PP')
        ->assertJsonPath('companySetting.sell_payment', 'SP')
        ->assertJsonPath('companySetting.expense_payment', 'EP')
        ->assertJsonPath('companySetting.business_location', 'BL')
        ->assertJsonPath('companySetting.subscription_no', 'SN')
        ->assertJsonPath('companySetting.draft', 'DRA')
        ->assertJsonPath('companySetting.opening_stock', 'OS')
        ->assertJsonPath('companySetting.grn', 'GRN')
        ->assertJsonPath('companySetting.gin', 'GIN');
});

test('superadmin can view and update company settings', function () {
    $company = Company::query()->create([
        'code' => 'CO-00001',
        'name' => 'Acme Corp',
        'email' => 'old@example.com',
        'phone' => '1111111111',
        'address' => 'Old address',
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 2,
    ]);

    CompanySetting::query()->create([
        'company_id' => $company->id,
        'business_name' => 'Acme Corp',
        'purchase_order' => 'PO-',
    ]);

    $countryId = DB::table('countries')->insertGetId([
        'name' => 'Pakistan',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $stateId = DB::table('states')->insertGetId([
        'name' => 'Punjab',
        'country_id' => $countryId,
        'country_code' => 'PK',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $cityId = DB::table('cities')->insertGetId([
        'name' => 'Lahore',
        'state_id' => $stateId,
        'state_code' => 'PB',
        'country_id' => $countryId,
        'country_code' => 'PK',
        'latitude' => 31.5497,
        'longitude' => 74.3436,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->getJson("/api/company-settings/{$company->id}")
        ->assertSuccessful()
        ->assertJsonPath('companySetting.business_name', 'Acme Corp')
        ->assertJsonPath('companySetting.email', 'old@example.com')
        ->assertJsonPath('companySetting.purchase_order', 'PO-');

    $this->putJson("/api/company-settings/{$company->id}", [
        'business_name' => 'Acme Updated',
        'email' => 'new@example.com',
        'phone' => '2222222222',
        'cell' => '3333333333',
        'whatsapp_no' => '4444444444',
        'fb_link' => 'https://facebook.com/acme',
        'address' => 'New address',
        'country_id' => $countryId,
        'state_id' => $stateId,
        'city_id' => $cityId,
        'search_type' => 'selectbox',
        'accounting_method' => 'fifo',
        'default_pos_unit' => '1',
        'update_packing_qty' => true,
        'purchase_column' => [
            ['name' => 'Packing Quantity', 'show' => false],
        ],
        'purchase_order' => 'PO-NEW-',
        'purchase_approval' => true,
        'auto_grn' => false,
    ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'Successfully Saved')
        ->assertJsonPath('companySetting.business_name', 'Acme Updated')
        ->assertJsonPath('companySetting.email', 'new@example.com')
        ->assertJsonPath('companySetting.search_type', 'selectbox')
        ->assertJsonPath('companySetting.accounting_method', 'fifo')
        ->assertJsonPath('companySetting.update_packing_qty', true)
        ->assertJsonPath('companySetting.purchase_order', 'PO-NEW-')
        ->assertJsonPath('companySetting.purchase_approval', true);

    $this->assertDatabaseHas('company_settings', [
        'company_id' => $company->id,
        'business_name' => 'Acme Updated',
        'search_type' => 'selectbox',
        'accounting_method' => 'fifo',
        'update_packing_qty' => 1,
        'purchase_order' => 'PO-NEW-',
        'purchase_approval' => 1,
    ]);

    $this->assertDatabaseHas('companies', [
        'id' => $company->id,
        'email' => 'new@example.com',
        'phone' => '2222222222',
        'cell' => '3333333333',
        'whatsapp_no' => '4444444444',
        'fb_link' => 'https://facebook.com/acme',
        'address' => 'New address',
        'country_id' => $countryId,
        'state_id' => $stateId,
        'city_id' => $cityId,
    ]);
});

test('non superadmin users cannot access another company settings', function () {
    $company = Company::query()->create([
        'code' => 'CO-00002',
        'name' => 'Other Corp',
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 2,
    ]);

    $ownCompany = Company::query()->create([
        'code' => 'CO-00003',
        'name' => 'Own Corp',
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 2,
    ]);

    $branchId = Branch::query()->create([
        'code' => 'BR-00001',
        'company_id' => $ownCompany->id,
        'name' => 'Main Branch',
        'is_active' => true,
    ])->id;

    $roleId = Role::query()->create([
        'company_id' => $ownCompany->id,
        'branch_id' => $branchId,
        'name' => 'branchuser',
        'is_active' => true,
    ])->id;

    $user = User::query()->create([
        'first_name' => 'Branch',
        'last_name' => 'User',
        'username' => 'branchuser',
        'email' => 'branch@example.com',
        'password' => bcrypt('password'),
        'pass' => 'password',
        'is_active' => true,
        'company_id' => $ownCompany->id,
        'branch_id' => $branchId,
        'role_id' => $roleId,
    ]);

    Sanctum::actingAs($user);

    $this->getJson("/api/company-settings/{$company->id}")
        ->assertForbidden();
});

test('company user can access their own company settings', function () {
    $company = Company::query()->create([
        'code' => 'CO-00004',
        'name' => 'Own Corp',
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 2,
    ]);

    $branchId = Branch::query()->create([
        'code' => 'BR-00002',
        'company_id' => $company->id,
        'name' => 'Main Branch',
        'is_active' => true,
    ])->id;

    $roleId = Role::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branchId,
        'name' => 'companyadmin',
        'is_active' => true,
    ])->id;

    $user = User::query()->create([
        'first_name' => 'Company',
        'last_name' => 'Admin',
        'username' => 'companyadmin2',
        'email' => 'companyadmin2@example.com',
        'password' => bcrypt('password'),
        'pass' => 'password',
        'is_active' => true,
        'company_id' => $company->id,
        'branch_id' => $branchId,
        'role_id' => $roleId,
    ]);

    Sanctum::actingAs($user);

    $this->getJson("/api/company-settings/{$company->id}")
        ->assertSuccessful()
        ->assertJsonPath('companySetting.company_id', $company->id);
});
