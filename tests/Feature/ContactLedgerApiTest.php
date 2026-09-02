<?php

use App\Models\Contact;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * @return array{company_id: int, branch_id: int}
 */
function seedLedgerScope(): array
{
    static $counter = 0;
    $counter++;

    $companyId = DB::table('companies')->insertGetId([
        'code' => 'LDG'.str_pad((string) $counter, 3, '0', STR_PAD_LEFT),
        'name' => 'Ledger Company '.$counter,
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $branchId = DB::table('branches')->insertGetId([
        'code' => 'LDB'.str_pad((string) $counter, 3, '0', STR_PAD_LEFT),
        'company_id' => $companyId,
        'name' => 'Ledger Branch '.$counter,
        'is_active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('financial_years')->insert([
        'company_id' => $companyId,
        'name' => 'FY 2026',
        'start_date' => '2026-07-01',
        'end_date' => '2027-07-01',
        'status' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'company_id' => $companyId,
        'branch_id' => $branchId,
    ];
}

/**
 * @param  array<string, mixed>  $attributes
 * @return array{contact: Contact, coa_id: int, scope: array{company_id: int, branch_id: int}}
 */
function createLinkedLedgerContact(string $userType, array $attributes = []): array
{
    $scope = seedLedgerScope();
    $isCustomer = $userType === 'customer';
    $code = $isCustomer ? '101-00010' : '311-00010';
    $nature = $isCustomer ? 'dr' : 'cr';

    $coaId = DB::table('chart_of_accounts')->insertGetId([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'code' => $code,
        'name' => $isCustomer ? 'Ledger Customer' : 'Ledger Supplier',
        'acc_type' => 't',
        'acc_nature' => $nature,
        'bs' => 1,
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $contact = Contact::query()->create(array_merge([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'business_name' => $isCustomer ? 'Ledger Customer Co' : 'Ledger Supplier Co',
        'first_name' => 'Pat',
        'mobile' => '03001234567',
        'address' => 'Test address',
        'code' => ($isCustomer ? 'CU-' : 'SU-').$coaId,
        'user_type' => $userType,
        'type' => 'local',
        'ntn_number' => '1234567',
        'active' => true,
        'link_account' => true,
        'supplier_gl_id' => $isCustomer ? null : $code,
        'customer_gl_id' => $isCustomer ? $code : null,
        'gl_id' => $code,
    ], $attributes));

    return [
        'contact' => $contact,
        'coa_id' => $coaId,
        'scope' => $scope,
    ];
}

function seedLedgerOpeningBalance(array $scope, int $coaId, float $amount, string $nature = 'cr'): void
{
    $financialYearId = DB::table('financial_years')
        ->where('company_id', $scope['company_id'])
        ->value('id');

    DB::table('account_balances')->insert([
        'company_id' => $scope['company_id'],
        'branch_id' => $scope['branch_id'],
        'financial_id' => $financialYearId,
        'coa_id' => $coaId,
        'opening_balance' => $amount,
        'acc_nature' => $nature,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('fetch ledger returns opening balance and purchase row for supplier', function () {
    $linked = createLinkedLedgerContact('supplier');
    seedLedgerOpeningBalance($linked['scope'], $linked['coa_id'], 10000);

    Transaction::factory()->create([
        'company_id' => $linked['scope']['company_id'],
        'branch_id' => $linked['scope']['branch_id'],
        'contact_id' => $linked['contact']->id,
        'type' => Transaction::TYPE_PURCHASE,
        'invoice_no' => 'PO-00001',
        'transaction_date' => '2026-08-23',
        'final_amount' => 23000,
        'payment_status' => 'due',
        'status' => 'approved',
    ]);

    Transaction::factory()->create([
        'company_id' => $linked['scope']['company_id'],
        'branch_id' => $linked['scope']['branch_id'],
        'contact_id' => $linked['contact']->id,
        'type' => Transaction::TYPE_RECEIVING_NOTE,
        'invoice_no' => 'GRN-00001',
        'transaction_date' => '2026-08-23',
        'final_amount' => 23000,
        'payment_status' => 'due',
        'status' => 'received',
    ]);

    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->getJson('/api/fetchledger?contact_id='.$linked['contact']->id.'&start_date=2026-07-01&end_date=2027-07-01');

    $response->assertOk()
        ->assertJsonPath('openingbalance', 10000)
        ->assertJsonPath('total_purchase', 23000)
        ->assertJsonCount(1, 'taccount')
        ->assertJsonPath('taccount.0.voucher_no', 'PO-00001')
        ->assertJsonPath('taccount.0.credit', 23000)
        ->assertJsonPath('taccount.0.acc_nature', 'cr');
});

test('fetch ledger returns opening balance and sell row for customer', function () {
    $linked = createLinkedLedgerContact('customer');
    seedLedgerOpeningBalance($linked['scope'], $linked['coa_id'], 2500, 'dr');

    Transaction::factory()->create([
        'company_id' => $linked['scope']['company_id'],
        'branch_id' => $linked['scope']['branch_id'],
        'contact_id' => $linked['contact']->id,
        'type' => Transaction::TYPE_SELL,
        'invoice_no' => 'INV-00001',
        'transaction_date' => '2026-08-15',
        'final_amount' => 5000,
        'payment_status' => 'due',
        'status' => 'approved',
    ]);

    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->getJson('/api/fetchledger?contact_id='.$linked['contact']->id.'&start_date=2026-07-01&end_date=2027-07-01');

    $response->assertOk()
        ->assertJsonPath('openingbalance', 2500)
        ->assertJsonPath('total_sell', 5000)
        ->assertJsonCount(1, 'taccount')
        ->assertJsonPath('taccount.0.voucher_no', 'INV-00001')
        ->assertJsonPath('taccount.0.debit', 5000)
        ->assertJsonPath('taccount.0.acc_nature', 'dr');
});

test('fetch ledger prefers approved journal lines over transactions', function () {
    $linked = createLinkedLedgerContact('supplier');
    seedLedgerOpeningBalance($linked['scope'], $linked['coa_id'], 10000);

    Transaction::factory()->create([
        'company_id' => $linked['scope']['company_id'],
        'branch_id' => $linked['scope']['branch_id'],
        'contact_id' => $linked['contact']->id,
        'type' => Transaction::TYPE_PURCHASE,
        'invoice_no' => 'PO-00009',
        'transaction_date' => '2026-08-23',
        'final_amount' => 23000,
        'status' => 'approved',
    ]);

    $voucherId = DB::table('t_accounts')->insertGetId([
        'company_id' => $linked['scope']['company_id'],
        'branch_id' => $linked['scope']['branch_id'],
        'account_code' => '311-00010',
        'voucher_no' => 'JV-00001',
        'ref_no' => 'PO-00009',
        'cheque_no' => '',
        'comments' => 'Purchase journal',
        'voucher_date' => '2026-08-23',
        'status' => 'approved',
        'type' => 'cash',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('t_account_details')->insert([
        't_account_id' => $voucherId,
        'branch_id' => $linked['scope']['branch_id'],
        'contact_id' => $linked['contact']->id,
        'account_code' => '311-00010',
        'description' => 'Purchase journal',
        'acc_nature' => 'cr',
        'credit' => 23000,
        'debit' => 0,
        'highlight' => 0,
        'amount' => 23000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->getJson('/api/fetchledger?contact_id='.$linked['contact']->id.'&start_date=2026-07-01&end_date=2027-07-01');

    $response->assertOk()
        ->assertJsonPath('openingbalance', 10000)
        ->assertJsonCount(1, 'taccount')
        ->assertJsonPath('taccount.0.voucher_no', 'JV-00001')
        ->assertJsonPath('taccount.0.credit', 23000);
});

test('fetch financial years returns the active year dates', function () {
    $scope = seedLedgerScope();
    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->getJson('/api/fetchfinancialyears?company_id='.$scope['company_id']);

    $response->assertOk();

    expect(substr((string) $response->json('0.start_date'), 0, 10))->toBe('2026-07-01')
        ->and(substr((string) $response->json('0.end_date'), 0, 10))->toBe('2027-07-01');
});

test('fetch ledger clamps requested dates to the active financial year', function () {
    $linked = createLinkedLedgerContact('supplier');
    seedLedgerOpeningBalance($linked['scope'], $linked['coa_id'], 10000);

    Transaction::factory()->create([
        'company_id' => $linked['scope']['company_id'],
        'branch_id' => $linked['scope']['branch_id'],
        'contact_id' => $linked['contact']->id,
        'type' => Transaction::TYPE_PURCHASE,
        'invoice_no' => 'PO-BEFORE-FY',
        'transaction_date' => '2026-06-15',
        'final_amount' => 4000,
        'status' => 'approved',
    ]);

    Transaction::factory()->create([
        'company_id' => $linked['scope']['company_id'],
        'branch_id' => $linked['scope']['branch_id'],
        'contact_id' => $linked['contact']->id,
        'type' => Transaction::TYPE_PURCHASE,
        'invoice_no' => 'PO-00001',
        'transaction_date' => '2026-08-23',
        'final_amount' => 23000,
        'status' => 'approved',
    ]);

    Sanctum::actingAs(User::query()->findOrFail(1));

    $response = $this->getJson('/api/fetchledger?contact_id='.$linked['contact']->id.'&start_date=2026-01-01&end_date=2028-12-31');

    $response->assertOk()
        ->assertJsonCount(1, 'taccount')
        ->assertJsonPath('taccount.0.voucher_no', 'PO-00001');
});
