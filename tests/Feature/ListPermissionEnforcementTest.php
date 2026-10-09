<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Confirmed in the 2026-09-22 audit and fixed here: 52 of the 65 list-page GET @index endpoints had no read
 * permission gate at all — any authenticated user, holding no menu permission whatsoever, could call the URL
 * directly and read the full listing. Every module's own menu tree already carries a permission for its list
 * page (the same convention already used by write actions like store/update); the fix adds
 * `$this->authorizeMenuPermission()` (or the matching company-settings/family-approval gate) as the first line
 * of each index(). This test also re-checks a handful of the 13 index() endpoints that were already gated, to
 * catch any future regression in those too.
 */
function lpeCompany(): int
{
    return DB::table('companies')->insertGetId(['code' => 'LPE-'.uniqid(), 'name' => 'LPE Co', 'address' => 'x', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * @param  list<string>  $paths
 */
function lpeUser(int $companyId, array $paths = []): User
{
    $role = Role::query()->create(['name' => 'lpe'.uniqid(), 'company_id' => $companyId, 'is_active' => true]);

    foreach ($paths as $path) {
        grantMenuPermission($role->id, $path, ltrim(str_replace([':', '/'], ['', '-'], $path), '-').uniqid());
    }

    return createStaffUserForRole($role, ['company_id' => $companyId]);
}

function lpeAssertNotForbidden(TestResponse $response): void
{
    expect($response->status())->not->toBe(403);
}

$plainPathCases = [
    'BankIssuerController' => ['bank-issuers', '/bankissuer'],
    'BankController' => ['banks', '/bank'],
    'BranchController' => ['branches', '/branch'],
    'BrandController' => ['brands', '/brand'],
    'CashCollectionController' => ['cash-collections', '/cashcollection'],
    'CategoryController' => ['categories', '/category'],
    'ChartOfAccountController' => ['chart-of-accounts', '/chart-of-account'],
    'CityController' => ['cities', '/city'],
    'CommissionAgentController' => ['commission-agents', '/commissionagent'],
    'ConsumerController' => ['consumers', '/consumer'],
    'CountryController' => ['countries', '/country'],
    'CurrencyController' => ['currencies', '/currency'],
    'CustomerGroupController' => ['customer-groups', '/customer-group'],
    'CustomerController' => ['customers', '/customer'],
    'DepartmentController' => ['departments', '/department'],
    'DepositController' => ['deposits', '/deposit'],
    'DiscountController' => ['discounts', '/discount'],
    'ExpenseController' => ['expenses', '/expense'],
    'FundTransferController' => ['fundtransfers', '/fundtransfer'],
    'GiftCardController' => ['gift-cards', '/giftcard'],
    'IssueNoteController' => ['issue-notes', '/issuenote'],
    'ItemTypeController' => ['item-types', '/itemtype'],
    'JournalEntryController' => ['journal-entries', '/journalentry'],
    'LoyaltyController' => ['loyalty', '/loyalty'],
    'PaymentController' => ['payments', '/acpayment'],
    'PriceListController' => ['pricelists', '/pricelist'],
    'ProductController' => ['products', '/product'],
    'PurchaseApprovalController' => ['purchase-approvals', '/purchase/approval'],
    'PurchasePaymentController' => ['purchase-payments', '/purchase/payment'],
    'PurchaseReturnController' => ['purchase-returns', '/purchase/return'],
    'PurchaseController' => ['purchases', '/purchase'],
    'ReceivingNoteController' => ['receiving-notes', '/receivingnote'],
    'RoleController' => ['roles', '/role'],
    'SellApprovalController' => ['sell-approvals', '/sell/approval'],
    'SellPaymentController' => ['sell-payments', '/sell/payment'],
    'SellReturnController' => ['sell-returns', '/sell/return'],
    'SellController' => ['sells', '/sell'],
    'StateController' => ['states', '/state'],
    'StockTakeController' => ['stock-takes', '/stocktake'],
    'StockAdjustmentController' => ['stockadjustments', '/stockadjustment'],
    'StockTransferController' => ['stocktransfers', '/stocktransfer'],
    'SupplierController' => ['suppliers', '/supplier'],
    'TimezoneController' => ['timezones', '/timezone'],
    'TransporterController' => ['transporters', '/transporter'],
    'UnitController' => ['units', '/unit'],
    'UserController' => ['users', '/user'],
    'VariationController' => ['variations', '/variation'],
    'WarehouseController' => ['warehouses', '/warehouse'],
    'WarrantyController' => ['warranties', '/warranty'],
];

test('list index() requires the module\'s own menu permission, and holding it (or superadmin) is enough', function (string $uri, string $permissionPath) {
    $co = lpeCompany();

    // no permission at all: blocked
    Sanctum::actingAs(lpeUser($co));
    $this->getJson('/api/'.$uri)->assertForbidden();

    // holding exactly this module's permission: not blocked
    Sanctum::actingAs(lpeUser($co, [$permissionPath]));
    lpeAssertNotForbidden($this->getJson('/api/'.$uri));

    // superadmin: never blocked
    Sanctum::actingAs(User::query()->findOrFail(1));
    lpeAssertNotForbidden($this->getJson('/api/'.$uri));
})->with($plainPathCases);

test('the voucher-approval family list still requires its own approve permission', function () {
    $co = lpeCompany();

    Sanctum::actingAs(lpeUser($co));
    $this->getJson('/api/payment-approvals')->assertForbidden();

    Sanctum::actingAs(lpeUser($co, ['/acpayment/:id/approve']));
    lpeAssertNotForbidden($this->getJson('/api/payment-approvals'));

    Sanctum::actingAs(User::query()->findOrFail(1));
    lpeAssertNotForbidden($this->getJson('/api/payment-approvals'));
});

test('financial-year and tax list still require the company-settings gate', function (string $uri) {
    $co = lpeCompany();

    Sanctum::actingAs(lpeUser($co));
    $this->getJson('/api/'.$uri)->assertForbidden();

    $companyAdminRole = Role::query()->create(['name' => 'companyadmin', 'company_id' => $co, 'is_active' => true]);
    Sanctum::actingAs(createStaffUserForRole($companyAdminRole, ['company_id' => $co]));
    lpeAssertNotForbidden($this->getJson('/api/'.$uri));

    Sanctum::actingAs(User::query()->findOrFail(1));
    lpeAssertNotForbidden($this->getJson('/api/'.$uri));
})->with(['financialyears' => ['financialyears'], 'taxes' => ['taxes']]);

// spot-checks a handful of the endpoints that were already gated before this pass, to catch any future regression
test('previously-gated list endpoints still block a permission-less user', function (string $uri) {
    $co = lpeCompany();
    Sanctum::actingAs(lpeUser($co));

    $this->getJson('/api/'.$uri)->assertForbidden();
})->with([
    'api-keys' => ['api-keys'],
    'backups' => ['backups'],
    'companies' => ['companies'],
    'currency-rates' => ['currency-rates'],
    'lowstock' => ['lowstock'],
    'menus' => ['menus'],
    'portal-users' => ['portal-users'],
]);

test('previously-gated list endpoints still allow a superadmin', function (string $uri) {
    Sanctum::actingAs(User::query()->findOrFail(1));

    lpeAssertNotForbidden($this->getJson('/api/'.$uri));
})->with([
    'api-keys' => ['api-keys'],
    'backups' => ['backups'],
    'companies' => ['companies'],
    'currency-rates' => ['currency-rates'],
    'lowstock' => ['lowstock'],
    'menus' => ['menus'],
    'portal-users' => ['portal-users'],
]);
