<?php

use App\Models\Contact;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

test('chart of accounts balance matches supplier and customer ledger closing balances', function () {
    $supplier = createLinkedLedgerContact('supplier');
    seedLedgerOpeningBalance($supplier['scope'], $supplier['coa_id'], 10000);

    Transaction::factory()->create([
        'company_id' => $supplier['scope']['company_id'],
        'branch_id' => $supplier['scope']['branch_id'],
        'contact_id' => $supplier['contact']->id,
        'type' => Transaction::TYPE_PURCHASE,
        'invoice_no' => 'PO-00001',
        'transaction_date' => '2026-08-23',
        'final_amount' => 23000,
        'payment_status' => 'due',
        'status' => 'approved',
    ]);

    $customerCoaId = DB::table('chart_of_accounts')->insertGetId([
        'company_id' => $supplier['scope']['company_id'],
        'branch_id' => $supplier['scope']['branch_id'],
        'code' => '101-00010',
        'name' => 'Ledger Customer',
        'acc_type' => 't',
        'acc_nature' => 'dr',
        'bs' => 1,
        'active' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $customer = Contact::query()->create([
        'company_id' => $supplier['scope']['company_id'],
        'branch_id' => $supplier['scope']['branch_id'],
        'business_name' => 'Ledger Customer Co',
        'first_name' => 'Ada',
        'mobile' => '03007654321',
        'address' => 'Test address',
        'code' => 'CU-'.$customerCoaId,
        'user_type' => 'customer',
        'type' => 'local',
        'ntn_number' => '7654321',
        'active' => true,
        'link_account' => true,
        'customer_gl_id' => '101-00010',
        'gl_id' => '101-00010',
    ]);

    seedLedgerOpeningBalance($supplier['scope'], $customerCoaId, 2500, 'dr');

    Transaction::factory()->create([
        'company_id' => $supplier['scope']['company_id'],
        'branch_id' => $supplier['scope']['branch_id'],
        'contact_id' => $customer->id,
        'type' => Transaction::TYPE_SELL,
        'invoice_no' => 'INV-00001',
        'transaction_date' => '2026-08-15',
        'final_amount' => 5000,
        'payment_status' => 'due',
        'status' => 'approved',
    ]);

    Sanctum::actingAs(User::query()->findOrFail(1));

    $supplierLedger = $this->getJson('/api/fetchledger?contact_id='.$supplier['contact']->id.'&start_date=2026-07-01&end_date=2027-07-01')
        ->assertOk();

    $customerLedger = $this->getJson('/api/fetchledger?contact_id='.$customer->id.'&start_date=2026-07-01&end_date=2027-07-01')
        ->assertOk();

    $accounts = $this->getJson('/api/chart-of-accounts?'.http_build_query([
        'company_id' => $supplier['scope']['company_id'],
        'branch_id' => $supplier['scope']['branch_id'],
        'status' => 'all',
    ]))->assertOk()->json();

    $supplierNode = collect($accounts)->firstWhere('code', $supplier['contact']->supplier_gl_id);
    $customerNode = collect($accounts)->firstWhere('code', $customer->customer_gl_id);

    expect($supplierLedger->json('openingbalance'))->toBe(10000)
        ->and($supplierLedger->json('closingbalance'))->toBe(33000)
        ->and($customerLedger->json('openingbalance'))->toBe(2500)
        ->and($customerLedger->json('closingbalance'))->toBe(7500)
        ->and($supplierNode['opening_balance'])->toBe(33000)
        ->and($customerNode['opening_balance'])->toBe(7500);
});

test('chart of accounts uses journal activity instead of purchase documents when journals exist', function () {
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
        'credit' => 15000,
        'debit' => 0,
        'highlight' => 0,
        'amount' => 15000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Sanctum::actingAs(User::query()->findOrFail(1));

    $ledger = $this->getJson('/api/fetchledger?contact_id='.$linked['contact']->id.'&start_date=2026-07-01&end_date=2027-07-01')
        ->assertOk();

    $accounts = $this->getJson('/api/chart-of-accounts?'.http_build_query([
        'company_id' => $linked['scope']['company_id'],
        'branch_id' => $linked['scope']['branch_id'],
        'status' => 'all',
    ]))->assertOk()->json();

    $node = collect($accounts)->firstWhere('code', '311-00010');

    expect($ledger->json('closingbalance'))->toBe(25000)
        ->and($node['opening_balance'])->toBe(25000);
});
