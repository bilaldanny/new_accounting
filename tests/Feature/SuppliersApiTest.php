<?php

use App\Models\ChartOfAccountMapping;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function seedSupplierCoaMapping(array $scope): int
{
    ChartOfAccountMapping::forBranch($scope['company_id'], $scope['branch_id']);

    $liabilityId = DB::table('chart_of_accounts')->insertGetId([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'code' => '300-00000',
        'name' => 'Trade Creditors',
        'acc_type' => 'c',
        'acc_nature' => 'cr',
        'bs' => 1,
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $supplierParentId = DB::table('chart_of_accounts')->insertGetId([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'parent_id' => $liabilityId,
        'code' => '300-00001',
        'name' => 'Suppliers',
        'acc_type' => 'c',
        'acc_nature' => 'cr',
        'bs' => 1,
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('chart_of_account_mappings')
        ->where('company_id', $scope['company_id'])
        ->where('branch_id', $scope['branch_id'])
        ->where('key', 'supplier')
        ->update(['value' => $supplierParentId]);

    DB::table('financial_years')->insert([
        'company_id' => $scope['company_id'],
        'name' => 'FY 2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $supplierParentId;
}

function seedSupplierScope(): array
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

function createSupplier(array $attributes = []): Contact
{
    if (isset($attributes['company_id'], $attributes['branch_id'])) {
        $scope = [
            'company_id' => $attributes['company_id'],
            'branch_id' => $attributes['branch_id'],
        ];
    } else {
        $scope = seedSupplierScope();
    }

    static $supplierCounter = 0;
    $supplierCounter++;

    return Contact::query()->create(array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'business_name' => 'Acme Supplies',
        'first_name' => 'John',
        'mobile' => '03001234567',
        'address' => 'Test address',
        'code' => 'SU-'.str_pad((string) $supplierCounter, 5, '0', STR_PAD_LEFT),
        'user_type' => 'supplier',
        'type' => 'local',
        'ntn_number' => '1234567',
        'active' => true,
    ], $attributes));
}

function validSupplierPayload(array $scope, array $overrides = []): array
{
    return array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'business_name' => 'New Supplier Co',
        'first_name' => 'Jane',
        'mobile' => '03007654321',
        'user_type' => 'supplier',
        'type' => 'local',
        'pay_type' => 'day',
        'active' => true,
        'address' => '123 Supplier Street',
        'ntn_number' => '1234567-8',
    ], $overrides);
}

test('suppliers api creates a supplier with required fields', function () {
    $scope = seedSupplierScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->postJson('/api/suppliers', validSupplierPayload($scope));

    $response->assertOk()
        ->assertJson(['message' => 'Successfully Saved']);

    $this->assertDatabaseHas('contacts', [
        'business_name' => 'New Supplier Co',
        'first_name' => 'Jane',
        'mobile' => '03007654321',
        'user_type' => 'supplier',
    ]);
});

test('suppliers api auto links supplier to chart of account when mapping exists', function () {
    $scope = seedSupplierScope();
    seedSupplierCoaMapping($scope);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->postJson('/api/suppliers', validSupplierPayload($scope, [
        'business_name' => 'Linked Supplier Co',
    ]));

    $response->assertOk()
        ->assertJson(['message' => 'Successfully Saved']);

    $supplier = Contact::query()->where('business_name', 'Linked Supplier Co')->first();

    expect($supplier)->not->toBeNull()
        ->and($supplier->link_account)->toBeTrue()
        ->and($supplier->supplier_gl_id)->toBe('301-00000')
        ->and($supplier->gl_id)->toBe('301-00000');

    $this->assertDatabaseHas('chart_of_accounts', [
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'code' => '301-00000',
        'name' => 'Linked Supplier Co',
        'acc_type' => 't',
    ]);

    $coaId = DB::table('chart_of_accounts')
        ->where('code', '301-00000')
        ->value('id');

    $this->assertDatabaseHas('account_balances', [
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'coa_id' => $coaId,
        'opening_balance' => 0,
        'acc_nature' => 'cr',
    ]);
});

test('suppliers api rejects missing required supplier fields', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->postJson('/api/suppliers', [
        'business_name' => 'Incomplete Supplier',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['company_id', 'branch_id', 'first_name', 'mobile']);
});

test('suppliers api rejects missing address and tax number', function () {
    $scope = seedSupplierScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->postJson('/api/suppliers', validSupplierPayload($scope, [
        'address' => '',
        'ntn_number' => '',
    ]));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['address', 'ntn_number']);
});

test('suppliers api lists only supplier and both user types', function () {
    $scope = seedSupplierScope();
    createSupplier(['business_name' => 'Listed Supplier', 'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']]);
    createSupplier([
        'business_name' => 'Both Contact',
        'user_type' => 'both',
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
    ]);
    Contact::query()->create([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'business_name' => 'Customer Only',
        'first_name' => 'Cust',
        'mobile' => '03001111111',
        'address' => 'Address',
        'code' => 'CU-00001',
        'user_type' => 'customer',
        'type' => 'local',
        'ntn_number' => '999',
        'active' => true,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/suppliers');

    $response->assertOk();

    $names = collect($response->json('data.data'))->pluck('business_name');

    expect($names)->toContain('Listed Supplier', 'Both Contact')
        ->not->toContain('Customer Only');
});

test('suppliers api includes chart of account linked status', function () {
    $scope = seedSupplierScope();
    createSupplier([
        'business_name' => 'Unlinked Supplier',
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'supplier_gl_id' => null,
    ]);
    createSupplier([
        'business_name' => 'Linked Supplier',
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'supplier_gl_id' => '200-00001',
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/suppliers');

    $response->assertOk();

    $records = collect($response->json('data.data'))->keyBy('business_name');

    expect($records['Unlinked Supplier']['account_linked'])->toBeFalse()
        ->and($records['Linked Supplier']['account_linked'])->toBeTrue()
        ->and($records['Linked Supplier']['supplier_gl_id'])->toBe('200-00001');
});

test('suppliers api updates an existing supplier', function () {
    $supplier = createSupplier();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->putJson("/api/suppliers/{$supplier->id}", [
        'company_id' => $supplier->company_id,
        'branch_id' => $supplier->branch_id,
        'business_name' => 'Updated Supplier Co',
        'first_name' => 'Updated',
        'mobile' => '03009998888',
        'address' => $supplier->address,
        'ntn_number' => $supplier->ntn_number,
        'type' => 'export',
        'active' => true,
    ]);

    $response->assertOk()
        ->assertJson(['message' => 'Successfully Saved']);

    $this->assertDatabaseHas('contacts', [
        'id' => $supplier->id,
        'business_name' => 'Updated Supplier Co',
        'first_name' => 'Updated',
        'type' => 'export',
    ]);
});

test('suppliers api soft deletes a supplier', function () {
    $supplier = createSupplier();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->deleteJson("/api/suppliers/{$supplier->id}");

    $response->assertOk()
        ->assertJson(['message' => 'Successfully Deleted']);

    $this->assertSoftDeleted('contacts', ['id' => $supplier->id]);
});

test('fetch suppliers endpoint returns active suppliers for dropdowns', function () {
    $scope = seedSupplierScope();
    createSupplier(['business_name' => 'Active Supplier', 'active' => true, 'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']]);
    createSupplier([
        'business_name' => 'Inactive Supplier',
        'active' => false,
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/fetchsuppliers?company_id='.$scope['company_id'].'&branch_id='.$scope['branch_id']);

    $response->assertOk();

    $names = collect($response->json())->pluck('text');

    expect($names)->toContain('Active Supplier')
        ->not->toContain('Inactive Supplier');
});

test('fetch contact detail returns supplier with financial stats', function () {
    $scope = seedSupplierScope();
    $supplier = createSupplier([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'business_name' => 'Detail Supplier',
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/fetchcontactdetail?contact_id='.$supplier->id);

    $response->assertOk()
        ->assertJsonPath('business_name', 'Detail Supplier')
        ->assertJsonPath('total_purchase', 0)
        ->assertJsonPath('paid_purchase', 0)
        ->assertJsonPath('due_purchase', 0);
});

test('fetch ledger returns ledger payload for supplier contact', function () {
    $supplier = createSupplier();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/fetchledger?contact_id='.$supplier->id.'&start_date=2026-01-01&end_date=2026-12-31');

    $response->assertOk()
        ->assertJsonStructure([
            'taccount',
            'openingbalance',
            'total_purchase',
            'total_paid_purchase',
            'total_sell',
            'total_paid_sell',
        ]);
});

test('suppliers api links an existing unlinked supplier to chart of account', function () {
    $scope = seedSupplierScope();
    seedSupplierCoaMapping($scope);

    $supplier = createSupplier([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'business_name' => 'Unlinked Supplier Co',
        'supplier_gl_id' => null,
        'link_account' => false,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->postJson('/api/suppliers/'.$supplier->id.'/link-coa');

    $response->assertOk()
        ->assertJson([
            'message' => 'Successfully Linked',
            'supplier_gl_id' => '301-00000',
            'link_account' => true,
        ]);

    $this->assertDatabaseHas('contacts', [
        'id' => $supplier->id,
        'supplier_gl_id' => '301-00000',
        'gl_id' => '301-00000',
        'link_account' => 1,
    ]);
});

test('suppliers api rejects linking supplier when already linked', function () {
    $scope = seedSupplierScope();
    seedSupplierCoaMapping($scope);

    $supplier = createSupplier([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'supplier_gl_id' => '301-00000',
        'gl_id' => '301-00000',
        'link_account' => true,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->postJson('/api/suppliers/'.$supplier->id.'/link-coa');

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['supplier_gl_id']);
});

test('suppliers api rejects linking supplier when mapping is missing', function () {
    $scope = seedSupplierScope();

    $supplier = createSupplier([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'business_name' => 'Still Unlinked Supplier',
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->postJson('/api/suppliers/'.$supplier->id.'/link-coa');

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['supplier_mapping']);
});
