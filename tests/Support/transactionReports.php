<?php

use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * Helpers for the transaction list report tests (purchase, return, payment, stock adjustment and
 * expense reports). Documents are inserted straight into the tables so every amount, status and date
 * is known and no form or journal side effect gets in the way.
 */

/**
 * A company with one branch, one customer and one supplier.
 *
 * @return array{company_id: int, branch_id: int, customer_id: int, supplier_id: int}
 */
function trpScope(string $suffix = '1'): array
{
    $companyId = DB::table('companies')->insertGetId([
        'code' => 'TRP'.$suffix,
        'name' => 'Report Company '.$suffix,
        'address' => '1 Report Street',
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $branchId = trpBranch($companyId, 'Report Branch '.$suffix);

    $customer = trpContact(['company_id' => $companyId, 'branch_id' => $branchId], 'Acme Retail '.$suffix, 'customer', 'CU-'.$suffix, 'Sara', 'Khan');
    $supplier = trpContact(['company_id' => $companyId, 'branch_id' => $branchId], 'Farm Supplies '.$suffix, 'supplier', 'SU-'.$suffix, 'Omar', 'Ali');

    return [
        'company_id' => $companyId,
        'branch_id' => $branchId,
        'customer_id' => $customer,
        'supplier_id' => $supplier,
    ];
}

/**
 * @param  array{company_id: int, branch_id: int}  $scope
 */
function trpContact(array $scope, ?string $businessName, string $userType, string $code, ?string $firstName = null, ?string $lastName = null): int
{
    return Contact::query()->create([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'business_name' => $businessName ?? '',
        'first_name' => $firstName ?? '',
        'last_name' => $lastName ?? '',
        'mobile' => '03007654321',
        'address' => 'Contact address',
        'code' => $code,
        'user_type' => $userType,
        'type' => 'local',
        'ntn_number' => '1234567',
        'pay_term' => 10,
        'pay_type' => 'day',
        'credit_limit' => 0,
        'active' => true,
    ])->id;
}

function trpBranch(int $companyId, string $name): int
{
    return DB::table('branches')->insertGetId([
        'code' => 'B'.strtoupper(substr(md5($name.$companyId), 0, 6)),
        'company_id' => $companyId,
        'name' => $name,
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Inserts a transaction row and returns its id. The contact defaults to the customer for sells and
 * sell returns and to the supplier for everything else.
 *
 * @param  array{company_id: int, branch_id: int, customer_id: int, supplier_id: int}  $scope
 * @param  array<string, mixed>  $attributes
 */
function trpDoc(array $scope, string $type, array $attributes = []): int
{
    static $sequence = 0;
    $sequence++;

    $isSale = in_array($type, ['sell', 'sellreturn'], true);

    return DB::table('transactions')->insertGetId(array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'contact_id' => $isSale ? $scope['customer_id'] : $scope['supplier_id'],
        'invoice_no' => strtoupper(substr($type, 0, 3)).'-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
        'type' => $type,
        'status' => 'final',
        'payment_status' => 'due',
        'transaction_date' => '2026-09-10 10:00:00',
        'total_before_tax' => 100,
        'tax_amount' => 10,
        'discount_amount' => 5,
        'shipping_charges' => 2,
        'final_amount' => 107,
        'total_item' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));
}

/**
 * @param  array{company_id: int, branch_id: int, customer_id: int, supplier_id: int}  $scope
 * @param  array<string, mixed>  $attributes
 */
function trpPayment(array $scope, int $transactionId, float $amount, string $paidOn, string $method = 'cash', array $attributes = []): int
{
    static $sequence = 0;
    $sequence++;

    $contactId = DB::table('transactions')->where('id', $transactionId)->value('contact_id');

    return DB::table('payments')->insertGetId(array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'transaction_id' => $transactionId,
        'contact_id' => $contactId,
        'is_return' => 0,
        'amount' => $amount,
        'method' => $method,
        'paid_on' => $paidOn,
        'payment_ref_no' => 'PAY-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT),
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));
}

function trpActAsSuperadmin(): void
{
    Sanctum::actingAs(User::query()->findOrFail(1));
}

/**
 * The ids of the rows on the returned page, in order.
 *
 * @return list<int>
 */
function trpIds(TestResponse $response): array
{
    return collect($response->json('data.data'))->pluck('id')->map(fn ($id): int => (int) $id)->all();
}
