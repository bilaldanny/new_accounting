<?php

use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Payment;
use App\Models\Tax;
use App\Models\Transaction;
use App\Models\User;
use App\Services\WithholdingAccountSetup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function wasMapped(int $companyId, int $branchId, string $key): ?string
{
    $value = DB::table('chart_of_account_mappings')->where('company_id', $companyId)->where('branch_id', $branchId)->where('key', $key)->value('value');

    return $value === null || $value === '' ? null : (string) $value;
}

function wasNewBranch(): Branch
{
    $companyId = DB::table('companies')->insertGetId(['code' => 'WAS001', 'name' => 'Onboarding Co', 'address' => 'Somewhere', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);

    return Branch::createCompanyBranch($companyId);
}

test('a new branch gets its withholding receivable and payable accounts created and mapped', function () {
    $branch = wasNewBranch();
    $companyId = (int) $branch->company_id;
    $branchId = (int) $branch->id;

    $receivable = ChartOfAccount::query()->findOrFail((int) wasMapped($companyId, $branchId, 'withholdingreceivable'));
    $payable = ChartOfAccount::query()->findOrFail((int) wasMapped($companyId, $branchId, 'withholdingpayable'));

    expect($receivable->name)->toBe('Withholding Tax Receivable')
        ->and($receivable->acc_nature)->toBe('dr')
        ->and($receivable->parent->name)->toBe('Assets')
        ->and((int) $receivable->branch_id)->toBe($branchId)
        ->and($payable->name)->toBe('Withholding Tax Payable')
        ->and($payable->acc_nature)->toBe('cr')
        ->and($payable->parent->name)->toBe('Liabilities')
        ->and((int) $payable->company_id)->toBe($companyId);
});

test('running the setup again creates no duplicate accounts and keeps a mapping the user made', function () {
    $branch = wasNewBranch();
    $companyId = (int) $branch->company_id;
    $branchId = (int) $branch->id;
    $before = wasMapped($companyId, $branchId, 'withholdingreceivable');

    app(WithholdingAccountSetup::class)->ensureForBranch($companyId, $branchId);
    app(WithholdingAccountSetup::class)->ensureForBranch($companyId, $branchId);

    expect(ChartOfAccount::query()->where('branch_id', $branchId)->where('name', 'like', 'Withholding Tax%')->count())->toBe(2)
        ->and(wasMapped($companyId, $branchId, 'withholdingreceivable'))->toBe($before);

    $own = ChartOfAccount::query()->create(['parent_id' => ChartOfAccount::query()->where('branch_id', $branchId)->where('name', 'Assets')->value('id'), 'company_id' => $companyId, 'branch_id' => $branchId,
        'name' => 'My Own WHT', 'code' => '200-09999', 'acc_type' => 't', 'acc_nature' => 'dr', 'active' => true]);
    DB::table('chart_of_account_mappings')->where('branch_id', $branchId)->where('key', 'withholdingreceivable')->update(['value' => (string) $own->id]);

    app(WithholdingAccountSetup::class)->ensureForBranch($companyId, $branchId);

    expect(wasMapped($companyId, $branchId, 'withholdingreceivable'))->toBe((string) $own->id)
        ->and(ChartOfAccount::query()->where('branch_id', $branchId)->where('name', 'Withholding Tax Receivable')->count())->toBe(1);
});

test('an existing account with the standard name is reused instead of duplicated', function () {
    $branch = wasNewBranch();
    $companyId = (int) $branch->company_id;
    $branchId = (int) $branch->id;
    $existing = (int) wasMapped($companyId, $branchId, 'withholdingpayable');
    DB::table('chart_of_account_mappings')->where('branch_id', $branchId)->where('key', 'withholdingpayable')->update(['value' => null]);

    app(WithholdingAccountSetup::class)->ensureForBranch($companyId, $branchId);

    expect(wasMapped($companyId, $branchId, 'withholdingpayable'))->toBe((string) $existing)
        ->and(ChartOfAccount::query()->where('branch_id', $branchId)->where('name', 'Withholding Tax Payable')->count())->toBe(1);
});

test('a branch created through the API is ready for withholding tax with no manual mapping', function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
    $company = Company::query()->create(['code' => 'WAS002', 'name' => 'Api Co', 'address' => 'Road 1', 'is_active' => true]);
    $before = Branch::query()->count();

    $this->postJson('/api/branches', ['company_id' => $company->id, 'name' => 'Second Branch', 'email' => 'second@example.test', 'phone' => '0421234567', 'address' => 'Road 2', 'is_active' => true])->assertSuccessful();

    $branch = Branch::query()->where('company_id', $company->id)->latest('id')->firstOrFail();
    expect(Branch::query()->count())->toBe($before + 1)
        ->and(wasMapped((int) $company->id, (int) $branch->id, 'withholdingreceivable'))->not->toBeNull()
        ->and(wasMapped((int) $company->id, (int) $branch->id, 'withholdingpayable'))->not->toBeNull();
});

test('a sale with withholding tax saves on a branch that only got the automatic setup', function () {
    Sanctum::actingAs(User::query()->findOrFail(1));
    $scope = seedSellScope();
    // The branch has its chart roots and the unfilled mapping rows a fresh branch starts with, nothing else.
    parentChartOfAccount((int) $scope['company_id'], (int) $scope['branch_id']);
    foreach (['withholdingreceivable' => 'Withholding Tax Receivable', 'withholdingpayable' => 'Withholding Tax Payable'] as $key => $name) {
        DB::table('chart_of_account_mappings')->insert(['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'name' => $name, 'key' => $key, 'value' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    app(WithholdingAccountSetup::class)->ensureForBranch((int) $scope['company_id'], (int) $scope['branch_id']);
    $wht = Tax::query()->create(['company_id' => $scope['company_id'], 'name' => 'WHT 4', 'percentage' => 4, 'type' => 0, 'status' => true, 'kind' => 'withholding'])->id;

    $this->postJson('/api/sells', validSellPayload($scope, ['withholding_tax_id' => $wht]))->assertSuccessful();

    $sell = Transaction::query()->where('type', 'sell')->latest('id')->firstOrFail();
    $payment = Payment::query()->where('transaction_id', $sell->id)->firstOrFail();
    expect($payment->is_withholding)->toBeTrue()
        ->and((string) $payment->payment_account)->toBe(wasMapped((int) $scope['company_id'], (int) $scope['branch_id'], 'withholdingreceivable'));
});
