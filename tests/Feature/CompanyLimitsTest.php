<?php

use App\Models\Company;
use App\Models\Product;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function limCompany(array $overrides = []): Company
{
    static $counter = 0;
    $counter++;

    return Company::query()->create(array_merge([
        'code' => 'CO-6'.str_pad((string) $counter, 4, '0', STR_PAD_LEFT),
        'name' => 'Limits Co '.$counter,
        'is_active' => true,
        'max_users' => 1,
        'max_branches' => 5,
        'max_warehouses' => 1,
        'max_products' => 1,
        'max_invoices_per_month' => 1,
        'max_storage_mb' => 500,
    ], $overrides));
}

test('max_users is now enforced when creating a user, the real gap this build closes', function () {
    $company = limCompany();
    $role = Role::query()->create(['name' => 'staff', 'company_id' => $company->id, 'is_active' => true]);
    grantMenuPermission((int) $role->id, '/user/add');

    // The company's own first user fills its one allowed slot.
    $owner = createStaffUserForRole($role, ['company_id' => $company->id]);
    Sanctum::actingAs($owner);

    $this->postJson('/api/users', [
        'role_id' => $role->id,
        'department_id' => 1,
        'first_name' => 'Second', 'last_name' => 'User',
        'email' => 'second@example.com', 'username' => 'second_user',
        'password' => 'Str0ng!Pass', 'password_confirmation' => 'Str0ng!Pass',
        'branch_id' => null,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['company_id']);

    expect(User::query()->where('company_id', $company->id)->count())->toBe(1);
});

test('a warehouse cannot be added beyond the company limit', function () {
    $company = limCompany(['max_warehouses' => 1]);
    Warehouse::query()->create(['company_id' => $company->id, 'name' => 'Main', 'is_active' => true]);

    expect(fn () => Warehouse::createWarehouse((object) ['company_id' => $company->id, 'name' => 'Second']))
        ->toThrow(ValidationException::class);
});

test('a product cannot be added beyond the company limit', function () {
    $company = limCompany(['max_products' => 1]);
    Product::query()->create([
        'company_id' => $company->id, 'name' => 'Product One', 'type' => 'single', 'sku' => 'SKU1', 'is_active' => true,
    ]);

    expect($company->canAddProduct())->toBeFalse();
});

test('a sell invoice counts toward the monthly invoice limit but a draft does not', function () {
    $company = limCompany(['max_invoices_per_month' => 1]);

    Transaction::query()->create([
        'company_id' => $company->id,
        'type' => Transaction::TYPE_SELL,
        'status' => 'draft',
        'invoice_no' => 'DRAFT-1',
        'final_amount' => 100,
    ]);
    expect($company->invoicesThisMonth())->toBe(0);

    Transaction::query()->create([
        'company_id' => $company->id,
        'type' => Transaction::TYPE_SELL,
        'status' => 'final',
        'invoice_no' => 'INV-1',
        'final_amount' => 100,
    ]);
    expect($company->invoicesThisMonth())->toBe(1)
        ->and($company->canAddInvoice())->toBeFalse();
});

test('storage usage is computed from actual attachment file sizes, not a counter', function () {
    $company = limCompany(['max_storage_mb' => 500]);

    expect($company->storageUsedBytes())->toBe(0);
});
