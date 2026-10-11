<?php

use App\Models\ChartOfAccountMapping;
use App\Models\Contact;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * @return array{company_id: int, branch_id: int}
 */
function cimpScope(string $suffix, bool $withCustomerAccount = false): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'CIMP'.$suffix, 'name' => 'Import Co '.$suffix, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $branchId = DB::table('branches')->insertGetId([
        'code' => 'CIB'.$suffix, 'company_id' => $companyId, 'name' => 'Branch '.$suffix, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $scope = ['company_id' => $companyId, 'branch_id' => $branchId];

    DB::table('financial_years')->insert([
        'company_id' => $companyId, 'name' => 'FY 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'status' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    if ($withCustomerAccount) {
        ChartOfAccountMapping::forBranch($companyId, $branchId);

        $account = fn (string $code, string $name, ?int $parentId): int => DB::table('chart_of_accounts')->insertGetId([
            'company_id' => $companyId, 'branch_id' => $branchId, 'parent_id' => $parentId, 'code' => $code, 'name' => $name,
            'acc_type' => 'c', 'acc_nature' => 'dr', 'bs' => 1, 'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $assets = $account('200-00000', 'Assets', null);
        $current = $account('210-00000', 'Current Assets', $assets);
        $debtors = $account('213-00000', 'Trade Debtors', $current);

        DB::table('chart_of_account_mappings')->where('company_id', $companyId)->where('branch_id', $branchId)
            ->where('key', 'customer')->update(['value' => $debtors]);
    }

    return $scope;
}

/**
 * @param  array{company_id: int, branch_id: int}  $scope
 * @param  list<string>  $paths
 */
function cimpStaff(array $scope, array $paths): User
{
    $role = Role::query()->create(['name' => 'importer_'.uniqid(), 'company_id' => $scope['company_id'], 'is_active' => true]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path);
    }

    return createStaffUserForRole($role, ['company_id' => $scope['company_id'], 'branch_id' => $scope['branch_id'], 'email_verified_at' => now()]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cimpRow(array $overrides = []): array
{
    return array_merge(['business_name' => 'Imported Traders', 'first_name' => 'Imran', 'mobile' => '03001112222', 'email' => 'imran@example.com'], $overrides);
}

test('a valid customer file creates every row through the same path as the add form', function () {
    $scope = cimpScope('A');
    Sanctum::actingAs(cimpStaff($scope, ['/customer/import']));

    $response = $this->postJson('/api/customers/import', ['rows' => [
        cimpRow(),
        cimpRow(['business_name' => 'Second Stores', 'mobile' => '03003334444', 'email' => '', 'credit_limit' => 5000, 'pay_term' => 15]),
    ]])->assertOk();

    expect($response->json('summary.created'))->toBe(2)
        ->and($response->json('message'))->toBe('Successfully imported 2 new and updated 0 customer records.');

    $customers = Contact::query()->where('company_id', $scope['company_id'])->orderBy('id')->get();

    expect($customers)->toHaveCount(2)
        ->and($customers[0]->user_type)->toBe('customer')
        ->and($customers[0]->branch_id)->toBe($scope['branch_id'])
        ->and($customers[0]->code)->not->toBe('')
        ->and($customers[1]->business_name)->toBe('Second Stores')
        ->and((float) $customers[1]->credit_limit)->toBe(5000.0);
});

test('a check run reports each row and saves nothing', function () {
    $scope = cimpScope('B');
    Sanctum::actingAs(cimpStaff($scope, ['/supplier/import']));

    $response = $this->postJson('/api/suppliers/import', ['dry_run' => true, 'rows' => [cimpRow(), cimpRow(['business_name' => '', 'mobile' => '0300'])]])->assertOk();

    expect($response->json('dry_run'))->toBeTrue()
        ->and($response->json('rows.0.status'))->toBe('created')
        ->and($response->json('rows.1.status'))->toBe('error')
        ->and($response->json('summary.failed'))->toBe(1)
        ->and(Contact::query()->count())->toBe(0);
});

test('one bad row rejects the whole import and nothing is saved, with the row named', function () {
    $scope = cimpScope('C');
    Sanctum::actingAs(cimpStaff($scope, ['/supplier/import']));

    $response = $this->postJson('/api/suppliers/import', ['rows' => [
        cimpRow(),
        cimpRow(['business_name' => 'Bad Email Co', 'mobile' => '0300', 'email' => 'not-an-email', 'pay_type' => 'fortnight']),
    ]])->assertUnprocessable();

    expect($response->json('rows.1.row'))->toBe(2)
        ->and(implode(' ', $response->json('errors')))->toContain('Row 2')
        ->and(Contact::query()->count())->toBe(0);
});

test('a row matching an existing email, mobile or name is skipped as a duplicate, and so is a repeat inside the file', function () {
    $scope = cimpScope('D');
    Contact::query()->create(array_merge($scope, [
        'business_name' => 'Already Here', 'first_name' => 'A', 'mobile' => '03009998888', 'email' => 'here@example.com',
        'address' => 'x', 'code' => 'CU-9', 'user_type' => 'customer', 'type' => 'local', 'ntn_number' => '1', 'active' => true,
    ]));
    Sanctum::actingAs(cimpStaff($scope, ['/customer/import']));

    $response = $this->postJson('/api/customers/import', ['rows' => [
        cimpRow(['business_name' => 'Different Name', 'mobile' => '03111111111', 'email' => 'HERE@example.com']),
        cimpRow(['business_name' => 'Fresh One', 'mobile' => '03222222222', 'email' => 'fresh@example.com']),
        cimpRow(['business_name' => 'Fresh One Again', 'mobile' => '03222222222', 'email' => 'other@example.com']),
    ]])->assertOk();

    expect(collect($response->json('rows'))->pluck('status')->all())->toBe(['duplicate', 'created', 'duplicate'])
        ->and($response->json('rows.0.duplicate_of'))->toContain('Already Here')
        ->and($response->json('summary.duplicates'))->toBe(2)
        ->and(Contact::query()->where('company_id', $scope['company_id'])->count())->toBe(2);
});

test('rows are always filed under the caller company, whatever company the file or the request names', function () {
    $mine = cimpScope('E');
    $other = cimpScope('F');
    Contact::query()->create(array_merge($other, [
        'business_name' => 'Other Tenant Co', 'first_name' => 'O', 'mobile' => '03005556666', 'email' => 'other-tenant@example.com',
        'address' => 'x', 'code' => 'CU-1', 'user_type' => 'customer', 'type' => 'local', 'ntn_number' => '1', 'active' => true,
    ]));
    Sanctum::actingAs(cimpStaff($mine, ['/customer/import']));

    $response = $this->postJson('/api/customers/import', [
        'company_id' => $other['company_id'],
        'branch_id' => $other['branch_id'],
        'rows' => [cimpRow(['company_id' => $other['company_id'], 'business_name' => 'Other Tenant Co', 'mobile' => '03005556666', 'email' => 'other-tenant@example.com'])],
    ])->assertOk();

    // the other company's contact is invisible to this company, so it is not a duplicate; the row lands in ours
    expect($response->json('rows.0.status'))->toBe('created')
        ->and(Contact::query()->where('company_id', $mine['company_id'])->count())->toBe(1)
        ->and(Contact::query()->where('company_id', $other['company_id'])->count())->toBe(1);
});

test('a user without the import permission is refused and a guest is unauthenticated', function () {
    $scope = cimpScope('G');
    $this->postJson('/api/customers/import', ['rows' => [cimpRow()]])->assertUnauthorized();

    Sanctum::actingAs(cimpStaff($scope, ['/customer/add']));
    $this->postJson('/api/customers/import', ['rows' => [cimpRow()]])->assertForbidden();
});

test('an opening balance is posted by the account link of the manual create', function () {
    $scope = cimpScope('H', withCustomerAccount: true);
    Sanctum::actingAs(cimpStaff($scope, ['/customer/import']));

    $this->postJson('/api/customers/import', ['rows' => [cimpRow(['opening_balance' => 2500])]])->assertOk();

    $customer = Contact::query()->where('company_id', $scope['company_id'])->firstOrFail();

    expect($customer->customer_gl_id)->toBe('213-00001')
        ->and((bool) $customer->link_account)->toBeTrue();

    $coaId = DB::table('chart_of_accounts')->where('company_id', $scope['company_id'])->where('code', '213-00001')->value('id');

    expect((float) DB::table('account_balances')->where('coa_id', $coaId)->value('opening_balance'))->toBe(2500.0)
        ->and(DB::table('account_balances')->where('coa_id', $coaId)->value('acc_nature'))->toBe('dr');
});

test('an opening balance with no account mapping is a row error rather than a silently dropped balance', function () {
    $scope = cimpScope('I');
    Sanctum::actingAs(cimpStaff($scope, ['/customer/import']));

    $response = $this->postJson('/api/customers/import', ['rows' => [cimpRow(['opening_balance' => 100])]])->assertUnprocessable();

    expect(implode(' ', $response->json('errors')))->toContain('account mapping')
        ->and(Contact::query()->count())->toBe(0);
});
