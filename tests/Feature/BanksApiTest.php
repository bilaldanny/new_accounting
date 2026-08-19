<?php

use App\Models\Bank;
use App\Models\ChartOfAccountMapping;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function seedBankCoaMapping(array $scope): int
{
    ChartOfAccountMapping::forBranch($scope['company_id'], $scope['branch_id']);

    $assetId = DB::table('chart_of_accounts')->insertGetId([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'code' => '100-00000',
        'name' => 'Assets',
        'acc_type' => 'c',
        'acc_nature' => 'dr',
        'bs' => 1,
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $bankParentId = DB::table('chart_of_accounts')->insertGetId([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'parent_id' => $assetId,
        'code' => '100-00001',
        'name' => 'Banks',
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
        ->where('key', 'bank')
        ->update(['value' => $bankParentId]);

    DB::table('financial_years')->insert([
        'company_id' => $scope['company_id'],
        'name' => 'FY 2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $bankParentId;
}

function seedBankScope(): array
{
    static $counter = 0;
    $counter++;

    $companyId = DB::table('companies')->insertGetId([
        'code' => 'BNK'.str_pad((string) $counter, 3, '0', STR_PAD_LEFT),
        'name' => 'Bank Test Company '.$counter,
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $branchId = DB::table('branches')->insertGetId([
        'code' => 'BB'.str_pad((string) $counter, 3, '0', STR_PAD_LEFT),
        'company_id' => $companyId,
        'name' => 'Bank Branch '.$counter,
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'company_id' => $companyId,
        'branch_id' => $branchId,
    ];
}

function createBank(array $attributes = []): Bank
{
    if (isset($attributes['company_id'], $attributes['branch_id'])) {
        $scope = [
            'company_id' => $attributes['company_id'],
            'branch_id' => $attributes['branch_id'],
        ];
    } else {
        $scope = seedBankScope();
    }

    static $bankCounter = 0;
    $bankCounter++;

    return Bank::query()->create(array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'bank_name' => 'Test Bank',
        'first_name' => 'John',
        'mobile' => '03001234567',
        'code' => 'BK-'.str_pad((string) $bankCounter, 5, '0', STR_PAD_LEFT),
        'type' => 'local',
        'active' => true,
    ], $attributes));
}

function validBankPayload(array $scope, array $overrides = []): array
{
    return array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'bank_name' => 'Allied Bank',
        'first_name' => 'Jane',
        'mobile' => '03007654321',
        'type' => 'local',
        'active' => true,
    ], $overrides);
}

test('banks api creates a bank with required fields', function () {
    $scope = seedBankScope();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->postJson('/api/banks', validBankPayload($scope, [
        'address' => '123 Bank Street',
        'landmark' => 'Near Mall',
    ]));

    $response->assertOk()
        ->assertJson(['message' => 'Successfully Saved']);

    $this->assertDatabaseHas('banks', [
        'bank_name' => 'Allied Bank',
        'first_name' => 'Jane',
        'mobile' => '03007654321',
        'address' => '123 Bank Street',
        'landmark' => 'Near Mall',
    ]);
});

test('banks api auto links bank to chart of account when mapping exists', function () {
    $scope = seedBankScope();
    seedBankCoaMapping($scope);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->postJson('/api/banks', validBankPayload($scope, [
        'bank_name' => 'Linked Bank',
        'opening_balance' => 2500,
    ]));

    $response->assertOk()
        ->assertJson(['message' => 'Successfully Saved']);

    $bank = Bank::query()->where('bank_name', 'Linked Bank')->first();

    expect($bank)->not->toBeNull()
        ->and($bank->link_account)->toBeTrue()
        ->and($bank->gl_id)->toBe('101-00000');

    $this->assertDatabaseHas('chart_of_accounts', [
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'code' => '101-00000',
        'name' => 'Linked Bank',
        'acc_type' => 't',
    ]);
});

test('banks api rejects missing required bank fields', function () {
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->postJson('/api/banks', [
        'bank_name' => 'Incomplete Bank',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['company_id', 'branch_id', 'first_name', 'mobile']);
});

test('banks api lists banks', function () {
    $scope = seedBankScope();
    createBank(['bank_name' => 'Listed Bank', 'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/banks');

    $response->assertOk();

    $names = collect($response->json('data.data'))->pluck('bank_name');

    expect($names)->toContain('Listed Bank');
});

test('banks api updates an existing bank', function () {
    $bank = createBank();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->putJson("/api/banks/{$bank->id}", [
        'company_id' => $bank->company_id,
        'branch_id' => $bank->branch_id,
        'bank_name' => 'Updated Bank',
        'first_name' => 'Updated',
        'mobile' => '03009998888',
        'type' => 'export',
        'active' => true,
    ]);

    $response->assertOk()
        ->assertJson(['message' => 'Successfully Saved']);

    $this->assertDatabaseHas('banks', [
        'id' => $bank->id,
        'bank_name' => 'Updated Bank',
        'first_name' => 'Updated',
        'type' => 'export',
    ]);
});

test('banks api soft deletes a bank', function () {
    $bank = createBank();
    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->deleteJson("/api/banks/{$bank->id}");

    $response->assertOk()
        ->assertJson(['message' => 'Successfully Deleted']);

    $this->assertSoftDeleted('banks', ['id' => $bank->id]);
});

test('fetch banks endpoint returns active banks for dropdowns', function () {
    $scope = seedBankScope();
    createBank(['bank_name' => 'Active Bank', 'active' => true, 'company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id']]);
    createBank([
        'bank_name' => 'Inactive Bank',
        'active' => false,
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/fetchbanks?company_id='.$scope['company_id'].'&branch_id='.$scope['branch_id']);

    $response->assertOk();

    $names = collect($response->json())->pluck('text');

    expect($names)->toContain('Active Bank')
        ->not->toContain('Inactive Bank');
});

test('banks generate-code api returns the next bank code', function () {
    $scope = seedBankScope();

    createBank([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'code' => 'BK-00001',
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->getJson('/api/banks/generate-code?company_id='.$scope['company_id'].'&branch_id='.$scope['branch_id']);

    $response->assertOk()
        ->assertJsonStructure(['code']);

    expect($response->json('code'))->toBe('BK-00002');
});

test('banks api links an existing unlinked bank to chart of account', function () {
    $scope = seedBankScope();
    seedBankCoaMapping($scope);

    $bank = createBank([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'bank_name' => 'Unlinked Bank',
        'gl_id' => null,
        'link_account' => false,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $response = $this->postJson('/api/banks/'.$bank->id.'/link-coa');

    $response->assertOk()
        ->assertJson([
            'message' => 'Successfully Linked',
            'gl_id' => '101-00000',
            'link_account' => true,
        ]);
});
