<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('guests cannot access the branches api', function () {
    $this->getJson('/api/branches')
        ->assertUnauthorized();
});

test('branch web routes require authentication', function () {
    $this->get(route('branch'))
        ->assertRedirect();

    $this->get(route('branch.trash'))
        ->assertRedirect();
});

test('branch store creates chart of accounts and mappings', function () {
    $company = Company::query()->create([
        'code' => 'CO-00001',
        'name' => 'Acme Corp',
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 5,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/branches', [
        'company_id' => $company->id,
        'name' => 'Downtown Branch',
        'email' => 'branch@acme.test',
        'phone' => '03001234567',
        'address' => 'Main Street',
    ])->assertSuccessful()
        ->assertJson(['message' => 'Successfully Saved']);

    $branchId = DB::table('branches')->where('name', 'Downtown Branch')->value('id');
    $branchCode = DB::table('branches')->where('id', $branchId)->value('code');

    expect($branchCode)->toBe('BR-00001')
        ->and(DB::table('chart_of_accounts')->where('branch_id', $branchId)->count())->toBe(6)
        ->and(DB::table('chart_of_account_mappings')->where('branch_id', $branchId)->count())->toBe(17);
});

test('branches generate-code api returns the next sequential code', function () {
    Branch::query()->create([
        'code' => 'BR-00001',
        'name' => 'First Branch',
        'email' => 'first@branch.test',
        'is_active' => true,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->getJson('/api/branches/generate-code')
        ->assertSuccessful()
        ->assertJson(['code' => 'BR-00002']);
});

test('only one branch can be default per company', function () {
    $company = Company::query()->create([
        'code' => 'CO-00002',
        'name' => 'Beta Corp',
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 5,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/branches', [
        'company_id' => $company->id,
        'name' => 'Main Branch',
        'email' => 'main@beta.test',
        'is_default' => false,
    ])->assertSuccessful();

    $mainBranchId = Branch::query()->where('name', 'Main Branch')->value('id');

    expect(Branch::query()->find($mainBranchId)?->is_default)->toBeTrue();

    $this->postJson('/api/branches', [
        'company_id' => $company->id,
        'name' => 'Second Branch',
        'email' => 'second@beta.test',
        'is_default' => true,
    ])->assertSuccessful();

    $secondBranchId = Branch::query()->where('name', 'Second Branch')->value('id');

    expect(Branch::query()->find($secondBranchId)?->is_default)->toBeTrue()
        ->and(Branch::query()->find($mainBranchId)?->is_default)->toBeFalse()
        ->and(
            Branch::query()
                ->where('company_id', $company->id)
                ->where('is_default', true)
                ->count()
        )->toBe(1);

    $this->putJson("/api/branches/{$mainBranchId}", [
        'company_id' => $company->id,
        'name' => 'Main Branch',
        'email' => 'main@beta.test',
        'is_default' => true,
    ])->assertSuccessful();

    expect(Branch::query()->find($mainBranchId)?->is_default)->toBeTrue()
        ->and(Branch::query()->find($secondBranchId)?->is_default)->toBeFalse()
        ->and(
            Branch::query()
                ->where('company_id', $company->id)
                ->where('is_default', true)
                ->count()
        )->toBe(1);
});

test('default branch cannot be unset without assigning another default', function () {
    $company = Company::query()->create([
        'code' => 'CO-00003',
        'name' => 'Gamma Corp',
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 5,
    ]);

    $branch = Branch::query()->create([
        'code' => 'BR-00010',
        'company_id' => $company->id,
        'name' => 'Only Branch',
        'email' => 'only@gamma.test',
        'is_active' => true,
        'is_default' => true,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->putJson("/api/branches/{$branch->id}", [
        'company_id' => $company->id,
        'name' => 'Only Branch',
        'email' => 'only@gamma.test',
        'is_default' => false,
    ])->assertStatus(422);

    expect(Branch::query()->find($branch->id)?->is_default)->toBeTrue();
});

test('unchecked is_default and is_active booleans are saved as false', function () {
    $company = Company::query()->create([
        'code' => 'CO-00004',
        'name' => 'Delta Corp',
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 5,
    ]);

    Branch::query()->create([
        'code' => 'BR-00020',
        'company_id' => $company->id,
        'name' => 'Existing Default',
        'email' => 'existing@delta.test',
        'is_active' => true,
        'is_default' => true,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->postJson('/api/branches', [
        'company_id' => $company->id,
        'name' => 'Non Default Branch',
        'email' => 'nondefault@delta.test',
        'is_active' => false,
        'is_default' => false,
    ])->assertSuccessful();

    $branch = Branch::query()->where('name', 'Non Default Branch')->first();

    expect($branch)->not->toBeNull()
        ->and($branch->is_default)->toBeFalse()
        ->and($branch->is_active)->toBeFalse();
});

test('branches index returns can_add_branch false when company limit is reached', function () {
    $company = Company::query()->create([
        'code' => 'CO-00005',
        'name' => 'Limit Corp',
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 1,
    ]);

    Branch::query()->create([
        'code' => 'BR-00030',
        'company_id' => $company->id,
        'name' => 'Only Branch',
        'email' => 'only@limit.test',
        'is_active' => true,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->getJson('/api/branches?company_id='.$company->id)
        ->assertSuccessful()
        ->assertJson([
            'can_add_branch' => false,
        ]);
});

test('branches index returns can_add_branch true when company is under limit', function () {
    $company = Company::query()->create([
        'code' => 'CO-00006',
        'name' => 'Room Corp',
        'is_active' => true,
        'max_users' => 10,
        'max_branches' => 3,
    ]);

    Branch::query()->create([
        'code' => 'BR-00031',
        'company_id' => $company->id,
        'name' => 'First Branch',
        'email' => 'first@room.test',
        'is_active' => true,
    ]);

    $superadmin = User::query()->findOrFail(1);
    Sanctum::actingAs($superadmin);

    $this->getJson('/api/branches?company_id='.$company->id)
        ->assertSuccessful()
        ->assertJson([
            'can_add_branch' => true,
        ]);
});
