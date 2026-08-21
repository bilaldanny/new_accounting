<?php

use App\Models\ChartOfAccountMapping;
use App\Models\Contact;
use App\Models\CustomerGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function seedCustomerCoaMapping(array $scope): int
{
    ChartOfAccountMapping::forBranch($scope['company_id'], $scope['branch_id']);

    $assetId = DB::table('chart_of_accounts')->insertGetId([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'code' => '100-00000',
        'name' => 'Trade Debtors',
        'acc_type' => 'c',
        'acc_nature' => 'dr',
        'bs' => 1,
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $customerParentId = DB::table('chart_of_accounts')->insertGetId([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'parent_id' => $assetId,
        'code' => '100-00001',
        'name' => 'Customers',
        'acc_type' => 'c',
        'acc_nature' => 'dr',
        'bs' => 1,
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('chart_of_account_mappings')
        ->where('company_id', $scope['company_id'])
        ->where('branch_id', $scope['branch_id'])
        ->where('key', 'customer')
        ->update(['value' => $customerParentId]);

    DB::table('financial_years')->insert([
        'company_id' => $scope['company_id'],
        'name' => 'FY 2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $customerParentId;
}

function seedCustomerScope(): array
{
    static $counter = 0;
    $counter++;

    $companyId = DB::table('companies')->insertGetId([
        'code' => 'CMP'.str_pad((string) $counter, 3, '0', STR_PAD_LEFT),
        'name' => 'Test Company '.$counter,
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $branchId = DB::table('branches')->insertGetId([
        'code' => 'BR'.str_pad((string) $counter, 3, '0', STR_PAD_LEFT),
        'company_id' => $companyId,
        'name' => 'Branch One '.$counter,
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'company_id' => $companyId,
        'branch_id' => $branchId,
    ];
}

function createCustomer(array $attributes = []): Contact
{
    if (isset($attributes['company_id'], $attributes['branch_id'])) {
        $scope = [
            'company_id' => $attributes['company_id'],
            'branch_id' => $attributes['branch_id'],
        ];
    } else {
        $scope = seedCustomerScope();
    }

    static $customerCounter = 0;
    $customerCounter++;

    return Contact::query()->create(array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'business_name' => 'Acme Retail',
        'first_name' => 'John',
        'mobile' => '03001234567',
        'address' => 'Test address',
        'code' => 'CU-'.str_pad((string) $customerCounter, 5, '0', STR_PAD_LEFT),
        'user_type' => 'customer',
        'type' => 'local',
        'ntn_number' => '1234567',
        'active' => true,
    ], $attributes));
}

function validCustomerPayload(array $scope, array $overrides = []): array
{
    return array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'business_name' => 'New Customer Co',
        'first_name' => 'Jane',
        'mobile' => '03007654321',
        'user_type' => 'customer',
        'type' => 'local',
        'pay_type' => 'day',
        'active' => true,
        'address' => '123 Customer Street',
        'ntn_number' => '1234567-8',
    ], $overrides);
}

test('customers api creates a customer with required fields', function () {
    $scope = seedCustomerScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->postJson('/api/customers', validCustomerPayload($scope, [
        'address' => '123 Main Street',
        'address_line_2' => 'Suite 4B',
        'zipcode' => '85001',
        'street_name' => 'Main Street',
        'building_number' => '12A',
        'secondary_number' => '22B',
        'landmark' => 'Near City Mall',
    ]));

    $response->assertOk()
        ->assertJson(['message' => 'Successfully Saved']);

    $this->assertDatabaseHas('contacts', [
        'business_name' => 'New Customer Co',
        'first_name' => 'Jane',
        'mobile' => '03007654321',
        'user_type' => 'customer',
        'address' => '123 Main Street',
        'address_line_2' => 'Suite 4B',
        'zipcode' => '85001',
        'street_name' => 'Main Street',
        'building_number' => '12A',
        'secondary_number' => '22B',
        'landmark' => 'Near City Mall',
    ]);
});

test('customers api auto links customer to chart of account when mapping exists', function () {
    $scope = seedCustomerScope();
    seedCustomerCoaMapping($scope);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->postJson('/api/customers', validCustomerPayload($scope, [
        'business_name' => 'Linked Customer Co',
        'opening_balance' => 1500,
    ]));

    $response->assertOk()
        ->assertJson(['message' => 'Successfully Saved']);

    $customer = Contact::query()->where('business_name', 'Linked Customer Co')->first();

    expect($customer)->not->toBeNull()
        ->and($customer->link_account)->toBeTrue()
        ->and($customer->customer_gl_id)->toBe('101-00000');

    $this->assertDatabaseHas('chart_of_accounts', [
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'code' => '101-00000',
        'name' => 'Linked Customer Co',
        'acc_type' => 't',
    ]);
});

test('customers api rejects missing required customer fields', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->postJson('/api/customers', [
        'business_name' => 'Incomplete Customer',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['company_id', 'branch_id', 'first_name', 'mobile']);
});

test('customers api lists only customer and both user types', function () {
    $scope = seedCustomerScope();
    createCustomer(['business_name' => 'Listed Customer', 'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']]);
    createCustomer([
        'business_name' => 'Both Contact',
        'user_type' => 'both',
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
    ]);
    Contact::query()->create([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'business_name' => 'Supplier Only',
        'first_name' => 'Supp',
        'mobile' => '03001111111',
        'address' => 'Address',
        'code' => 'SU-00001',
        'user_type' => 'supplier',
        'type' => 'local',
        'ntn_number' => '999',
        'active' => true,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/customers');

    $response->assertOk();

    $names = collect($response->json('data.data'))->pluck('business_name');

    expect($names)->toContain('Listed Customer', 'Both Contact')
        ->not->toContain('Supplier Only');
});

test('customers api includes chart of account linked status', function () {
    $scope = seedCustomerScope();
    createCustomer([
        'business_name' => 'Unlinked Customer',
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'customer_gl_id' => null,
    ]);
    createCustomer([
        'business_name' => 'Linked Customer',
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'customer_gl_id' => '101-00001',
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/customers');

    $response->assertOk();

    $records = collect($response->json('data.data'))->keyBy('business_name');

    expect($records['Unlinked Customer']['account_linked'])->toBeFalse()
        ->and($records['Linked Customer']['account_linked'])->toBeTrue()
        ->and($records['Linked Customer']['customer_gl_id'])->toBe('101-00001');
});

test('customers api updates an existing customer', function () {
    $customer = createCustomer();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->putJson("/api/customers/{$customer->id}", [
        'company_id' => $customer->company_id,
        'branch_id' => $customer->branch_id,
        'business_name' => 'Updated Customer Co',
        'first_name' => 'Updated',
        'mobile' => '03009998888',
        'address' => $customer->address,
        'ntn_number' => $customer->ntn_number,
        'type' => 'export',
        'active' => true,
    ]);

    $response->assertOk()
        ->assertJson(['message' => 'Successfully Saved']);

    $this->assertDatabaseHas('contacts', [
        'id' => $customer->id,
        'business_name' => 'Updated Customer Co',
        'first_name' => 'Updated',
        'type' => 'export',
    ]);
});

test('customers api soft deletes a customer', function () {
    $customer = createCustomer();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->deleteJson("/api/customers/{$customer->id}");

    $response->assertOk()
        ->assertJson(['message' => 'Successfully Deleted']);

    $this->assertSoftDeleted('contacts', ['id' => $customer->id]);
});

test('customers show endpoint returns customer with financial stats', function () {
    $scope = seedCustomerScope();
    $customer = createCustomer([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'business_name' => 'Detail Customer',
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/customers/'.$customer->id);

    $response->assertOk()
        ->assertJsonPath('business_name', 'Detail Customer')
        ->assertJsonPath('total_sell', 0)
        ->assertJsonPath('paid_sell', 0)
        ->assertJsonPath('due_sell', 0)
        ->assertJsonPath('opening_balance', 0);
});

test('customers api includes opening balance in list for linked customer', function () {
    $scope = seedCustomerScope();
    $customerParentId = seedCustomerCoaMapping($scope);

    $coaId = DB::table('chart_of_accounts')->insertGetId([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'parent_id' => $customerParentId,
        'code' => '101-00000',
        'name' => 'Listed Linked Customer',
        'acc_type' => 't',
        'acc_nature' => 'dr',
        'bs' => 1,
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    createCustomer([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'business_name' => 'Listed Linked Customer',
        'customer_gl_id' => '101-00000',
        'link_account' => true,
    ]);

    $financialYearId = DB::table('financial_years')
        ->where('company_id', $scope['company_id'])
        ->value('id');

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

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/customers');

    $response->assertOk();

    $records = collect($response->json('data.data'))->keyBy('business_name');

    expect($records['Listed Linked Customer']['op_bal'])->toBe(2500);
});

test('customers show endpoint returns opening balance for linked customer', function () {
    $scope = seedCustomerScope();
    $customerParentId = seedCustomerCoaMapping($scope);

    $coaId = DB::table('chart_of_accounts')->insertGetId([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'parent_id' => $customerParentId,
        'code' => '101-00000',
        'name' => 'Linked Detail Customer',
        'acc_type' => 't',
        'acc_nature' => 'dr',
        'bs' => 1,
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $customer = createCustomer([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'business_name' => 'Linked Detail Customer',
        'customer_gl_id' => '101-00000',
        'link_account' => true,
    ]);

    $financialYearId = DB::table('financial_years')
        ->where('company_id', $scope['company_id'])
        ->value('id');

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

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/customers/'.$customer->id);

    $response->assertOk()
        ->assertJsonPath('opening_balance', 2500);
});

test('customers api links an existing unlinked customer to chart of account', function () {
    $scope = seedCustomerScope();
    seedCustomerCoaMapping($scope);

    $customer = createCustomer([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'business_name' => 'Unlinked Customer Co',
        'customer_gl_id' => null,
        'link_account' => false,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->postJson('/api/customers/'.$customer->id.'/link-coa');

    $response->assertOk()
        ->assertJson([
            'message' => 'Successfully Linked',
            'customer_gl_id' => '101-00000',
            'link_account' => true,
        ]);

    $this->assertDatabaseHas('contacts', [
        'id' => $customer->id,
        'customer_gl_id' => '101-00000',
        'link_account' => 1,
    ]);
});

test('customers generate-code api returns the next contact code', function () {
    $scope = seedCustomerScope();

    createCustomer([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'code' => 'CU-00001',
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/customers/generate-code?company_id='.$scope['company_id'].'&branch_id='.$scope['branch_id']);

    $response->assertOk()
        ->assertJsonStructure(['code']);

    expect($response->json('code'))->toBe('CU-00002');
});

test('customers api stores customer_group_id when provided', function () {
    $scope = seedCustomerScope();
    $customerGroup = CustomerGroup::query()->create([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'name' => 'Premium Retail',
        'price_calculation_type' => 'percentage',
        'calculation_percentage' => 12.5,
        'active' => true,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/customers', validCustomerPayload($scope, [
        'customer_group_id' => $customerGroup->id,
    ]))->assertOk();

    $this->assertDatabaseHas('contacts', [
        'business_name' => 'New Customer Co',
        'customer_group_id' => $customerGroup->id,
    ]);
});
